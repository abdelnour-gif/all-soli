<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Support\Clock;

defined('QP_APP') || exit;

/** Super Admin dashboard numbers. */
final class StatsService
{
    private Connection $db;
    private Clock $clock;

    public function __construct(Connection $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    public function overview(): array
    {
        $t = fn($n) => $this->db->table($n);
        $providers = $this->db->getRow(
            "SELECT COUNT(*) total, SUM(status='active') active, SUM(status='frozen') frozen, SUM(status='blocked') blocked, SUM(queue_open=1 AND status='active') open_now
             FROM {$t('providers')}"
        );
        $credits = $this->db->getRow(
            "SELECT COALESCE(SUM(granted_total),0) granted, COALESCE(SUM(purchased_total),0) purchased,
                    COALESCE(SUM(adjusted_total),0) adjusted, COALESCE(SUM(reserved),0) reserved, COALESCE(SUM(consumed),0) consumed
             FROM {$t('credit_accounts')}"
        );
        $revenue = $this->db->getVar("SELECT COALESCE(SUM(paid_amount),0) FROM {$t('credit_ledger')} WHERE type = 'purchased'");
        return [
            'providers'          => array_map('intval', $providers),
            'active_bookings'    => (int) $this->db->getVar("SELECT COUNT(*) FROM {$t('tickets')} WHERE status IN ('waiting','called','in_service')"),
            'completed_total'    => (int) $this->db->getVar("SELECT COUNT(*) FROM {$t('tickets')} WHERE status = 'completed'"),
            'credits'            => array_map('intval', $credits) + ['revenue' => (float) $revenue],
            'low_credit_providers' => (int) $this->db->getVar(
                "SELECT COUNT(*) FROM {$t('credit_accounts')} a JOIN {$t('providers')} p ON p.id = a.provider_id
                 WHERE p.status = 'active' AND a.granted_total + a.purchased_total + a.adjusted_total - a.reserved - a.consumed <= %d",
                [(int) $this->db->getVar("SELECT setting_value FROM {$t('settings')} WHERE setting_key = 'low_credit_threshold'")]
            ),
        ];
    }

    /** Completed services per day for the last N days (UTC dates of completion). */
    public function completedSeries(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $rows = $this->db->getResults(
            "SELECT SUBSTR(closed_at, 1, 10) d, COUNT(*) n FROM {$this->db->table('tickets')}
             WHERE status = 'completed' AND closed_at >= %s GROUP BY SUBSTR(closed_at, 1, 10) ORDER BY d",
            [$this->clock->nowUtc()->modify('-' . $days . ' days')->format('Y-m-d 00:00:00')]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = (int) $r['n'];
        }
        $out = [];
        $start = $this->clock->nowUtc()->modify('-' . ($days - 1) . ' days');
        for ($i = 0; $i < $days; $i++) {
            $d = $start->modify("+$i days")->format('Y-m-d');
            $out[] = ['date' => $d, 'completed' => $map[$d] ?? 0];
        }
        return $out;
    }

    /** Platform-wide bookings list for the Super Admin (newest first). */
    public function recentTickets(array $f = [], int $limit = 50, int $offset = 0): array
    {
        $t = $this->db->table('tickets');
        $where = '1=1';
        $params = [];
        if (!empty($f['provider_id'])) {
            $where .= ' AND t.provider_id = %d';
            $params[] = (int) $f['provider_id'];
        }
        if (!empty($f['status']) && in_array($f['status'], ['waiting', 'called', 'in_service', 'completed', 'cancelled', 'no_show', 'expired', 'active'], true)) {
            if ($f['status'] === 'active') {
                $where .= " AND t.status IN ('waiting','called','in_service')";
            } else {
                $where .= ' AND t.status = %s';
                $params[] = $f['status'];
            }
        }
        if (!empty($f['date'])) {
            $where .= ' AND t.queue_date = %s';
            $params[] = (string) $f['date'];
        }
        if (!empty($f['phone'])) {
            $where .= ' AND t.phone LIKE %s';
            $params[] = '%' . preg_replace('/[^\d+]/', '', (string) $f['phone']) . '%';
        }
        $params[] = max(1, min(200, $limit));
        $params[] = max(0, $offset);
        return $this->db->getResults(
            "SELECT t.id, t.provider_id, p.name_ar provider_name, p.ticket_prefix, t.queue_date, t.ticket_number, t.public_ref, t.source,
                    t.customer_name, t.phone, t.status, t.credit_state, t.delayed_count, t.created_at, t.closed_at
             FROM $t t JOIN {$this->db->table('providers')} p ON p.id = t.provider_id
             WHERE $where ORDER BY t.id DESC LIMIT %d OFFSET %d",
            $params
        );
    }
}
