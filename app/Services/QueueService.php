<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Database\DbException;
use QueuePro\Domain\TicketStatus as S;
use QueuePro\Support\Actor;
use QueuePro\Support\Clock;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/**
 * The queue engine: joining, numbering, transitions, live position, end-of-day rollover.
 *
 * Concurrency model
 * -----------------
 * Every write for a provider starts by locking that provider's credit_accounts row
 * (CreditService::lockAccount). That row acts as a per-provider mutex, so bookings,
 * calls and completions for the same provider are serialised while different
 * providers never block each other. Daily numbers come from queue_counters inside
 * the same transaction. On top of that, unique keys make duplicates impossible even
 * if application code were wrong:
 *   - (provider_id, queue_date, ticket_number)  → no duplicate numbers
 *   - one active ticket per phone (partial unique index / generated column)
 *   - credit_ledger (ticket_id, type)           → no double reserve/consume/release
 */
final class QueueService
{
    private Connection $db;
    private Clock $clock;
    private CreditService $credits;
    private Settings $settings;
    private RateLimiter $limiter;
    private NotificationService $notify;
    private OtpService $otp;

    public function __construct(Connection $db, Clock $clock, CreditService $credits, Settings $settings, RateLimiter $limiter, NotificationService $notify, OtpService $otp)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->credits = $credits;
        $this->settings = $settings;
        $this->limiter = $limiter;
        $this->notify = $notify;
        $this->otp = $otp;
    }

    /** OTP is enforced only when switched on AND a real SMS gateway is configured. */
    public function otpRequired(): bool
    {
        return $this->settings->bool('require_otp') && $this->otp->available();
    }

    // ====================================================================== booking

    /**
     * Customer joins the queue (no account). Returns the ticket view + the secret
     * link token (shown once; only its hash is stored).
     *
     * @param array{phone:string,name?:string,service_id?:int,otp_code?:string,lang?:string} $input
     */
    public function join(int $providerId, array $input, Actor $actor, ?string $idempotencyKey = null): array
    {
        $provider = $this->provider($providerId);
        $this->rollover($provider);

        $phone = Security::normalizePhone((string) ($input['phone'] ?? ''), $this->countryPhoneCode($provider['country_code']));
        if (!$phone) {
            throw new QueueException('invalid_phone', 422);
        }
        $name = Security::cleanName($input['name'] ?? null);
        $lang = ($input['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $serviceId = !empty($input['service_id']) ? (int) $input['service_id'] : null;
        if ($serviceId) {
            $this->assertService($providerId, $serviceId);
        }

        // Idempotent replay: same key → same ticket, no second booking.
        $idemHash = null;
        if ($idempotencyKey !== null && strlen($idempotencyKey) >= 16 && strlen($idempotencyKey) <= 100) {
            $idemHash = Security::hashKey('join:' . $providerId . ':' . $idempotencyKey);
            $replay = $this->idempotentReplay($idemHash, $idempotencyKey);
            if ($replay) {
                return $replay;
            }
        }

        // Anti-abuse (outside the transaction so failed attempts still count).
        if ($actor->ipHash) {
            $this->limiter->hit('ip:' . $actor->ipHash, $this->settings->int('max_joins_per_ip_window'), $this->settings->int('ip_window_minutes') * 60);
        }
        $this->limiter->hit('phone:' . $phone, $this->settings->int('max_joins_per_phone_day'), 86400);

        if ($this->otpRequired()) {
            $this->otp->verify($phone, 'join', $input['otp_code'] ?? null);
        }

        try {
            return $this->db->transaction(function () use ($providerId, $phone, $name, $serviceId, $actor, $idemHash, $idempotencyKey, $lang) {
                if ($idemHash) {
                    $this->db->insert($this->db->table('idempotency_keys'), [
                        'key_hash' => $idemHash, 'scope' => 'join', 'response' => null, 'created_at' => $this->clock->utcString(),
                    ]);
                }
                $acc = $this->credits->lockAccount($providerId);
                $provider = $this->provider($providerId); // fresh read under lock

                if ($provider['status'] !== 'active') {
                    throw new QueueException('provider_unavailable', 409);
                }
                if (!(int) $provider['queue_open']) {
                    throw new QueueException('queue_closed', 409);
                }
                if (CreditService::available($acc) < 1) {
                    throw new QueueException('booking_paused', 409);
                }
                $date = $this->clock->localDate($provider['timezone']);
                if ((int) $provider['max_waiting'] > 0 && $this->countActive($providerId, $date) >= (int) $provider['max_waiting']) {
                    throw new QueueException('queue_full', 409);
                }
                if ($this->db->getVar(
                    "SELECT id FROM {$this->db->table('tickets')} WHERE provider_id = %d AND phone = %s AND status IN ('waiting','called','in_service')",
                    [$providerId, $phone]
                )) {
                    throw new QueueException('already_in_queue', 409);
                }

                $token = $idempotencyKey && $idemHash ? $this->tokenFromIdempotency($idempotencyKey, $providerId) : Security::newToken();
                $ticket = $this->createTicket($provider, $date, $acc, [
                    'source' => 'online', 'phone' => $phone, 'customer_name' => $name,
                    'service_id' => $serviceId, 'ip_hash' => $actor->ipHash, 'lang' => $lang,
                ], $token, $actor);

                if ($idemHash) {
                    $this->db->update($this->db->table('idempotency_keys'), ['response' => (string) $ticket['id']], ['key_hash' => $idemHash]);
                }
                return ['token' => $token, 'ticket' => $this->publicView($ticket, $provider)];
            });
        } catch (DbException $e) {
            if ($e->isDuplicate() && $idemHash
                && $this->db->getVar("SELECT key_hash FROM {$this->db->table('idempotency_keys')} WHERE key_hash = %s", [$idemHash])) {
                // A concurrent request with the same key won the race; return its result.
                $replay = $this->idempotentReplay($idemHash, (string) $idempotencyKey);
                if ($replay) {
                    return $replay;
                }
                throw new QueueException('duplicate_request', 409);
            }
            throw $e;
        }
    }

    /** Provider adds a customer who is physically present (no phone needed). */
    public function addWalkIn(int $providerId, Actor $actor, ?string $name = null, ?int $serviceId = null): array
    {
        $this->assertCanManage($actor, $providerId);
        $provider = $this->provider($providerId);
        $this->rollover($provider);
        if ($serviceId) {
            $this->assertService($providerId, $serviceId);
        }
        return $this->db->transaction(function () use ($providerId, $actor, $name, $serviceId) {
            $acc = $this->credits->lockAccount($providerId);
            $provider = $this->provider($providerId);
            if ($provider['status'] !== 'active') {
                throw new QueueException('provider_unavailable', 409);
            }
            if (CreditService::available($acc) < 1) {
                throw new QueueException('insufficient_credit', 409);
            }
            $date = $this->clock->localDate($provider['timezone']);
            $ticket = $this->createTicket($provider, $date, $acc, [
                'source' => 'walkin', 'phone' => null,
                'customer_name' => Security::cleanName($name), 'service_id' => $serviceId, 'ip_hash' => null,
            ], Security::newToken(), $actor);
            return $this->providerView($ticket, $provider);
        });
    }

    /** Inside a transaction with the account already locked. */
    private function createTicket(array $provider, string $date, array &$acc, array $fields, string $token, Actor $actor): array
    {
        $providerId = (int) $provider['id'];
        $counters = $this->db->table('queue_counters');
        // Atomic: creates today's counter at 1 or increments it, holding the row lock.
        $this->db->query(
            "INSERT INTO $counters (provider_id, queue_date, last_number, last_sort) VALUES (%d, %s, 1, 1)"
            . $this->db->onConflict(['provider_id', 'queue_date'], 'last_number = last_number + 1, last_sort = last_sort + 1'),
            [$providerId, $date]
        );
        $c = $this->db->getRow("SELECT last_number, last_sort FROM $counters WHERE provider_id = %d AND queue_date = %s" . $this->db->forUpdate(), [$providerId, $date]);
        $now = $this->clock->utcString();

        $ticketId = 0;
        for ($attempt = 0; $attempt < 3 && !$ticketId; $attempt++) {
            try {
                $ticketId = $this->db->insert($this->db->table('tickets'), $fields + [
                    'provider_id'   => $providerId,
                    'queue_date'    => $date,
                    'ticket_number' => (int) $c['last_number'],
                    'sort_key'      => (int) $c['last_sort'],
                    'public_ref'    => Security::newPublicRef(),
                    'token_hash'    => Security::hashToken($token),
                    'status'        => S::WAITING,
                    'credit_state'  => 'reserved',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            } catch (DbException $e) {
                if (!$e->isDuplicate()) {
                    throw $e;
                }
                if (!empty($fields['phone']) && $this->db->getVar(
                    "SELECT id FROM {$this->db->table('tickets')} WHERE provider_id = %d AND phone = %s AND status IN ('waiting','called','in_service')",
                    [$providerId, $fields['phone']]
                )) {
                    throw new QueueException('already_in_queue', 409);
                }
                if ($this->db->getVar(
                    "SELECT id FROM {$this->db->table('tickets')} WHERE provider_id = %d AND queue_date = %s AND ticket_number = %d",
                    [$providerId, $date, (int) $c['last_number']]
                )) {
                    throw $e; // a duplicate day number would mean a broken lock — never swallow it
                }
                // otherwise: random public reference collided → retry with a new one
            }
        }
        if (!$ticketId) {
            throw new \RuntimeException('Could not allocate ticket reference');
        }
        $this->credits->reserve($providerId, $ticketId, $acc);
        $this->event($ticketId, null, S::WAITING, $fields['source'] === 'walkin' ? 'walkin' : 'join', $actor);

        // Already close to the front when joining → no separate "almost your turn" SMS later.
        $near = $this->settings->int('notify_near_ahead');
        if ($near > 0 && $this->countAhead($providerId, $date, (int) $c['last_sort']) <= $near) {
            $this->db->update($this->db->table('tickets'), ['notified_near' => 1], ['id' => $ticketId]);
        }
        $ticket = $this->ticketById($ticketId);
        $this->notify->onJoined($ticket, $provider, $token);
        return $ticket;
    }

    // ====================================================================== transitions

    /**
     * Provider action on a ticket: call | start | complete | delay | no_show | cancel.
     * The ticket is always looked up by (id AND provider_id) → no IDOR.
     */
    public function transition(int $providerId, int $ticketId, string $action, Actor $actor): array
    {
        $this->assertCanManage($actor, $providerId);
        if (!isset(S::TRANSITIONS[$action])) {
            throw new QueueException('invalid_input', 422);
        }
        return $this->db->transaction(function () use ($providerId, $ticketId, $action, $actor) {
            $acc = $this->credits->lockAccount($providerId);
            $ticket = $this->db->getRow(
                "SELECT * FROM {$this->db->table('tickets')} WHERE id = %d AND provider_id = %d" . $this->db->forUpdate(),
                [$ticketId, $providerId]
            );
            if (!$ticket) {
                throw new QueueException('ticket_not_found', 404);
            }
            $this->applyTransition($ticket, $action, $acc, $actor);
            $provider = $this->provider($providerId);
            return $this->providerView($this->ticketById($ticketId), $provider);
        });
    }

    /** Call the next waiting ticket (lowest sort_key). Returns null when nobody is waiting. */
    public function callNext(int $providerId, Actor $actor): ?array
    {
        $this->assertCanManage($actor, $providerId);
        $provider = $this->provider($providerId);
        $this->rollover($provider);
        return $this->db->transaction(function () use ($providerId, $actor, $provider) {
            $acc = $this->credits->lockAccount($providerId);
            $date = $this->clock->localDate($provider['timezone']);
            $ticket = $this->db->getRow(
                "SELECT * FROM {$this->db->table('tickets')}
                 WHERE provider_id = %d AND queue_date = %s AND status = %s
                 ORDER BY sort_key ASC LIMIT 1" . $this->db->forUpdate(),
                [$providerId, $date, S::WAITING]
            );
            if (!$ticket) {
                return null;
            }
            $this->applyTransition($ticket, 'call', $acc, $actor);
            return $this->providerView($this->ticketById((int) $ticket['id']), $provider);
        });
    }

    /** Customer cancels with the secret link token. */
    public function cancelByToken(string $token, Actor $actor): array
    {
        $hash = Security::hashToken($token);
        $found = $this->db->getRow("SELECT id, provider_id FROM {$this->db->table('tickets')} WHERE token_hash = %s", [$hash]);
        if (!$found) {
            throw new QueueException('ticket_not_found', 404);
        }
        $providerId = (int) $found['provider_id'];
        return $this->db->transaction(function () use ($providerId, $hash, $actor) {
            $acc = $this->credits->lockAccount($providerId);
            $ticket = $this->db->getRow("SELECT * FROM {$this->db->table('tickets')} WHERE token_hash = %s" . $this->db->forUpdate(), [$hash]);
            if (!in_array($ticket['status'], S::CUSTOMER_CANCELLABLE, true)) {
                throw new QueueException('invalid_transition', 409);
            }
            $this->applyTransition($ticket, 'cancel', $acc, $actor);
            return $this->publicView($this->ticketById((int) $ticket['id']), $this->provider($providerId));
        });
    }

    /** Core state machine + credit side effects. Caller holds account + ticket locks. */
    private function applyTransition(array $ticket, string $action, array &$acc, Actor $actor): void
    {
        [$from, $to] = S::TRANSITIONS[$action];
        if (!in_array($ticket['status'], $from, true)) {
            throw new QueueException('invalid_transition', 409, ['status' => $ticket['status']]);
        }
        $id = (int) $ticket['id'];
        $providerId = (int) $ticket['provider_id'];
        $now = $this->clock->utcString();
        $set = ['status' => $to, 'updated_at' => $now];

        switch ($action) {
            case 'call':
                $set['called_at'] = $now;
                break;
            case 'start':
                $set['service_started_at'] = $now;
                if (!$ticket['called_at']) {
                    $set['called_at'] = $now;
                }
                break;
            case 'delay':
                if ((int) $ticket['delayed_count'] >= 1) {
                    throw new QueueException('already_delayed', 409);
                }
                // Move to the end of today's queue; keep the visible number.
                $counters = $this->db->table('queue_counters');
                $this->db->query(
                    "UPDATE $counters SET last_sort = last_sort + 1 WHERE provider_id = %d AND queue_date = %s",
                    [$providerId, $ticket['queue_date']]
                );
                $set['sort_key'] = (int) $this->db->getVar(
                    "SELECT last_sort FROM $counters WHERE provider_id = %d AND queue_date = %s",
                    [$providerId, $ticket['queue_date']]
                );
                $set['delayed_count'] = (int) $ticket['delayed_count'] + 1;
                $set['called_at'] = null;
                break;
            case 'complete':
            case 'no_show':
            case 'cancel':
                $set['closed_at'] = $now;
                $set['closed_reason'] = $action;
                $consume = $action === 'complete'
                    || ($action === 'no_show' && $this->settings->bool('consume_on_no_show'))
                    || ($action === 'cancel' && $actor->type !== Actor::CUSTOMER && $this->settings->bool('consume_on_cancel'));
                if ($ticket['credit_state'] === 'reserved') {
                    if ($consume) {
                        $this->credits->consume($providerId, $id, $acc, $actor);
                        $set['credit_state'] = 'consumed';
                    } else {
                        $this->credits->release($providerId, $id, $acc, $actor, $action);
                        $set['credit_state'] = 'released';
                    }
                }
                break;
        }

        $this->db->update($this->db->table('tickets'), $set, ['id' => $id]);
        $this->event($id, $ticket['status'], $to, $action, $actor);

        // Notifications are queued in the same transaction (sent after the response).
        $provider = $this->provider($providerId);
        if ($action === 'call') {
            $this->notify->onCalled($this->ticketById($id), $provider);
        }
        $this->notify->checkNear($provider, $ticket['queue_date']);
    }

    private function countAhead(int $providerId, string $date, int $sortKey): int
    {
        return (int) $this->db->getVar(
            "SELECT COUNT(*) FROM {$this->db->table('tickets')}
             WHERE provider_id = %d AND queue_date = %s AND status = 'waiting' AND sort_key < %d",
            [$providerId, $date, $sortKey]
        );
    }

    /**
     * Lost the link? Prove the phone with an SMS code and get a fresh link to the
     * active ticket. The old link stops working (token rotated).
     */
    public function recoverTicket(int $providerId, string $rawPhone, ?string $code, Actor $actor): array
    {
        $provider = $this->provider($providerId);
        if (!$this->otp->available()) {
            throw new QueueException('otp_unavailable', 409);
        }
        $phone = Security::normalizePhone($rawPhone, $this->countryPhoneCode($provider['country_code']));
        if (!$phone) {
            throw new QueueException('invalid_phone', 422);
        }
        $this->otp->verify($phone, 'recover', $code);
        return $this->db->transaction(function () use ($providerId, $phone, $provider) {
            $ticket = $this->db->getRow(
                "SELECT * FROM {$this->db->table('tickets')} WHERE provider_id = %d AND phone = %s AND status IN ('waiting','called','in_service')" . $this->db->forUpdate(),
                [$providerId, $phone]
            );
            if (!$ticket) {
                throw new QueueException('no_active_ticket', 404);
            }
            $token = Security::newToken();
            $this->db->update($this->db->table('tickets'), ['token_hash' => Security::hashToken($token), 'updated_at' => $this->clock->utcString()], ['id' => (int) $ticket['id']]);
            $this->event((int) $ticket['id'], $ticket['status'], $ticket['status'], 'recover', Actor::customer());
            return ['token' => $token, 'ticket' => $this->publicView($this->ticketById((int) $ticket['id']), $provider)];
        });
    }

    /** Send an OTP for joining/recovering after validating the phone for this provider's country. */
    public function requestOtp(int $providerId, string $rawPhone, string $purpose, string $lang, Actor $actor): array
    {
        $provider = $this->provider($providerId);
        $phone = Security::normalizePhone($rawPhone, $this->countryPhoneCode($provider['country_code']));
        if (!$phone) {
            throw new QueueException('invalid_phone', 422);
        }
        if ($purpose === 'recover' && !$this->db->getVar(
            "SELECT id FROM {$this->db->table('tickets')} WHERE provider_id = %d AND phone = %s AND status IN ('waiting','called','in_service')",
            [$providerId, $phone]
        )) {
            throw new QueueException('no_active_ticket', 404);
        }
        return $this->otp->request($phone, $purpose, $lang === 'en' ? 'en' : 'ar', $actor->ipHash);
    }

    // ====================================================================== end of day (lazy, no cron)

    /**
     * Closes yesterday's (or older) active tickets for a provider and releases their
     * reserved credit. Runs at most once per local day per provider, triggered by
     * the first request that touches that provider after midnight (provider TZ).
     * A WP-Cron job calls it for all providers as a backup.
     */
    public function rollover(array $provider): int
    {
        $today = $this->clock->localDate($provider['timezone']);
        if ($provider['last_rollover_date'] === $today) {
            return 0;
        }
        $providerId = (int) $provider['id'];
        return $this->db->transaction(function () use ($providerId, $today) {
            $acc = $this->credits->lockAccount($providerId);
            $stale = $this->db->getResults(
                "SELECT * FROM {$this->db->table('tickets')}
                 WHERE provider_id = %d AND status IN ('waiting','called','in_service') AND queue_date < %s" . $this->db->forUpdate(),
                [$providerId, $today]
            );
            $system = Actor::system();
            $now = $this->clock->utcString();
            foreach ($stale as $t) {
                $set = ['status' => S::EXPIRED, 'closed_at' => $now, 'closed_reason' => 'end_of_day', 'updated_at' => $now];
                if ($t['credit_state'] === 'reserved') {
                    $this->credits->release($providerId, (int) $t['id'], $acc, $system, 'end_of_day');
                    $set['credit_state'] = 'released';
                }
                $this->db->update($this->db->table('tickets'), $set, ['id' => (int) $t['id']]);
                $this->event((int) $t['id'], $t['status'], S::EXPIRED, 'end_of_day', $system);
            }
            $this->db->update($this->db->table('providers'), ['last_rollover_date' => $today], ['id' => $providerId]);
            return count($stale);
        });
    }

    public function rolloverAll(): int
    {
        $n = 0;
        foreach ($this->db->getResults("SELECT * FROM {$this->db->table('providers')} WHERE status <> 'blocked'") as $p) {
            $n += $this->rollover($p);
        }
        return $n;
    }

    // ====================================================================== queue control

    public function setQueueOpen(int $providerId, bool $open, Actor $actor): void
    {
        $this->assertCanManage($actor, $providerId);
        $this->db->update($this->db->table('providers'), [
            'queue_open' => $open ? 1 : 0, 'updated_at' => $this->clock->utcString(),
        ], ['id' => $providerId]);
    }

    // ====================================================================== read models

    /** Public live status for the provider page (cheap; cached by the REST layer). */
    public function liveStatus(int $providerId): array
    {
        $provider = $this->provider($providerId);
        $this->rollover($provider);
        $provider = $this->provider($providerId);
        $date = $this->clock->localDate($provider['timezone']);
        $t = $this->db->table('tickets');

        $waiting = (int) $this->db->getVar(
            "SELECT COUNT(*) FROM $t WHERE provider_id = %d AND queue_date = %s AND status = 'waiting'",
            [$providerId, $date]
        );
        $balance = $this->credits->balance($providerId);
        $accepting = $provider['status'] === 'active' && (int) $provider['queue_open'] === 1 && $balance['available'] > 0;
        $reason = null;
        if (!$accepting) {
            $reason = $provider['status'] !== 'active' ? 'provider_unavailable'
                : (!(int) $provider['queue_open'] ? 'queue_closed' : 'booking_paused');
        }
        return [
            'date'             => $date,
            'current'          => $this->currentNumber($provider, $date),
            'waiting'          => $waiting,
            'avg_minutes'      => $this->avgServiceMinutes($provider, $date),
            'estimated_wait'   => $waiting * $this->avgServiceMinutes($provider, $date),
            'accepting'        => $accepting,
            'reason'           => $reason,
            'queue_open'       => (bool) (int) $provider['queue_open'],
        ];
    }

    /** What the customer sees on the ticket page (polled every 5–10 s). */
    public function ticketByToken(string $token): array
    {
        $ticket = $this->db->getRow("SELECT * FROM {$this->db->table('tickets')} WHERE token_hash = %s", [Security::hashToken($token)]);
        if (!$ticket) {
            throw new QueueException('ticket_not_found', 404);
        }
        $provider = $this->provider((int) $ticket['provider_id']);
        if ($this->rollover($provider) > 0) {
            $ticket = $this->ticketById((int) $ticket['id']);
        }
        return $this->publicView($ticket, $provider);
    }

    /** Provider dashboard: today's live queue + recently closed. */
    public function providerQueue(int $providerId, Actor $actor): array
    {
        $this->assertCanManage($actor, $providerId);
        $provider = $this->provider($providerId);
        $this->rollover($provider);
        $date = $this->clock->localDate($provider['timezone']);
        $t = $this->db->table('tickets');

        $active = $this->db->getResults(
            "SELECT * FROM $t WHERE provider_id = %d AND queue_date = %s AND status IN ('called','in_service','waiting')
             ORDER BY CASE status WHEN 'in_service' THEN 0 WHEN 'called' THEN 1 ELSE 2 END, sort_key ASC",
            [$providerId, $date]
        );
        $closed = $this->db->getResults(
            "SELECT * FROM $t WHERE provider_id = %d AND queue_date = %s AND status NOT IN ('called','in_service','waiting')
             ORDER BY closed_at DESC LIMIT 30",
            [$providerId, $date]
        );
        $counts = $this->db->getResults(
            "SELECT status, COUNT(*) n FROM $t WHERE provider_id = %d AND queue_date = %s GROUP BY status",
            [$providerId, $date]
        );
        $byStatus = array_fill_keys([S::WAITING, S::CALLED, S::IN_SERVICE, S::COMPLETED, S::CANCELLED, S::NO_SHOW, S::EXPIRED], 0);
        foreach ($counts as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }
        $map = fn($row) => $this->providerView($row, $provider);
        return [
            'date'    => $date,
            'active'  => array_map($map, $active),
            'closed'  => array_map($map, $closed),
            'counts'  => $byStatus,
            'live'    => $this->liveStatus($providerId),
        ];
    }

    private function publicView(array $t, array $provider): array
    {
        $ahead = null;
        if ($t['status'] === S::WAITING) {
            $ahead = (int) $this->db->getVar(
                "SELECT COUNT(*) FROM {$this->db->table('tickets')}
                 WHERE provider_id = %d AND queue_date = %s AND status = 'waiting' AND sort_key < %d",
                [(int) $t['provider_id'], $t['queue_date'], (int) $t['sort_key']]
            );
        }
        $avg = $this->avgServiceMinutes($provider, $t['queue_date']);
        return [
            'ref'            => $t['public_ref'],
            'number'         => (int) $t['ticket_number'],
            'code'           => $this->code($provider, (int) $t['ticket_number']),
            'status'         => $t['status'],
            'delayed'        => (int) $t['delayed_count'] > 0,
            'ahead'          => $ahead,
            'estimated_wait' => $ahead !== null ? $ahead * $avg : null,
            'current'        => $this->currentNumber($provider, $t['queue_date']),
            'can_cancel'     => in_array($t['status'], S::CUSTOMER_CANCELLABLE, true),
            'rating'         => $t['status'] === 'completed' ? $this->db->getRow("SELECT stars, comment FROM {$this->db->table('ratings')} WHERE ticket_id = %d", [(int) $t['id']]) : null,
            'can_rate'       => $t['status'] === 'completed' && $t['closed_at']
                && strtotime($t['closed_at'] . ' UTC') >= $this->clock->nowUtc()->getTimestamp() - max(1, $this->settings->int('rating_window_days')) * 86400,
            'date'           => $t['queue_date'],
            'phone_masked'   => Security::maskPhone($t['phone']),
            'provider'       => ['slug' => $provider['slug'], 'name_ar' => $provider['name_ar'], 'name_en' => $provider['name_en']],
        ];
    }

    private function providerView(array $t, array $provider): array
    {
        return [
            'id'            => (int) $t['id'],
            'number'        => (int) $t['ticket_number'],
            'code'          => $this->code($provider, (int) $t['ticket_number']),
            'ref'           => $t['public_ref'],
            'status'        => $t['status'],
            'delayed'       => (int) $t['delayed_count'] > 0,
            'source'        => $t['source'],
            'customer_name' => $t['customer_name'],
            'phone'         => $t['phone'],
            'service_id'    => $t['service_id'] ? (int) $t['service_id'] : null,
            'credit_state'  => $t['credit_state'],
            'created_at'    => $t['created_at'],
            'called_at'     => $t['called_at'],
            'closed_at'     => $t['closed_at'],
        ];
    }

    private function code(array $provider, int $number): string
    {
        return $provider['ticket_prefix'] . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    private function currentNumber(array $provider, string $date): ?string
    {
        $n = $this->db->getVar(
            "SELECT ticket_number FROM {$this->db->table('tickets')}
             WHERE provider_id = %d AND queue_date = %s AND called_at IS NOT NULL AND status IN ('called','in_service','completed')
             ORDER BY called_at DESC, id DESC LIMIT 1",
            [(int) $provider['id'], $date]
        );
        return $n === null ? null : $this->code($provider, (int) $n);
    }

    /** Average of today's real service times once there are 3+ samples, else the configured value. */
    private function avgServiceMinutes(array $provider, string $date): int
    {
        $row = $this->db->getRow(
            "SELECT COUNT(*) n, AVG(" . $this->db->secondsBetween('COALESCE(service_started_at, called_at)', 'closed_at') . ") s
             FROM {$this->db->table('tickets')}
             WHERE provider_id = %d AND queue_date = %s AND status = 'completed' AND called_at IS NOT NULL",
            [(int) $provider['id'], $date]
        );
        if ($row && (int) $row['n'] >= 3 && (float) $row['s'] > 0) {
            return max(1, (int) round((float) $row['s'] / 60));
        }
        return max(1, (int) $provider['avg_service_minutes']);
    }

    // ====================================================================== helpers

    public function provider(int $id): array
    {
        $p = $this->db->getRow("SELECT * FROM {$this->db->table('providers')} WHERE id = %d", [$id]);
        if (!$p) {
            throw new QueueException('provider_not_found', 404);
        }
        return $p;
    }

    private function ticketById(int $id): array
    {
        return $this->db->getRow("SELECT * FROM {$this->db->table('tickets')} WHERE id = %d", [$id]);
    }

    private function countActive(int $providerId, string $date): int
    {
        return (int) $this->db->getVar(
            "SELECT COUNT(*) FROM {$this->db->table('tickets')} WHERE provider_id = %d AND queue_date = %s AND status IN ('waiting','called','in_service')",
            [$providerId, $date]
        );
    }

    private function assertService(int $providerId, int $serviceId): void
    {
        if (!$this->db->getVar(
            "SELECT id FROM {$this->db->table('services')} WHERE id = %d AND provider_id = %d AND is_active = 1",
            [$serviceId, $providerId]
        )) {
            throw new QueueException('invalid_service', 422);
        }
    }

    /** Providers act only on their own data; blocked providers act on nothing. */
    public function assertCanManage(Actor $actor, int $providerId): void
    {
        if ($actor->isAdmin()) {
            return;
        }
        if ($actor->type !== Actor::PROVIDER || $actor->providerId !== $providerId) {
            throw new QueueException('forbidden', 403);
        }
        $status = $this->db->getVar("SELECT status FROM {$this->db->table('providers')} WHERE id = %d", [$providerId]);
        if ($status === 'blocked') {
            throw new QueueException('forbidden', 403);
        }
    }

    private function countryPhoneCode(string $country): string
    {
        return (string) ($this->db->getVar("SELECT phone_code FROM {$this->db->table('countries')} WHERE code = %s", [$country]) ?: '+213');
    }

    private function event(int $ticketId, ?string $from, string $to, string $action, Actor $actor): void
    {
        $this->db->insert($this->db->table('ticket_events'), [
            'ticket_id' => $ticketId, 'from_status' => $from, 'to_status' => $to, 'action' => $action,
            'actor_type' => $actor->type, 'actor_user_id' => $actor->userId, 'created_at' => $this->clock->utcString(),
        ]);
    }

    private function tokenFromIdempotency(string $key, int $providerId): string
    {
        return rtrim(strtr(base64_encode(substr(hash_hmac('sha256', 'tok:' . $providerId . ':' . $key, Security::hashKey('token-derivation'), true), 0, 18)), '+/', '-_'), '=');
    }

    private function idempotentReplay(string $idemHash, string $key): ?array
    {
        $row = $this->db->getRow("SELECT response FROM {$this->db->table('idempotency_keys')} WHERE key_hash = %s", [$idemHash]);
        if (!$row || !$row['response']) {
            return null;
        }
        $ticket = $this->ticketById((int) $row['response']);
        if (!$ticket) {
            return null;
        }
        $provider = $this->provider((int) $ticket['provider_id']);
        return ['token' => $this->tokenFromIdempotency($key, (int) $ticket['provider_id']), 'ticket' => $this->publicView($ticket, $provider), 'replayed' => true];
    }
}
