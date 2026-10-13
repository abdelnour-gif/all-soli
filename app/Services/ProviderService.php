<?php
namespace QueuePro\Services;

use QueuePro\Database\Connection;
use QueuePro\Database\DbException;
use QueuePro\Support\Actor;
use QueuePro\Support\Clock;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/** Provider lifecycle (admin), profile & services (owner), public browsing. */
final class ProviderService
{
    private const RESERVED_SLUGS = [
        'admin', 'wp-admin', 'wp-json', 'wp-login', 'login', 'logout', 'register', 'api', 'p', 't', 'ticket',
        'tickets', 'providers', 'dashboard', 'account', 'super-admin', 'search', 'about', 'faq', 'contact',
        'explore', 'install', 'recover-admin', 'assets', 'uploads', 'storage', 'app', 'bin', 'tests', 'templates',
        'index', 'index-php', 'robots-txt', 'favicon-ico', 'sitemap', 'home', 'help', 'privacy', 'terms', 'static',
    ];
    private const PROFILE_FIELDS_OWNER = [
        'name_ar', 'name_en', 'description_ar', 'description_en', 'address', 'phone',
        'logo_url', 'cover_url', 'maps_url', 'avg_service_minutes', 'max_waiting', 'ticket_prefix',
    ];
    private const PROFILE_FIELDS_ADMIN = ['slug', 'category_id', 'city_id', 'country_code', 'timezone', 'currency_code', 'user_id'];

    private Connection $db;
    private Clock $clock;
    private CreditService $credits;
    private Settings $settings;
    private AuditLogger $audit;

