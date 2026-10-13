<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Support\Actor;
use QueuePro\Support\Clock;

defined('QP_APP') || exit;

/** Append-only log of sensitive operations: who, what, on which target, when. */
final class AuditLogger
{
    private Connection $db;
    private Clock $clock;

    public function __construct(Connection $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    public function log(Actor $actor, string $action, string $targetType, ?int $targetId, array $details = []): void
    {
        $this->db->insert($this->db->table('audit_log'), [
            'actor_user_id' => $actor->userId,
            'actor_role'    => $actor->type,
            'action'        => $action,
            'target_type'   => $targetType,
            'target_id'     => $targetId,
            'details'       => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip_hash'       => $actor->ipHash,
            'created_at'    => $this->clock->utcString(),
        ]);
    }

    public function recent(int $limit = 50, int $offset = 0, ?string $targetType = null, ?int $targetId = null): array
    {
        $limit = max(1, min(200, $limit));
        $where = '1=1';
        $params = [];
        if ($targetType) {
            $where .= ' AND target_type = %s';
            $params[] = $targetType;
        }
        if ($targetId) {
            $where .= ' AND target_id = %d';
            $params[] = $targetId;
        }
        $params[] = $limit;
        $params[] = max(0, $offset);
        return $this->db->getResults(
            "SELECT * FROM {$this->db->table('audit_log')} WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
            $params
        );
    }
}
