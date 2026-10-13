<?php
namespace QueuePro\Database;

defined('QP_APP') || exit;

/**
 * Database schema for SQLite (default) and MySQL/MariaDB.
 * Both dialects enforce the same guarantees with constraints, not just code:
 *   - one ticket number per provider per day          UNIQUE(provider_id, queue_date, ticket_number)
 *   - one active ticket per phone per provider         partial UNIQUE index (SQLite) / generated column (MySQL)
 *   - no double reserve / consume / release            UNIQUE(ticket_id, type) on the ledger
 *   - available credit never negative                  CHECK constraint
 */
final class Schema
{
    public const VERSION = '2.1.0';

    public static function install(Connection $db): void
    {
        foreach ($db->isSqlite() ? self::sqlite() : self::mysql() as $sql) {
            $db->exec($sql);
        }
        Migrations::run($db);
    }

    /** Table names in dependency order (used by tests / uninstall). */
    public const TABLES = [
        'users', 'currencies', 'languages', 'countries', 'cities', 'categories', 'providers', 'services',
        'queue_counters', 'tickets', 'ticket_events', 'credit_accounts', 'credit_ledger', 'packages',
        'audit_log', 'settings', 'idempotency_keys', 'otp_codes', 'notifications', 'ratings', 'rate_limits', 'migrations',
    ];