    public function __construct(Connection $db, Clock $clock, CreditService $credits, Settings $settings, AuditLogger $audit)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->credits = $credits;
        $this->settings = $settings;
        $this->audit = $audit;
    }

    // ---------------------------------------------------------------- admin

    /** Super Admin creates a provider; the free grant (default 10) is credited atomically. */
    public function create(array $data, Actor $actor): array
    {
        $this->assertAdmin($actor);
        $slug = $this->validSlug((string) ($data['slug'] ?? ''));
        $name = Security::cleanName($data['name_ar'] ?? null);
        if (!$name) {
            throw new QueueException('invalid_name', 422);
        }
        $tz = (string) ($data['timezone'] ?? 'Africa/Algiers');
        $this->validTimezone($tz);

        $userId = !empty($data['user_id']) ? $this->assertLinkableUser((int) $data['user_id'], null) : null;
        $now = $this->clock->utcString();
        try {
            $id = $this->db->insert($this->db->table('providers'), [
                'user_id'      => $userId,
                'slug'         => $slug,
                'name_ar'      => $name,
                'name_en'      => Security::cleanName($data['name_en'] ?? null),
                'category_id'  => !empty($data['category_id']) ? (int) $data['category_id'] : null,
                'city_id'      => !empty($data['city_id']) ? (int) $data['city_id'] : null,
                'country_code' => strtoupper(substr((string) ($data['country_code'] ?? 'DZ'), 0, 2)),
                'timezone'     => $tz,
                'currency_code'=> strtoupper(substr((string) ($data['currency_code'] ?? 'DZD'), 0, 3)),
                'address'      => isset($data['address']) ? mb_substr(strip_tags((string) $data['address']), 0, 255) : null,
                'phone'        => isset($data['phone']) ? Security::normalizePhone((string) $data['phone']) : null,
                'status'       => 'active',
                'queue_open'   => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (DbException $e) {
            if ($e->isDuplicate()) {
                throw new QueueException($this->db->getVar("SELECT id FROM {$this->db->table('providers')} WHERE slug = %s", [$slug]) ? 'slug_taken' : 'invalid_input', 409);
            }
            throw $e;
        }

        $this->audit->log($actor, 'provider.create', 'provider', $id, ['slug' => $slug]);
        $grant = $this->settings->int('free_grant_credits');
        if ($grant > 0) {
            $this->credits->addCredits($id, CreditService::FREE_GRANT, $grant, $actor, ['reason' => 'welcome grant']);
        }
        return $this->get($id);
    }

    /** active | frozen (no new bookings, can finish queue) | blocked (hidden, no actions). */
    public function setStatus(int $providerId, string $status, Actor $actor, ?string $reason = null): array
    {
        $this->assertAdmin($actor);
        if (!in_array($status, ['active', 'frozen', 'blocked'], true)) {
            throw new QueueException('invalid_input', 422);
        }
        $old = $this->get($providerId)['status'];
        $set = ['status' => $status, 'updated_at' => $this->clock->utcString()];
        if ($status !== 'active') {
            $set['queue_open'] = 0;
        }
        $this->db->update($this->db->table('providers'), $set, ['id' => $providerId]);
        $this->audit->log($actor, 'provider.status', 'provider', $providerId, ['from' => $old, 'to' => $status, 'reason' => $reason]);
        return $this->get($providerId);
    }

    // ---------------------------------------------------------------- profile

    public function update(int $providerId, array $data, Actor $actor): array
    {
        $allowed = self::PROFILE_FIELDS_OWNER;
        if ($actor->isAdmin()) {
            $allowed = array_merge($allowed, self::PROFILE_FIELDS_ADMIN);
        } elseif ($actor->type !== Actor::PROVIDER || $actor->providerId !== $providerId) {
            throw new QueueException('forbidden', 403);
        }
        $set = [];
        foreach (array_intersect_key($data, array_flip($allowed)) as $k => $v) {
            switch ($k) {
                case 'slug':
                    $set[$k] = $this->validSlug((string) $v, $providerId);
                    break;
                case 'timezone':
                    $this->validTimezone((string) $v);
                    $set[$k] = (string) $v;
                    break;
                case 'name_ar':
                    $set[$k] = Security::cleanName((string) $v) ?: throw new QueueException('invalid_name', 422);
                    break;
                case 'avg_service_minutes':
                    $set[$k] = max(1, min(600, (int) $v));
                    break;
                case 'max_waiting':
                    $set[$k] = max(0, min(5000, (int) $v));
                    break;
                case 'ticket_prefix':
                    $set[$k] = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $v)) ?: 'A';
                    $set[$k] = substr($set[$k], 0, 3);
                    break;
                case 'maps_url':
                case 'logo_url':
                case 'cover_url':
                    $set[$k] = filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $v) ? (string) $v : null;
                    break;
                case 'phone':
                    $set[$k] = $v ? Security::normalizePhone((string) $v) : null;
                    break;
                case 'category_id':
                case 'city_id':
                    $set[$k] = $v ? (int) $v : null;
                    break;
                case 'user_id':
                    $set[$k] = $v ? $this->assertLinkableUser((int) $v, $providerId) : null;
                    break;
                default:
                    $set[$k] = $v === null ? null : mb_substr(strip_tags((string) $v), 0, $k === 'description_ar' || $k === 'description_en' ? 2000 : 255);
            }
        }
        if ($set) {
            $set['updated_at'] = $this->clock->utcString();
            $this->db->update($this->db->table('providers'), $set, ['id' => $providerId]);
            if ($actor->isAdmin()) {
                $this->audit->log($actor, 'provider.update', 'provider', $providerId, ['fields' => array_keys($set)]);
            }
        }
        return $this->get($providerId);
    }

    // ---------------------------------------------------------------- services

    public function services(int $providerId, bool $activeOnly = true): array
    {
        return $this->db->getResults(
            "SELECT id, name_ar, name_en, price, duration_minutes, icon, sort_order, is_active
             FROM {$this->db->table('services')} WHERE provider_id = %d" . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id',
            [$providerId]
        );
    }

    public function saveService(int $providerId, array $data, Actor $actor, ?int $serviceId = null): array
    {
        $this->assertOwnerOrAdmin($actor, $providerId);
        $name = Security::cleanName($data['name_ar'] ?? null);
        if (!$name) {
            throw new QueueException('invalid_name', 422);
        }
        $row = [
            'name_ar'          => $name,
            'name_en'          => Security::cleanName($data['name_en'] ?? null),
            'price'            => max(0, round((float) ($data['price'] ?? 0), 2)),
            'duration_minutes' => max(1, min(600, (int) ($data['duration_minutes'] ?? 15))),
            'icon'             => isset($data['icon']) ? preg_replace('/[^a-z0-9-]/', '', strtolower((string) $data['icon'])) : null,
            'sort_order'       => (int) ($data['sort_order'] ?? 0),
            'is_active'        => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'updated_at'       => $this->clock->utcString(),
        ];
        if ($serviceId) {
            $n = $this->db->query(
                "UPDATE {$this->db->table('services')} SET name_ar=%s, name_en=%s, price=%f, duration_minutes=%d, icon=%s, sort_order=%d, is_active=%d, updated_at=%s
                 WHERE id = %d AND provider_id = %d",
                [$row['name_ar'], (string) $row['name_en'], $row['price'], $row['duration_minutes'], (string) $row['icon'], $row['sort_order'], $row['is_active'], $row['updated_at'], $serviceId, $providerId]
            );
            $exists = $this->db->getVar("SELECT id FROM {$this->db->table('services')} WHERE id = %d AND provider_id = %d", [$serviceId, $providerId]);
            if (!$exists) {
                throw new QueueException('invalid_service', 404);
            }
        } else {
            $serviceId = $this->db->insert($this->db->table('services'), $row + ['provider_id' => $providerId, 'created_at' => $row['updated_at']]);
        }
        return $this->db->getRow("SELECT * FROM {$this->db->table('services')} WHERE id = %d", [$serviceId]);
    }

    /** Soft delete (tickets keep a valid service reference). */
    public function deleteService(int $providerId, int $serviceId, Actor $actor): void
    {
        $this->assertOwnerOrAdmin($actor, $providerId);
        $this->db->query(
            "UPDATE {$this->db->table('services')} SET is_active = 0, updated_at = %s WHERE id = %d AND provider_id = %d",
            [$this->clock->utcString(), $serviceId, $providerId]
        );
    }

    // ---------------------------------------------------------------- reads

    public function get(int $id): array
    {
        $p = $this->db->getRow("SELECT * FROM {$this->db->table('providers')} WHERE id = %d", [$id]);
        if (!$p) {
            throw new QueueException('provider_not_found', 404);
        }
        return $p;
    }

    /** Public lookup: blocked providers do not exist for the public. */
    public function getPublicBySlug(string $slug): array
    {
        $p = $this->db->getRow(
            "SELECT * FROM {$this->db->table('providers')} WHERE slug = %s AND status <> 'blocked'",
            [strtolower($slug)]
        );
        if (!$p) {
            throw new QueueException('provider_not_found', 404);
        }
        return $p;
    }

    public function byUserId(int $userId): ?array
    {
        return $this->db->getRow("SELECT * FROM {$this->db->table('providers')} WHERE user_id = %d", [$userId]);
    }

    public function search(array $filters, int $page = 1, int $perPage = 20): array
    {
        $p = $this->db->table('providers');
        $where = "p.status = 'active'";
        $params = [];
        if (!empty($filters['category'])) {
            $where .= ' AND c.slug = %s';
            $params[] = (string) $filters['category'];
        }
        if (!empty($filters['city_id'])) {
            $where .= ' AND p.city_id = %d';
            $params[] = (int) $filters['city_id'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr((string) $filters['q'], 0, 60)) . '%';
            $where .= " AND (p.name_ar LIKE %s ESCAPE '!' OR p.name_en LIKE %s ESCAPE '!')";
            $params[] = $like;
            $params[] = $like;
        }
        $perPage = max(1, min(50, $perPage));
        $params[] = $perPage;
        $params[] = (max(1, $page) - 1) * $perPage;
        return $this->db->getResults(
            "SELECT p.id, p.slug, p.name_ar, p.name_en, p.address, p.logo_url, p.cover_url, p.queue_open,
                    p.rating_avg, p.rating_count, c.slug category, c.name_ar category_ar, c.name_en category_en, c.icon category_icon,
                    ci.name_ar city_ar, ci.name_en city_en,
                    (SELECT COUNT(*) FROM {$this->db->table('tickets')} t WHERE t.provider_id = p.id AND t.status = 'waiting') waiting
             FROM $p p
             LEFT JOIN {$this->db->table('categories')} c ON c.id = p.category_id
             LEFT JOIN {$this->db->table('cities')} ci ON ci.id = p.city_id
             WHERE $where ORDER BY p.queue_open DESC, p.rating_avg DESC, p.id DESC LIMIT %d OFFSET %d",
            $params
        );
    }

    public function listAll(int $page = 1, int $perPage = 30, ?string $status = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $where = $status ? 'WHERE p.status = %s' : '';
        $params = $status ? [$status] : [];
        $params[] = $perPage;
        $params[] = (max(1, $page) - 1) * $perPage;
        return $this->db->getResults(
            "SELECT p.*, a.granted_total + a.purchased_total + a.adjusted_total - a.reserved - a.consumed AS available,
                    a.reserved, a.consumed
             FROM {$this->db->table('providers')} p
             LEFT JOIN {$this->db->table('credit_accounts')} a ON a.provider_id = p.id
             $where ORDER BY p.id DESC LIMIT %d OFFSET %d",
            $params
        );
    }

    // ---------------------------------------------------------------- validation

    private function validSlug(string $slug, ?int $ignoreId = null): string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])$/', $slug) || in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new QueueException('invalid_input', 422, ['field' => 'slug']);
        }
        $taken = $this->db->getVar(
            "SELECT id FROM {$this->db->table('providers')} WHERE slug = %s" . ($ignoreId ? ' AND id <> %d' : ''),
            $ignoreId ? [$slug, $ignoreId] : [$slug]
        );
        if ($taken) {
            throw new QueueException('slug_taken', 409);
        }
        return $slug;
    }

    /** A provider can only be linked to an existing provider-role account that is not linked elsewhere. */
    private function assertLinkableUser(int $userId, ?int $providerId): int
    {
        $role = $this->db->getVar('SELECT role FROM qp_users WHERE id = %d', [$userId]);
        if ($role !== 'provider') {
            throw new QueueException('invalid_input', 422, ['field' => 'user_id']);
        }
        $other = $this->db->getVar('SELECT id FROM qp_providers WHERE user_id = %d', [$userId]);
        if ($other && (int) $other !== (int) $providerId) {
            throw new QueueException('user_exists', 409);
        }
        return $userId;
    }

    private function validTimezone(string $tz): void
    {
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new QueueException('invalid_input', 422, ['field' => 'timezone']);
        }
    }

    private function assertAdmin(Actor $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new QueueException('forbidden', 403);
        }
    }

    private function assertOwnerOrAdmin(Actor $actor, int $providerId): void
    {
        if ($actor->isAdmin()) {
            return;
        }
        if ($actor->type !== Actor::PROVIDER || $actor->providerId !== $providerId) {
            throw new QueueException('forbidden', 403);
        }
    }
}
