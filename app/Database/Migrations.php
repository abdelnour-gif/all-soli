<?php
namespace QueuePro\Database;

defined('QP_APP') || exit;

/**
 * Forward-only schema upgrades for existing installations.
 * Each migration runs once (recorded in qp_migrations). Add new entries at the end:
 *   'YYYYMMDD_name' => ['sqlite' => [...sql], 'mysql' => [...sql]]
 */
final class Migrations
{
    private const LIST = [
        // 2.1: the per-network limit no longer refuses (it asks for an SMS code instead); raise the old
        // default 4 → 6, but keep any value the Super Admin chose.
        '20261013_shared_network_limits' => [
            'sqlite' => ["UPDATE qp_settings SET setting_value = '6' WHERE setting_key = 'max_joins_per_ip_window' AND setting_value = '4'"],
            'mysql'  => ["UPDATE qp_settings SET setting_value = '6' WHERE setting_key = 'max_joins_per_ip_window' AND setting_value = '4'"],
        ],
        // Example for a future release:
        // '20270101_providers_whatsapp' => [
        //     'sqlite' => ["ALTER TABLE qp_providers ADD COLUMN whatsapp TEXT NULL"],
        //     'mysql'  => ["ALTER TABLE qp_providers ADD COLUMN whatsapp VARCHAR(20) NULL"],
        // ],
    ];

    public static function run(Connection $db): int
    {
        $done = array_column($db->getResults('SELECT id FROM qp_migrations'), 'id');
        $n = 0;
        foreach (self::LIST as $id => $sqls) {
            if (in_array($id, $done, true)) {
                continue;
            }
            foreach ($sqls[$db->driver()] ?? [] as $sql) {
                $db->exec($sql);
            }
            $db->insert('qp_migrations', ['id' => $id, 'applied_at' => gmdate('Y-m-d H:i:s')]);
            $n++;
        }
        return $n;
    }
}
