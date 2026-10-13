<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Database\DbException;
use QueuePro\Support\Actor;
use QueuePro\Support\Clock;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/** Ratings: only for completed tickets, once per ticket, through the ticket's secret link. */
final class RatingService
{
    private Connection $db;
    private Clock $clock;
    private Settings $settings;
    private AuditLogger $audit;

    public function __construct(Connection $db, Clock $clock, Settings $settings, AuditLogger $audit)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->settings = $settings;
        $this->audit = $audit;
    }

    public function rate(string $token, int $stars, ?string $comment): array
    {
        if ($stars < 1 || $stars > 5) {
            throw new QueueException('invalid_input', 422, ['field' => 'stars']);
        }
        $ticket = $this->db->getRow("SELECT * FROM {$this->db->table('tickets')} WHERE token_hash = %s", [Security::hashToken($token)]);
        if (!$ticket) {
            throw new QueueException('ticket_not_found', 404);
        }
        $days = max(1, $this->settings->int('rating_window_days'));
        if ($ticket['status'] !== 'completed' || !$ticket['closed_at']
            || strtotime($ticket['closed_at'] . ' UTC') < $this->clock->nowUtc()->getTimestamp() - $days * 86400) {
            throw new QueueException('rating_not_allowed', 409);
        }
        $comment = $comment !== null ? trim(preg_replace('/\s+/u', ' ', strip_tags($comment))) : null;
        $providerId = (int) $ticket['provider_id'];
        try {
            $this->db->transaction(function () use ($ticket, $stars, $comment, $providerId) {
                $this->db->insert($this->db->table('ratings'), [
                    'ticket_id' => (int) $ticket['id'], 'provider_id' => $providerId, 'stars' => $stars,
                    'comment' => $comment ? mb_substr($comment, 0, 500) : null,
                    'customer_name' => $ticket['customer_name'] ? mb_substr($ticket['customer_name'], 0, 40) : null,
                    'created_at' => $this->clock->utcString(),
                ]);
                $this->recompute($providerId);
            });
        } catch (DbException $e) {
            if ($e->isDuplicate()) {
                throw new QueueException('already_rated', 409);
            }
            throw $e;
        }
        return ['ok' => true];
    }

    public function forTicket(int $ticketId): ?array
    {
        return $this->db->getRow("SELECT stars, comment FROM {$this->db->table('ratings')} WHERE ticket_id = %d", [$ticketId]);
    }

    /** Public list: visible ratings, first name only. */
    public function listPublic(int $providerId, int $limit = 20): array
    {
        return $this->db->getResults(
            "SELECT stars, comment, customer_name, created_at FROM {$this->db->table('ratings')}
             WHERE provider_id = %d AND is_visible = 1 ORDER BY id DESC LIMIT %d",
            [$providerId, max(1, min(50, $limit))]
        );
    }

    public function distribution(int $providerId): array
    {
        $rows = $this->db->getResults(
            "SELECT stars, COUNT(*) n FROM {$this->db->table('ratings')} WHERE provider_id = %d AND is_visible = 1 GROUP BY stars",
            [$providerId]
        );
        $out = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($rows as $r) {
            $out[(int) $r['stars']] = (int) $r['n'];
        }
        return $out;
    }

    public function listAdmin(int $limit = 50, ?int $providerId = null): array
    {
        $sql = "SELECT r.*, p.name_ar provider_name FROM {$this->db->table('ratings')} r JOIN {$this->db->table('providers')} p ON p.id = r.provider_id";
        $params = [];
        if ($providerId) {
            $sql .= ' WHERE r.provider_id = %d';
            $params[] = $providerId;
        }
        $params[] = max(1, min(200, $limit));
        return $this->db->getResults($sql . ' ORDER BY r.id DESC LIMIT %d', $params);
    }

    /** Moderation (Super Admin): hide/show a review; averages follow. */
    public function setVisible(int $ratingId, bool $visible, Actor $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new QueueException('forbidden', 403);
        }
        $row = $this->db->getRow("SELECT * FROM {$this->db->table('ratings')} WHERE id = %d", [$ratingId]);
        if (!$row) {
            throw new QueueException('invalid_input', 404);
        }
        $this->db->transaction(function () use ($row, $visible) {
            $this->db->update($this->db->table('ratings'), ['is_visible' => $visible ? 1 : 0], ['id' => (int) $row['id']]);
            $this->recompute((int) $row['provider_id']);
        });
        $this->audit->log($actor, $visible ? 'rating.show' : 'rating.hide', 'rating', $ratingId, ['provider_id' => (int) $row['provider_id']]);
    }

    private function recompute(int $providerId): void
    {
        $agg = $this->db->getRow(
            "SELECT COUNT(*) n, COALESCE(AVG(stars),0) a FROM {$this->db->table('ratings')} WHERE provider_id = %d AND is_visible = 1",
            [$providerId]
        );
        $this->db->update($this->db->table('providers'), [
            'rating_avg' => round((float) $agg['a'], 2), 'rating_count' => (int) $agg['n'],
        ], ['id' => $providerId]);
    }
}