    public static function drop(Connection $db): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            $db->exec('DROP TABLE IF EXISTS ' . $db->table($t));
        }
    }

    // =====================================================================================
    private static function sqlite(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS qp_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL COLLATE NOCASE UNIQUE,
                email TEXT NULL COLLATE NOCASE UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('admin','provider')),
                display_name TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                session_version INTEGER NOT NULL DEFAULT 1,
                last_login_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS qp_currencies (
                code TEXT PRIMARY KEY, name_ar TEXT NOT NULL, name_en TEXT NOT NULL, symbol TEXT NOT NULL,
                decimals INTEGER NOT NULL DEFAULT 2, is_active INTEGER NOT NULL DEFAULT 1
            )",
            "CREATE TABLE IF NOT EXISTS qp_languages (
                code TEXT PRIMARY KEY, name TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'ltr' CHECK (direction IN ('rtl','ltr')),
                is_active INTEGER NOT NULL DEFAULT 1
            )",
            "CREATE TABLE IF NOT EXISTS qp_countries (
                code TEXT PRIMARY KEY, name_ar TEXT NOT NULL, name_en TEXT NOT NULL, phone_code TEXT NOT NULL,
                currency_code TEXT NOT NULL, default_timezone TEXT NOT NULL, default_language TEXT NOT NULL DEFAULT 'ar',
                is_active INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS qp_cities (
                id INTEGER PRIMARY KEY AUTOINCREMENT, country_code TEXT NOT NULL, name_ar TEXT NOT NULL, name_en TEXT NOT NULL,
                timezone TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1
            )",
            "CREATE INDEX IF NOT EXISTS qp_cities_country ON qp_cities (country_code, is_active)",
            "CREATE TABLE IF NOT EXISTS qp_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, name_ar TEXT NOT NULL, name_en TEXT NOT NULL,
                icon TEXT NOT NULL DEFAULT 'store', sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1
            )",
            "CREATE TABLE IF NOT EXISTS qp_providers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL UNIQUE REFERENCES qp_users(id) ON DELETE SET NULL,
                slug TEXT NOT NULL UNIQUE,
                name_ar TEXT NOT NULL, name_en TEXT NULL, description_ar TEXT NULL, description_en TEXT NULL,
                category_id INTEGER NULL, country_code TEXT NOT NULL DEFAULT 'DZ', city_id INTEGER NULL,
                address TEXT NULL, phone TEXT NULL,
                timezone TEXT NOT NULL DEFAULT 'Africa/Algiers', currency_code TEXT NOT NULL DEFAULT 'DZD',
                ticket_prefix TEXT NOT NULL DEFAULT 'A', logo_url TEXT NULL, cover_url TEXT NULL, maps_url TEXT NULL,
                status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','frozen','blocked')),
                queue_open INTEGER NOT NULL DEFAULT 0,
                avg_service_minutes INTEGER NOT NULL DEFAULT 15,
                max_waiting INTEGER NOT NULL DEFAULT 0,
                last_rollover_date TEXT NULL,
                rating_avg REAL NOT NULL DEFAULT 0, rating_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS qp_providers_browse ON qp_providers (status, category_id, city_id)",
            "CREATE TABLE IF NOT EXISTS qp_services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider_id INTEGER NOT NULL REFERENCES qp_providers(id) ON DELETE CASCADE,
                name_ar TEXT NOT NULL, name_en TEXT NULL, price REAL NOT NULL DEFAULT 0,
                duration_minutes INTEGER NOT NULL DEFAULT 15, icon TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS qp_services_provider ON qp_services (provider_id, is_active, sort_order)",
            "CREATE TABLE IF NOT EXISTS qp_queue_counters (
                provider_id INTEGER NOT NULL, queue_date TEXT NOT NULL,
                last_number INTEGER NOT NULL DEFAULT 0, last_sort INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (provider_id, queue_date)
            )",
            "CREATE TABLE IF NOT EXISTS qp_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider_id INTEGER NOT NULL REFERENCES qp_providers(id),
                queue_date TEXT NOT NULL,
                ticket_number INTEGER NOT NULL,
                sort_key INTEGER NOT NULL,
                public_ref TEXT NOT NULL UNIQUE,
                token_hash TEXT NOT NULL UNIQUE,
                source TEXT NOT NULL DEFAULT 'online' CHECK (source IN ('online','walkin')),
                service_id INTEGER NULL,
                customer_name TEXT NULL,
                phone TEXT NULL,
                status TEXT NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting','called','in_service','completed','cancelled','no_show','expired')),
                delayed_count INTEGER NOT NULL DEFAULT 0,
                credit_state TEXT NOT NULL DEFAULT 'reserved' CHECK (credit_state IN ('reserved','consumed','released')),
                closed_reason TEXT NULL,
                lang TEXT NOT NULL DEFAULT 'ar',
                notified_near INTEGER NOT NULL DEFAULT 0,
                ip_hash TEXT NULL,
                created_at TEXT NOT NULL, called_at TEXT NULL, service_started_at TEXT NULL, closed_at TEXT NULL, updated_at TEXT NOT NULL,
                UNIQUE (provider_id, queue_date, ticket_number)
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS qp_tickets_one_active_per_phone ON qp_tickets (provider_id, phone)
                WHERE phone IS NOT NULL AND status IN ('waiting','called','in_service')",
            "CREATE INDEX IF NOT EXISTS qp_tickets_live ON qp_tickets (provider_id, queue_date, status, sort_key)",
            "CREATE INDEX IF NOT EXISTS qp_tickets_phone ON qp_tickets (phone)",
            "CREATE INDEX IF NOT EXISTS qp_tickets_stale ON qp_tickets (provider_id, status, queue_date)",
            "CREATE INDEX IF NOT EXISTS qp_tickets_closed ON qp_tickets (status, closed_at)",
            "CREATE TABLE IF NOT EXISTS qp_ticket_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, from_status TEXT NULL, to_status TEXT NOT NULL,
                action TEXT NOT NULL, actor_type TEXT NOT NULL, actor_user_id INTEGER NULL, created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS qp_ticket_events_ticket ON qp_ticket_events (ticket_id)",
            "CREATE TABLE IF NOT EXISTS qp_credit_accounts (
                provider_id INTEGER PRIMARY KEY,
                granted_total INTEGER NOT NULL DEFAULT 0, purchased_total INTEGER NOT NULL DEFAULT 0, adjusted_total INTEGER NOT NULL DEFAULT 0,
                reserved INTEGER NOT NULL DEFAULT 0, consumed INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL,
                CHECK (reserved >= 0 AND consumed >= 0 AND granted_total + purchased_total + adjusted_total - reserved - consumed >= 0)
            )",
            "CREATE TABLE IF NOT EXISTS qp_credit_ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('free_grant','purchased','reserved','released','consumed','adjustment')),
                amount INTEGER NOT NULL, ticket_id INTEGER NULL, package_id INTEGER NULL, payment_method TEXT NULL, paid_amount REAL NULL,
                total_after INTEGER NOT NULL, reserved_after INTEGER NOT NULL, consumed_after INTEGER NOT NULL, available_after INTEGER NOT NULL,
                reason TEXT NULL, actor_user_id INTEGER NULL, created_at TEXT NOT NULL,
                UNIQUE (ticket_id, type)
            )",
            "CREATE INDEX IF NOT EXISTS qp_ledger_provider ON qp_credit_ledger (provider_id, id)",
            "CREATE INDEX IF NOT EXISTS qp_ledger_type_date ON qp_credit_ledger (type, created_at)",
            "CREATE TABLE IF NOT EXISTS qp_packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name_ar TEXT NOT NULL, name_en TEXT NULL, credits INTEGER NOT NULL,
                price REAL NOT NULL DEFAULT 0, currency_code TEXT NOT NULL DEFAULT 'DZD', is_active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS qp_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL,
                target_type TEXT NOT NULL, target_id INTEGER NULL, details TEXT NULL, ip_hash TEXT NULL, created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS qp_audit_target ON qp_audit_log (target_type, target_id)",
            "CREATE INDEX IF NOT EXISTS qp_audit_created ON qp_audit_log (created_at)",
            "CREATE TABLE IF NOT EXISTS qp_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS qp_idempotency_keys (key_hash TEXT PRIMARY KEY, scope TEXT NOT NULL, response TEXT NULL, created_at TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS qp_otp_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL, purpose TEXT NOT NULL, code_hash TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL, consumed_at TEXT NULL, ip_hash TEXT NULL, created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS qp_otp_lookup ON qp_otp_codes (phone, purpose, id)",
            "CREATE TABLE IF NOT EXISTS qp_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id INTEGER NULL, ticket_id INTEGER NULL, type TEXT NOT NULL,
                channel TEXT NOT NULL DEFAULT 'sms', to_phone TEXT NOT NULL, body TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sending','sent','failed')),
                attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT NULL, external_id TEXT NULL, created_at TEXT NOT NULL, sent_at TEXT NULL
            )",
            "CREATE INDEX IF NOT EXISTS qp_notifications_status ON qp_notifications (status, id)",
            "CREATE TABLE IF NOT EXISTS qp_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL UNIQUE, provider_id INTEGER NOT NULL,
                stars INTEGER NOT NULL CHECK (stars BETWEEN 1 AND 5), comment TEXT NULL, customer_name TEXT NULL,
                is_visible INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS qp_ratings_provider ON qp_ratings (provider_id, is_visible, id)",
            "CREATE TABLE IF NOT EXISTS qp_rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, window_start TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS qp_migrations (id TEXT PRIMARY KEY, applied_at TEXT NOT NULL)",
        ];
    }

    // =====================================================================================
    private static function mysql(): array
    {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS qp_users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, username VARCHAR(60) NOT NULL, email VARCHAR(190) NULL,
                password_hash VARCHAR(255) NOT NULL, role ENUM('admin','provider') NOT NULL, display_name VARCHAR(120) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1, session_version INT UNSIGNED NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY username (username), UNIQUE KEY email (email)
            ) $o",
            "CREATE TABLE IF NOT EXISTS qp_currencies (code CHAR(3) NOT NULL, name_ar VARCHAR(60) NOT NULL, name_en VARCHAR(60) NOT NULL, symbol VARCHAR(10) NOT NULL, decimals TINYINT UNSIGNED NOT NULL DEFAULT 2, is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (code)) $o",
            "CREATE TABLE IF NOT EXISTS qp_languages (code VARCHAR(10) NOT NULL, name VARCHAR(40) NOT NULL, direction ENUM('rtl','ltr') NOT NULL DEFAULT 'ltr', is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (code)) $o",
            "CREATE TABLE IF NOT EXISTS qp_countries (code CHAR(2) NOT NULL, name_ar VARCHAR(80) NOT NULL, name_en VARCHAR(80) NOT NULL, phone_code VARCHAR(6) NOT NULL, currency_code CHAR(3) NOT NULL, default_timezone VARCHAR(64) NOT NULL, default_language VARCHAR(10) NOT NULL DEFAULT 'ar', is_active TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (code)) $o",
            "CREATE TABLE IF NOT EXISTS qp_cities (id INT UNSIGNED NOT NULL AUTO_INCREMENT, country_code CHAR(2) NOT NULL, name_ar VARCHAR(80) NOT NULL, name_en VARCHAR(80) NOT NULL, timezone VARCHAR(64) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), KEY country (country_code, is_active)) $o",
            "CREATE TABLE IF NOT EXISTS qp_categories (id INT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(60) NOT NULL, name_ar VARCHAR(80) NOT NULL, name_en VARCHAR(80) NOT NULL, icon VARCHAR(40) NOT NULL DEFAULT 'store', sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY slug (slug)) $o",
            "CREATE TABLE IF NOT EXISTS qp_providers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NULL, slug VARCHAR(80) NOT NULL,
                name_ar VARCHAR(120) NOT NULL, name_en VARCHAR(120) NULL, description_ar TEXT NULL, description_en TEXT NULL,
                category_id INT UNSIGNED NULL, country_code CHAR(2) NOT NULL DEFAULT 'DZ', city_id INT UNSIGNED NULL,
                address VARCHAR(255) NULL, phone VARCHAR(20) NULL, timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Algiers',
                currency_code CHAR(3) NOT NULL DEFAULT 'DZD', ticket_prefix VARCHAR(3) NOT NULL DEFAULT 'A',
                logo_url VARCHAR(255) NULL, cover_url VARCHAR(255) NULL, maps_url VARCHAR(500) NULL,
                status ENUM('active','frozen','blocked') NOT NULL DEFAULT 'active', queue_open TINYINT(1) NOT NULL DEFAULT 0,
                avg_service_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15, max_waiting SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_rollover_date DATE NULL, rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0, rating_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY slug (slug), UNIQUE KEY user_id (user_id), KEY browse (status, category_id, city_id)
            ) $o",
            "CREATE TABLE IF NOT EXISTS qp_services (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, provider_id BIGINT UNSIGNED NOT NULL, name_ar VARCHAR(120) NOT NULL, name_en VARCHAR(120) NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0, duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15, icon VARCHAR(40) NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY provider (provider_id, is_active, sort_order)) $o",
            "CREATE TABLE IF NOT EXISTS qp_queue_counters (provider_id BIGINT UNSIGNED NOT NULL, queue_date DATE NOT NULL, last_number INT UNSIGNED NOT NULL DEFAULT 0, last_sort INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (provider_id, queue_date)) $o",
            "CREATE TABLE IF NOT EXISTS qp_tickets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, provider_id BIGINT UNSIGNED NOT NULL, queue_date DATE NOT NULL,
                ticket_number INT UNSIGNED NOT NULL, sort_key INT UNSIGNED NOT NULL, public_ref CHAR(10) NOT NULL, token_hash CHAR(64) NOT NULL,
                source ENUM('online','walkin') NOT NULL DEFAULT 'online', service_id BIGINT UNSIGNED NULL, customer_name VARCHAR(80) NULL, phone VARCHAR(20) NULL,
                status ENUM('waiting','called','in_service','completed','cancelled','no_show','expired') NOT NULL DEFAULT 'waiting',
                delayed_count TINYINT UNSIGNED NOT NULL DEFAULT 0, credit_state ENUM('reserved','consumed','released') NOT NULL DEFAULT 'reserved',
                closed_reason VARCHAR(30) NULL, lang CHAR(2) NOT NULL DEFAULT 'ar', notified_near TINYINT(1) NOT NULL DEFAULT 0, ip_hash CHAR(64) NULL,
                active_phone_key VARCHAR(20) AS (CASE WHEN status IN ('waiting','called','in_service') THEN phone ELSE NULL END) STORED,
                created_at DATETIME NOT NULL, called_at DATETIME NULL, service_started_at DATETIME NULL, closed_at DATETIME NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY day_number (provider_id, queue_date, ticket_number), UNIQUE KEY token_hash (token_hash),
                UNIQUE KEY public_ref (public_ref), UNIQUE KEY one_active_per_phone (provider_id, active_phone_key),
                KEY live_queue (provider_id, queue_date, status, sort_key), KEY phone (phone), KEY stale (provider_id, status, queue_date), KEY closed (status, closed_at)
            ) $o",
            "CREATE TABLE IF NOT EXISTS qp_ticket_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ticket_id BIGINT UNSIGNED NOT NULL, from_status VARCHAR(20) NULL, to_status VARCHAR(20) NOT NULL, action VARCHAR(30) NOT NULL, actor_type VARCHAR(20) NOT NULL, actor_user_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY ticket (ticket_id)) $o",
            "CREATE TABLE IF NOT EXISTS qp_credit_accounts (provider_id BIGINT UNSIGNED NOT NULL, granted_total INT NOT NULL DEFAULT 0, purchased_total INT NOT NULL DEFAULT 0, adjusted_total INT NOT NULL DEFAULT 0, reserved INT NOT NULL DEFAULT 0, consumed INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, PRIMARY KEY (provider_id),
                CONSTRAINT chk_not_negative CHECK (reserved >= 0 AND consumed >= 0 AND granted_total + purchased_total + adjusted_total - reserved - consumed >= 0)) $o",
            "CREATE TABLE IF NOT EXISTS qp_credit_ledger (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, provider_id BIGINT UNSIGNED NOT NULL, type ENUM('free_grant','purchased','reserved','released','consumed','adjustment') NOT NULL, amount INT NOT NULL, ticket_id BIGINT UNSIGNED NULL, package_id BIGINT UNSIGNED NULL, payment_method VARCHAR(40) NULL, paid_amount DECIMAL(12,2) NULL, total_after INT NOT NULL, reserved_after INT NOT NULL, consumed_after INT NOT NULL, available_after INT NOT NULL, reason VARCHAR(255) NULL, actor_user_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY ticket_movement (ticket_id, type), KEY provider (provider_id, id), KEY type_date (type, created_at)) $o",
            "CREATE TABLE IF NOT EXISTS qp_packages (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name_ar VARCHAR(80) NOT NULL, name_en VARCHAR(80) NULL, credits INT UNSIGNED NOT NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0, currency_code CHAR(3) NOT NULL DEFAULT 'DZD', is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, PRIMARY KEY (id)) $o",
            "CREATE TABLE IF NOT EXISTS qp_audit_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, actor_user_id BIGINT UNSIGNED NULL, actor_role VARCHAR(30) NOT NULL, action VARCHAR(60) NOT NULL, target_type VARCHAR(30) NOT NULL, target_id BIGINT UNSIGNED NULL, details LONGTEXT NULL, ip_hash CHAR(64) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY target (target_type, target_id), KEY created (created_at)) $o",
            "CREATE TABLE IF NOT EXISTS qp_settings (setting_key VARCHAR(60) NOT NULL, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (setting_key)) $o",
            "CREATE TABLE IF NOT EXISTS qp_idempotency_keys (key_hash CHAR(64) NOT NULL, scope VARCHAR(40) NOT NULL, response LONGTEXT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (key_hash), KEY created (created_at)) $o",
            "CREATE TABLE IF NOT EXISTS qp_otp_codes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, phone VARCHAR(20) NOT NULL, purpose VARCHAR(20) NOT NULL, code_hash CHAR(64) NOT NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, ip_hash CHAR(64) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY lookup (phone, purpose, id)) $o",
            "CREATE TABLE IF NOT EXISTS qp_notifications (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, provider_id BIGINT UNSIGNED NULL, ticket_id BIGINT UNSIGNED NULL, type VARCHAR(20) NOT NULL, channel VARCHAR(20) NOT NULL DEFAULT 'sms', to_phone VARCHAR(20) NOT NULL, body TEXT NOT NULL, status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending', attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, last_error VARCHAR(255) NULL, external_id VARCHAR(100) NULL, created_at DATETIME NOT NULL, sent_at DATETIME NULL, PRIMARY KEY (id), KEY status (status, id)) $o",
            "CREATE TABLE IF NOT EXISTS qp_ratings (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ticket_id BIGINT UNSIGNED NOT NULL, provider_id BIGINT UNSIGNED NOT NULL, stars TINYINT UNSIGNED NOT NULL, comment VARCHAR(500) NULL, customer_name VARCHAR(80) NULL, is_visible TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY one_per_ticket (ticket_id), KEY provider (provider_id, is_visible, id), CONSTRAINT chk_stars CHECK (stars BETWEEN 1 AND 5)) $o",
            "CREATE TABLE IF NOT EXISTS qp_rate_limits (bucket CHAR(64) NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0, window_start DATETIME NOT NULL, PRIMARY KEY (bucket)) $o",
            "CREATE TABLE IF NOT EXISTS qp_migrations (id VARCHAR(60) NOT NULL, applied_at DATETIME NOT NULL, PRIMARY KEY (id)) $o",
        ];
    }
}
