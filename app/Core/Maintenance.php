<?php
namespace QueuePro\Core;

use QueuePro\Container;
use QueuePro\Notifications\Dispatcher;

defined('QP_APP') || exit;

/** Periodic jobs. Triggered after web responses (every 5 min) or by `php bin/console.php cron`. */
final class Maintenance
{
    public static function run(Container $c, bool $force = false): array
    {
        $lock = @fopen(QP_ROOT . '/storage/maintenance.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => true];
        }
        try {
            if (!$force && (int) Cache::get('maint_at') >= time() - 300) {
                return ['skipped' => true];
            }
            Cache::set('maint_at', time(), 86400);
            $out = [];
            $out['expired_tickets'] = $c->queue->rolloverAll();
            $out['messages_sent'] = (new Dispatcher($c))->run(50);
            $c->limiter->purgeOld();
            $c->otp->purgeOld();
            $c->db->query('DELETE FROM qp_idempotency_keys WHERE created_at < %s', [gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
            Cache::prune();
            foreach (glob(QP_ROOT . '/storage/backups/queuepro-*') ?: [] as $f) {
                if (@filemtime($f) < time() - 3600) {
                    @unlink($f); // leftovers of interrupted backup downloads
                }
            }
            if ($c->db->isSqlite() && (int) Cache::get('optimize_at') < time() - 86400) {
                Cache::set('optimize_at', time(), 172800);
                $c->db->exec('PRAGMA optimize');
            }
            return $out;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
