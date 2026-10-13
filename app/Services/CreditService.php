<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Database\DbException;
use QueuePro\Support\Actor;
use QueuePro\Support\Clock;
use QueuePro\Support\QueueException;

defined('QP_APP') || exit;

/**
 * Credit accounting.
 *
 *   Total     = granted + purchased + adjustments
 *   Available = Total − Reserved − Consumed      (never < 0, enforced here AND by a CHECK constraint)
 *
 * Booking   → reserve(+1 reserved)
 * Complete  → consume (−1 reserved, +1 consumed)
 * Cancel / No-show / end of day → release (−1 reserved)   [policy configurable]
 *
 * Every movement locks the provider's account row (SELECT … FOR UPDATE) and writes
 * one ledger line carrying the balances *after* the movement. Lock order across the
 * code base is always: credit_accounts → queue_counters → tickets.
 */
final class CreditService
{
    public const FREE_GRANT = 'free_grant';
    public const PURCHASED  = 'purchased';
    public const RESERVED   = 'reserved';
    public const RELEASED   = 'released';
    public const CONSUMED   = 'consumed';
    public const ADJUSTMENT = 'adjustment';

    private Connection $db;
    private Clock $clock;
    private AuditLogger $audit;

    public function __construct(Connection $db, Clock $clock, AuditLogger $audit)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->audit = $audit;
    }

    public static function available(array $acc): int
    {
        return self::total($acc) - (int) $acc['reserved'] - (int) $acc['consumed'];
    }

    public static function total(array $acc): int
    {
        return (int) $acc['granted_total'] + (int) $acc['purchased_total'] + (int) $acc['adjusted_total'];
    }

    /** Must be called inside a transaction. Creates the account row on first use. */
    public function lockAccount(int $providerId): array
    {
        $this->assertTx();
        $t = $this->db->table('credit_accounts');
        $acc = $this->db->getRow("SELECT * FROM $t WHERE provider_id = %d" . $this->db->forUpdate(), [$providerId]);
        if (!$acc) {
            $this->db->query(
                $this->db->insertIgnore() . " INTO $t (provider_id, updated_at) VALUES (%d, %s)",
                [$providerId, $this->clock->utcString()]
            );
            $acc = $this->db->getRow("SELECT * FROM $t WHERE provider_id = %d" . $this->db->forUpdate(), [$providerId]);
        }
        return $acc;
    }

    /** Read-only snapshot for dashboards. */
    public function balance(int $providerId): array
    {
        $acc = $this->db->getRow("SELECT * FROM {$this->db->table('credit_accounts')} WHERE provider_id = %d", [$providerId])
            ?? ['granted_total' => 0, 'purchased_total' => 0, 'adjusted_total' => 0, 'reserved' => 0, 'consumed' => 0];
        return [
            'total'     => self::total($acc),
            'available' => self::available($acc),
            'reserved'  => (int) $acc['reserved'],
            'consumed'  => (int) $acc['consumed'],
            'granted'   => (int) $acc['granted_total'],
            'purchased' => (int) $acc['purchased_total'],
            'adjusted'  => (int) $acc['adjusted_total'],
        ];
    }

    // ------------------------------------------------------------------ admin side

    /**
     * Add credits (free grant, purchase) or adjust (+/−) — Super Admin only.
     * Negative adjustments can never push Available below zero.
     */
    public function addCredits(int $providerId, string $type, int $amount, Actor $actor, array $meta = []): array
    {
        if (!in_array($type, [self::FREE_GRANT, self::PURCHASED, self::ADJUSTMENT], true)) {
            throw new QueueException('invalid_input', 422);
        }
        if ($amount === 0 || abs($amount) > 100000 || ($type !== self::ADJUSTMENT && $amount < 0)) {
            throw new QueueException('invalid_amount', 422);
        }
        if (!$actor->isAdmin() && $actor->type !== Actor::SYSTEM) {
            throw new QueueException('forbidden', 403);
        }

        return $this->db->transaction(function () use ($providerId, $type, $amount, $actor, $meta) {
            $acc = $this->lockAccount($providerId);
            $col = ['free_grant' => 'granted_total', 'purchased' => 'purchased_total', 'adjustment' => 'adjusted_total'][$type];
            $acc[$col] = (int) $acc[$col] + $amount;
            if (self::available($acc) < 0) {
                throw new QueueException('insufficient_credit', 409, ['available' => self::available($acc) - $amount]);
            }
            $this->db->query(
                "UPDATE {$this->db->table('credit_accounts')} SET $col = %d, updated_at = %s WHERE provider_id = %d",
                [$acc[$col], $this->clock->utcString(), $providerId]
            );
            $ledgerId = $this->writeLedger($providerId, $type, $amount, $acc, null, $actor, $meta);
            $this->audit->log($actor, 'credits.' . $type, 'provider', $providerId, [
                'amount' => $amount, 'ledger_id' => $ledgerId,
                'available_after' => self::available($acc),
            ] + array_intersect_key($meta, array_flip(['package_id', 'payment_method', 'paid_amount', 'reason'])));
            return $this->balanceFrom($acc);
        });
    }

    /** Grant a package: credits come from the package row. */
    public function addPackage(int $providerId, int $packageId, string $paymentMethod, ?string $note, Actor $actor): array
    {
        $pkg = $this->db->getRow("SELECT * FROM {$this->db->table('packages')} WHERE id = %d AND is_active = 1", [$packageId]);
        if (!$pkg) {
            throw new QueueException('invalid_input', 422, ['field' => 'package_id']);
        }
        return $this->addCredits($providerId, self::PURCHASED, (int) $pkg['credits'], $actor, [
            'package_id' => (int) $pkg['id'],
            'payment_method' => mb_substr($paymentMethod, 0, 40),
            'paid_amount' => $pkg['price'],
            'reason' => $note,
        ]);
    }

    // ------------------------------------------------------------------ ticket side (inside caller's transaction)

    /** Reserve 1 credit for a new ticket. Caller has already locked the account. */
    public function reserve(int $providerId, int $ticketId, array &$acc): void
    {
        $this->assertTx();
        if (self::available($acc) < 1) {
            throw new QueueException('booking_paused', 409);
        }
        $acc['reserved'] = (int) $acc['reserved'] + 1;
        $this->saveTicketMovement($providerId, $ticketId, self::RESERVED, $acc);
    }

    /** Reserved → Consumed. */
    public function consume(int $providerId, int $ticketId, array &$acc, Actor $actor): void
    {
        $this->assertTx();
        $acc['reserved'] = (int) $acc['reserved'] - 1;
        $acc['consumed'] = (int) $acc['consumed'] + 1;
        $this->saveTicketMovement($providerId, $ticketId, self::CONSUMED, $acc, $actor);
    }

    /** Reserved → back to Available. */
    public function release(int $providerId, int $ticketId, array &$acc, Actor $actor, string $reason): void
    {
        $this->assertTx();
        $acc['reserved'] = (int) $acc['reserved'] - 1;
        $this->saveTicketMovement($providerId, $ticketId, self::RELEASED, $acc, $actor, $reason);
    }

    private function saveTicketMovement(int $providerId, int $ticketId, string $type, array $acc, ?Actor $actor = null, ?string $reason = null): void
    {
        if ((int) $acc['reserved'] < 0) {
            // Should be impossible; refuse rather than corrupt the books.
            throw new QueueException('insufficient_credit', 409);
        }
        $this->db->query(
            "UPDATE {$this->db->table('credit_accounts')} SET reserved = %d, consumed = %d, updated_at = %s WHERE provider_id = %d",
            [$acc['reserved'], $acc['consumed'], $this->clock->utcString(), $providerId]
        );
        try {
            $this->writeLedger($providerId, $type, $type === self::RELEASED ? -1 : 1, $acc, $ticketId, $actor ?? Actor::system(), ['reason' => $reason]);
        } catch (DbException $e) {
            if ($e->isDuplicate()) {
                // UNIQUE(ticket_id, type): this movement already happened → block double spend.
                throw new QueueException('duplicate_request', 409);
            }
            throw $e;
        }
    }

    private function writeLedger(int $providerId, string $type, int $amount, array $acc, ?int $ticketId, Actor $actor, array $meta): int
    {
        return $this->db->insert($this->db->table('credit_ledger'), [
            'provider_id'     => $providerId,
            'type'            => $type,
            'amount'          => $amount,
            'ticket_id'       => $ticketId,
            'package_id'      => $meta['package_id'] ?? null,
            'payment_method'  => $meta['payment_method'] ?? null,
            'paid_amount'     => $meta['paid_amount'] ?? null,
            'total_after'     => self::total($acc),
            'reserved_after'  => (int) $acc['reserved'],
            'consumed_after'  => (int) $acc['consumed'],
            'available_after' => self::available($acc),
            'reason'          => isset($meta['reason']) ? mb_substr((string) $meta['reason'], 0, 255) : null,
            'actor_user_id'   => $actor->userId,
            'created_at'      => $this->clock->utcString(),
        ]);
    }

    public function ledger(int $providerId, int $limit = 50, int $beforeId = 0): array
    {
        $limit = max(1, min(200, $limit));
        $sql = "SELECT * FROM {$this->db->table('credit_ledger')} WHERE provider_id = %d"
            . ($beforeId > 0 ? ' AND id < %d' : '') . ' ORDER BY id DESC LIMIT %d';
        $params = $beforeId > 0 ? [$providerId, $beforeId, $limit] : [$providerId, $limit];
        return $this->db->getResults($sql, $params);
    }

    private function balanceFrom(array $acc): array
    {
        return [
            'total' => self::total($acc), 'available' => self::available($acc),
            'reserved' => (int) $acc['reserved'], 'consumed' => (int) $acc['consumed'],
        ];
    }

    private function assertTx(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Credit movements must run inside a transaction.');
        }
    }
}
