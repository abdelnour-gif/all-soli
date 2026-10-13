<?php
namespace QueuePro\Http;

use QueuePro\Container;
use QueuePro\Core\App;
use QueuePro\Core\Auth;
use QueuePro\Core\Cache;
use QueuePro\Core\Request;
use QueuePro\Core\Upload;
use QueuePro\Services\UserService;
use QueuePro\Support\Actor;
use QueuePro\Support\Messages;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

defined('QP_APP') || exit;

/**
 * JSON API — base path /api
 *
 * Public (no account):
 *   GET  /providers                       browse (category, city_id, q, page)
 *   GET  /providers/{slug}                profile + services
 *   GET  /providers/{slug}/live           light live status (cached ~5 s)
 *   GET  /providers/{slug}/reviews
 *   POST /providers/{slug}/join           take a number   [Idempotency-Key header]
 *   POST /providers/{slug}/otp            send SMS code (join | recover)
 *   POST /providers/{slug}/recover        new link for a lost ticket (phone + code)
 *   GET  /tickets/{token}                 my ticket (polled every 5–10 s)
 *   POST /tickets/{token}/cancel | /rate
 *
 * Provider (session cookie + X-CSRF-Token header):   /me, /me/*
 * Super Admin (session cookie + X-CSRF-Token header): /admin/*
 */
final class ApiController
{
    private Container $c;
    private Auth $auth;
    private Request $r;

    public function __construct(Container $c, Auth $auth)
    {
        $this->c = $c;
        $this->auth = $auth;
    }

    private function routes(): array
    {
        $slug = '(?P<slug>[a-z0-9-]{3,60})';
        $token = '(?P<token>[A-Za-z0-9_-]{16,64})';
        return [
            ['GET', '/providers', 'browse', 'public'],
            ['GET', "/providers/$slug", 'providerProfile', 'public'],
            ['GET', "/providers/$slug/live", 'live', 'public'],
            ['POST', "/providers/$slug/join", 'join', 'public'],
            ['GET', "/tickets/$token", 'ticket', 'public'],
            ['POST', "/tickets/$token/cancel", 'cancelTicket', 'public'],
            ['POST', "/tickets/$token/rate", 'rateTicket', 'public'],
            ['POST', "/providers/$slug/otp", 'requestOtp', 'public'],
            ['POST', "/providers/$slug/recover", 'recover', 'public'],
            ['GET', "/providers/$slug/reviews", 'reviews', 'public'],
            ['GET', '/meta', 'meta', 'public'],

            ['GET', '/me', 'me', 'provider'],
            ['GET', '/me/live', 'meLive', 'provider'],
            ['POST', '/me/queue', 'setQueue', 'provider'],
            ['POST', '/me/call-next', 'callNext', 'provider'],
            ['POST', '/me/tickets/(?P<id>\d+)/(?P<action>call|start|complete|delay|no_show|cancel)', 'ticketAction', 'provider'],
            ['POST', '/me/walkin', 'walkIn', 'provider'],
            ['GET', '/me/services', 'myServices', 'provider'],
            ['POST', '/me/services', 'saveService', 'provider'],
            ['POST', '/me/services/(?P<id>\d+)', 'saveService', 'provider'],
            ['DELETE', '/me/services/(?P<id>\d+)', 'deleteService', 'provider'],
            ['POST', '/me/profile', 'updateProfile', 'provider'],
            ['POST', '/me/media', 'uploadMedia', 'provider'],
            ['POST', '/me/password', 'changePassword', 'provider'],

            ['GET', '/admin/stats', 'adminStats', 'admin'],
            ['GET', '/admin/providers', 'adminProviders', 'admin'],
            ['POST', '/admin/providers', 'adminCreateProvider', 'admin'],
            ['GET', '/admin/providers/(?P<id>\d+)', 'adminProvider', 'admin'],
            ['POST', '/admin/providers/(?P<id>\d+)', 'adminUpdateProvider', 'admin'],
            ['POST', '/admin/providers/(?P<id>\d+)/status', 'adminSetStatus', 'admin'],
            ['POST', '/admin/providers/(?P<id>\d+)/credits', 'adminCredits', 'admin'],
            ['GET', '/admin/providers/(?P<id>\d+)/ledger', 'adminLedger', 'admin'],
            ['GET', '/admin/providers/(?P<id>\d+)/queue', 'adminQueue', 'admin'],
            ['POST', '/admin/providers/(?P<id>\d+)/password', 'adminResetPassword', 'admin'],
            ['POST', '/admin/providers/(?P<id>\d+)/login', 'adminCreateLogin', 'admin'],
            ['POST', '/admin/providers/(?P<id>\d+)/media', 'adminUploadMedia', 'admin'],
            ['GET', '/admin/packages', 'adminPackages', 'admin'],
            ['POST', '/admin/packages', 'adminSavePackage', 'admin'],
            ['GET', '/admin/audit', 'adminAudit', 'admin'],
            ['GET', '/admin/meta', 'adminMeta', 'admin'],
            ['GET', '/admin/tickets', 'adminTickets', 'admin'],
            ['GET', '/admin/notifications', 'adminNotifications', 'admin'],
            ['POST', '/admin/notifications/test', 'adminTestSms', 'admin'],
            ['GET', '/admin/ratings', 'adminRatings', 'admin'],
            ['POST', '/admin/ratings/(?P<id>\d+)', 'adminRatingVisibility', 'admin'],
            ['GET', '/admin/settings', 'adminSettings', 'admin'],
            ['POST', '/admin/settings', 'adminSaveSettings', 'admin'],
            ['POST', '/admin/password', 'changePassword', 'admin'],
            ['GET', '/admin/health', 'adminHealth', 'admin'],
            ['POST', '/admin/backup', 'adminBackup', 'admin'],
        ];
    }

    /** Entry point: $path is relative to /api (e.g. "/tickets/abc"). */
    public function dispatch(Request $r, string $path): void
    {
        $this->r = $r;
        $method = $r->method === 'HEAD' ? 'GET' : $r->method;
        $allowed = false;
        foreach ($this->routes() as [$m, $pattern, $handler, $access]) {
            if (!preg_match('#^' . $pattern . '/?$#', $path, $mm)) {
                continue;
            }
            $allowed = true;
            if ($m !== $method) {
                continue;
            }
            foreach ($mm as $k => $v) {
                if (is_string($k)) {
                    $r->params[$k] = $v;
                }
            }
            $this->run($handler, $access, $method);
            return;
        }
        $this->send(new JsonResponse(['code' => $allowed ? 'method_not_allowed' : 'not_found', 'message' => Messages::get('not_found', $this->lang())], $allowed ? 405 : 404));
    }

    private function run(string $handler, string $access, string $method): void
    {
        try {
            $write = $method !== 'GET';
            if ($write && !$this->sameOrigin()) {
                throw new QueueException('forbidden', 403);
            }
            if ($access !== 'public') {
                $u = $this->auth->user();
                if (!$u) {
                    throw new QueueException('unauthorized', 401);
                }
                if ($u['role'] !== $access) {
                    throw new QueueException('forbidden', 403);
                }
                if ($write && !$this->auth->checkCsrf($this->r->header('x-csrf-token'))) {
                    throw new QueueException('csrf', 403);
                }
                if ($handler !== 'changePassword') {
                    $this->auth->release(); // do not hold the session lock while working
                }
            } elseif ($write && !$this->r->isJson()) {
                // Public writes accept JSON only → a plain cross-site HTML form cannot submit them.
                throw new QueueException('invalid_input', 415);
            }
            $data = $this->$handler($this->r);
            $this->send($data instanceof JsonResponse ? $data : new JsonResponse($data, 200));
        } catch (QueueException $e) {
            $this->send(new JsonResponse(['code' => $e->errorCode(), 'message' => Messages::get($e->errorCode(), $this->lang())] + $e->context(), $e->httpStatus()));
        } catch (\Throwable $e) {
            App::log($e);
            $this->send(new JsonResponse(['code' => 'server_error', 'message' => Messages::get('server_error', $this->lang())], 500));
        }
    }

    private function send(JsonResponse $res): void
    {
        $res->send();
    }

    /** Browsers send Origin on cross-site writes; it must be our own host. */
    private function sameOrigin(): bool
    {
        $origin = $this->r->header('origin');
        if ($origin === '' || $origin === 'null') {
            return $origin === '';
        }
        $o = parse_url($origin);
        $b = parse_url(App::baseUrl());
        return isset($o['host'], $b['host']) && strtolower($o['host']) === strtolower($b['host'])
            && (int) ($o['port'] ?? 0) === (int) ($b['port'] ?? 0);
    }

    private function lang(): string
    {
        $l = (string) ($this->r->query['lang'] ?? '') ?: substr($this->r->header('accept-language'), 0, 2);
        return $l === 'en' ? 'en' : 'ar';
    }

    private function ipHash(): ?string
    {
        return Security::hashIp($this->r->ip(App::trustProxy()));
    }

    /** Provider actor for the logged-in provider account. */
    private function providerActor(): Actor
    {
        $user = $this->auth->user();
        $p = $user ? $this->c->providers->byUserId((int) $user['id']) : null;
        if (!$p) {
            throw new QueueException('forbidden', 403);
        }
        return Actor::provider((int) $user['id'], (int) $p['id'], $this->ipHash());
    }

    private function adminActor(): Actor
    {
        return Actor::admin((int) $this->auth->user()['id'], $this->ipHash());
    }

    private function flushLive(int $providerId): void
    {
        Cache::delete('live_' . $providerId);
    }

    private function noStore(array $data): JsonResponse
    {
        $res = new JsonResponse($data, 200);
        $res->header('Cache-Control', 'no-store, private');
        return $res;
    }

    // ================================================================ public

    private function meta(Request $r): array
    {
        $db = $this->c->db;
        return [
            'categories' => $db->getResults("SELECT slug, name_ar, name_en, icon FROM {$db->table('categories')} WHERE is_active = 1 ORDER BY sort_order"),
            'cities'     => $db->getResults("SELECT id, name_ar, name_en FROM {$db->table('cities')} WHERE is_active = 1 AND country_code = 'DZ' ORDER BY name_en"),
            'poll_interval' => $this->c->settings->int('poll_interval_seconds'),
        ];
    }

    private function browse(Request $r): array
    {
        return $this->c->providers->search([
            'category' => sanitize_key((string) $r->param('category')),
            'city_id'  => (int) $r->param('city_id'),
            'q'        => sanitize_text_field((string) $r->param('q')),
        ], max(1, (int) $r->param('page')));
    }

    private function providerProfile(Request $r): array
    {
        $p = $this->c->providers->getPublicBySlug($r->params['slug']);
        return [
            'slug' => $p['slug'], 'name_ar' => $p['name_ar'], 'name_en' => $p['name_en'],
            'description_ar' => $p['description_ar'], 'description_en' => $p['description_en'],
            'address' => $p['address'], 'phone' => $p['phone'], 'logo_url' => $p['logo_url'], 'cover_url' => $p['cover_url'],
            'maps_url' => $p['maps_url'],
            'currency' => $p['currency_code'], 'rating_avg' => (float) $p['rating_avg'], 'rating_count' => (int) $p['rating_count'],
            'services' => $this->c->providers->services((int) $p['id']),
            'live' => $this->liveData((int) $p['id']),
            'otp_required' => $this->c->queue->otpRequired(),
            'recovery_available' => $this->c->otp->available(),
        ];
    }

    private function reviews(Request $r): array
    {
        $p = $this->c->providers->getPublicBySlug($r->params['slug']);
        return [
            'average' => (float) $p['rating_avg'], 'count' => (int) $p['rating_count'],
            'distribution' => $this->c->ratings->distribution((int) $p['id']),
            'items' => $this->c->ratings->listPublic((int) $p['id'], 30),
        ];
    }

    private function requestOtp(Request $r): JsonResponse
    {
        $p = $this->c->providers->getPublicBySlug($r->params['slug']);
        $purpose = $r->param('purpose') === 'recover' ? 'recover' : 'join';
        return $this->noStore($this->c->queue->requestOtp((int) $p['id'], (string) $r->param('phone'), $purpose, $this->lang(), Actor::customer($this->ipHash())));
    }

    private function recover(Request $r): JsonResponse
    {
        $p = $this->c->providers->getPublicBySlug($r->params['slug']);
        $this->c->limiter->hit('recover-ip:' . ($this->ipHash() ?? 'x'), 10, 3600);
        $res = $this->c->queue->recoverTicket((int) $p['id'], (string) $r->param('phone'), (string) $r->param('code'), Actor::customer($this->ipHash()));
        $res['ticket_url'] = url('/t/' . $res['token'] . '/');
        return $this->noStore($res);
    }

    private function rateTicket(Request $r): JsonResponse
    {
        $this->c->limiter->hit('rate-ip:' . ($this->ipHash() ?? 'x'), 20, 3600);
        $this->c->ratings->rate($r->params['token'], (int) $r->param('stars'), $r->param('comment') !== null ? (string) $r->param('comment') : null);
        return $this->noStore($this->c->queue->ticketByToken($r->params['token']));
    }

    private function live(Request $r): JsonResponse
    {
        $p = $this->c->providers->getPublicBySlug($r->params['slug']);
        $res = new JsonResponse($this->liveData((int) $p['id']), 200);
        $res->header('Cache-Control', 'public, max-age=5');
        return $res;
    }

    /** Short server-side cache so hundreds of polling phones cost a handful of queries. */
    private function liveData(int $providerId): array
    {
        $key = 'live_' . $providerId;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $data = $this->c->queue->liveStatus($providerId);
        Cache::set($key, $data, 5);
        return $data;
    }

    private function join(Request $r): JsonResponse
    {
        $p = $this->c->providers->getPublicBySlug($r->params['slug']);
        if ((string) $r->param('website') !== '') {
            throw new QueueException('invalid_input', 422); // honeypot field filled → bot
        }
        $result = $this->c->queue->join((int) $p['id'], [
            'phone'      => (string) $r->param('phone'),
            'name'       => $r->param('name'),
            'service_id' => (int) $r->param('service_id'),
            'otp_code'   => $r->param('otp_code') !== null ? (string) $r->param('otp_code') : null,
            'lang'       => $this->lang(),
        ], Actor::customer($this->ipHash()), $r->header('idempotency-key') ?: null);
        $this->flushLive((int) $p['id']);
        $result['ticket_url'] = url('/t/' . $result['token'] . '/');
        $res = $this->noStore($result);
        $res->status(empty($result['replayed']) ? 201 : 200);
        return $res;
    }

    private function ticket(Request $r): JsonResponse
    {
        return $this->noStore($this->c->queue->ticketByToken($r->params['token']));
    }

    private function cancelTicket(Request $r): JsonResponse
    {
        $view = $this->c->queue->cancelByToken($r->params['token'], Actor::customer($this->ipHash()));
        $this->flushLive((int) $this->c->providers->getPublicBySlug($view['provider']['slug'])['id']);
        return $this->noStore($view);
    }

    // ================================================================ provider

    private function me(Request $r): JsonResponse
    {
        $a = $this->providerActor();
        $p = $this->c->providers->get($a->providerId);
        $balance = $this->c->credits->balance($a->providerId);
        return $this->noStore([
            'provider' => $p,
            'public_url' => provider_url($p['slug']),
            'currency_symbol' => $this->c->db->getVar("SELECT symbol FROM {$this->c->db->table('currencies')} WHERE code = %s", [$p['currency_code']]) ?: $p['currency_code'],
            'balance' => $balance + ['low' => $balance['available'] <= $this->c->settings->int('low_credit_threshold')],
            'queue' => $this->c->queue->providerQueue($a->providerId, $a),
            'services' => $this->c->providers->services($a->providerId, false),
        ]);
    }

    /** Polled every few seconds by the dashboard: queue + balance only. */
    private function meLive(Request $r): JsonResponse
    {
        $a = $this->providerActor();
        $balance = $this->c->credits->balance($a->providerId);
        $p = $this->c->providers->get($a->providerId);
        return $this->noStore([
            'queue_open' => (bool) (int) $p['queue_open'],
            'status'     => $p['status'],
            'balance'    => $balance + ['low' => $balance['available'] <= $this->c->settings->int('low_credit_threshold')],
            'queue'      => $this->c->queue->providerQueue($a->providerId, $a),
        ]);
    }

    private function setQueue(Request $r): array
    {
        $a = $this->providerActor();
        $this->c->queue->setQueueOpen($a->providerId, (bool) $r->param('open'), $a);
        $this->flushLive($a->providerId);
        return ['open' => (bool) $r->param('open')];
    }

    private function callNext(Request $r): array
    {
        $a = $this->providerActor();
        $t = $this->c->queue->callNext($a->providerId, $a);
        $this->flushLive($a->providerId);
        return ['ticket' => $t];
    }

    private function ticketAction(Request $r): array
    {
        $a = $this->providerActor();
        $t = $this->c->queue->transition($a->providerId, (int) $r->params['id'], (string) $r->params['action'], $a);
        $this->flushLive($a->providerId);
        return ['ticket' => $t, 'balance' => $this->c->credits->balance($a->providerId)];
    }

    private function walkIn(Request $r): array
    {
        $a = $this->providerActor();
        $t = $this->c->queue->addWalkIn($a->providerId, $a, $r->param('name'), (int) $r->param('service_id') ?: null);
        $this->flushLive($a->providerId);
        return ['ticket' => $t];
    }

    private function myServices(Request $r): array
    {
        return $this->c->providers->services($this->providerActor()->providerId, false);
    }

    private function saveService(Request $r): array
    {
        $a = $this->providerActor();
        return $this->c->providers->saveService($a->providerId, $r->body(), $a, isset($r->params['id']) ? (int) $r->params['id'] : null);
    }

    private function deleteService(Request $r): array
    {
        $a = $this->providerActor();
        $this->c->providers->deleteService($a->providerId, (int) $r->params['id'], $a);
        return ['deleted' => true];
    }

    private function uploadMedia(Request $r): array
    {
        $a = $this->providerActor();
        return $this->storeImage($r, $a->providerId, $a);
    }

    private function adminUploadMedia(Request $r): array
    {
        $id = (int) $r->params['id'];
        $this->c->providers->get($id);
        return $this->storeImage($r, $id, $this->adminActor());
    }

    /** Logo / cover upload (validated and re-encoded by Upload::image). */
    private function storeImage(Request $r, int $providerId, Actor $actor): array
    {
        $kind = $r->param('kind') === 'logo' ? 'logo_url' : 'cover_url';
        $file = $r->files['file'] ?? null;
        if (!is_array($file)) {
            throw new QueueException('invalid_input', 422, ['field' => 'file']);
        }
        $url = Upload::image($file, 'p' . $providerId . '-' . ($kind === 'logo_url' ? 'logo' : 'cover'));
        $this->c->providers->update($providerId, [$kind => $url], $actor);
        return ['url' => $url, 'kind' => $kind];
    }

    /** Signed-in admin or provider changes their own password. */
    private function changePassword(Request $r): array
    {
        $u = $this->auth->user();
        if (!$this->c->users->verify($u['username'], (string) $r->param('current'))) {
            throw new QueueException('login_failed', 403);
        }
        $this->c->users->setPassword((int) $u['id'], (string) $r->param('new'));
        $this->auth->refresh();
        $this->c->audit->log(new Actor($u['role'] === 'admin' ? Actor::ADMIN : Actor::PROVIDER, (int) $u['id'], null, $this->ipHash()), 'user.password_change', 'user', (int) $u['id']);
        return ['ok' => true];
    }

    private function updateProfile(Request $r): array
    {
        $a = $this->providerActor();
        return $this->c->providers->update($a->providerId, $r->body(), $a);
    }

    // ================================================================ admin

    private function adminStats(Request $r): array
    {
        return ['overview' => $this->c->stats->overview(), 'series' => $this->c->stats->completedSeries((int) ($r->param('days') ?: 30))];
    }

    private function adminProviders(Request $r): array
    {
        $status = $r->param('status');
        return $this->c->providers->listAll(max(1, (int) $r->param('page')), 30, in_array($status, ['active', 'frozen', 'blocked'], true) ? $status : null);
    }

    /**
     * Creates the provider and (optionally) its login account. The generated password
     * is returned once so the admin can hand it over (no dependency on e-mail).
     */
    private function adminCreateProvider(Request $r): array
    {
        $data = $r->body();
        $actor = $this->adminActor();
        $email = trim((string) ($data['email'] ?? ''));
        $username = trim((string) ($data['username'] ?? '')) ?: (string) ($data['slug'] ?? '');
        $withLogin = !empty($data['create_login']) || $email !== '';
        unset($data['user_id']);

        return $this->c->db->transaction(function () use ($data, $actor, $email, $username, $withLogin) {
            $credentials = null;
            if ($withLogin) {
                $acc = $this->c->users->create($username, $email ?: null, 'provider', (string) ($data['name_ar'] ?? ''));
                $data['user_id'] = $acc['id'];
                $credentials = ['login' => $username, 'email' => $email, 'password' => $acc['password'], 'url' => url('/dashboard/')];
            }
            $p = $this->c->providers->create($data, $actor);
            if (!empty($data['open_queue'])) {
                $this->c->queue->setQueueOpen((int) $p['id'], true, $actor);
                $p['queue_open'] = 1;
            }
            return ['provider' => $p, 'credentials' => $credentials];
        });
    }

    /** Adds a login to a provider created without one. */
    private function adminCreateLogin(Request $r): array
    {
        $id = (int) $r->params['id'];
        $p = $this->c->providers->get($id);
        if ($p['user_id']) {
            throw new QueueException('user_exists', 409);
        }
        $username = trim((string) $r->param('username')) ?: $p['slug'];
        $email = trim((string) $r->param('email'));
        $actor = $this->adminActor();
        return $this->c->db->transaction(function () use ($id, $username, $email, $p, $actor) {
            $acc = $this->c->users->create($username, $email ?: null, 'provider', $p['name_ar']);
            $this->c->providers->update($id, ['user_id' => $acc['id']], $actor);
            $this->c->audit->log($actor, 'provider.login_created', 'provider', $id);
            return ['login' => $username, 'email' => $email, 'password' => $acc['password'], 'url' => url('/dashboard/')];
        });
    }

    private function adminResetPassword(Request $r): array
    {
        $p = $this->c->providers->get((int) $r->params['id']);
        $u = $p['user_id'] ? $this->c->users->find((int) $p['user_id']) : null;
        if (!$u) {
            throw new QueueException('invalid_input', 422);
        }
        if ($u['role'] !== 'provider') {
            throw new QueueException('forbidden', 403); // never reset an admin's password from here
        }
        $pass = UserService::generatePassword();
        $this->c->users->setPassword((int) $u['id'], $pass);
        $this->c->audit->log($this->adminActor(), 'provider.password_reset', 'provider', (int) $p['id']);
        return ['login' => $u['username'], 'email' => $u['email'], 'password' => $pass, 'url' => url('/dashboard/')];
    }

    private function adminProvider(Request $r): array
    {
        $id = (int) $r->params['id'];
        $p = $this->c->providers->get($id);
        $u = $p['user_id'] ? $this->c->users->find((int) $p['user_id']) : null;
        return [
            'provider' => $p,
            'login' => $u ? ['login' => $u['username'], 'email' => $u['email'], 'last_login_at' => $u['last_login_at']] : null,
            'balance' => $this->c->credits->balance($id),
            'services' => $this->c->providers->services($id, false),
            'public_url' => provider_url($p['slug']),
            'today' => $this->c->queue->providerQueue($id, $this->adminActor()),
        ];
    }

    private function adminMeta(Request $r): array
    {
        $db = $this->c->db;
        return [
            'categories' => $db->getResults("SELECT id, slug, name_ar, name_en FROM {$db->table('categories')} WHERE is_active = 1 ORDER BY sort_order"),
            'cities' => $db->getResults("SELECT id, name_ar, name_en FROM {$db->table('cities')} WHERE is_active = 1 ORDER BY id"),
            'packages' => $db->getResults("SELECT * FROM {$db->table('packages')} ORDER BY sort_order, id"),
            'timezones' => ['Africa/Algiers', 'Africa/Casablanca', 'Africa/Tunis', 'Asia/Riyadh', 'Europe/Paris'],
        ];
    }

    private function adminTickets(Request $r): array
    {
        return $this->c->stats->recentTickets([
            'provider_id' => (int) $r->param('provider_id'),
            'status' => sanitize_key((string) $r->param('status')),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->param('date')) ? (string) $r->param('date') : null,
            'phone' => sanitize_text_field((string) $r->param('phone')),
        ], 50, max(0, (int) $r->param('offset')));
    }

    private function adminNotifications(Request $r): array
    {
        $st = $r->param('status');
        return $this->c->notify->recent(100, in_array($st, ['pending', 'sending', 'sent', 'failed'], true) ? $st : null);
    }

    private function adminTestSms(Request $r): array
    {
        $phone = Security::normalizePhone((string) $r->param('phone'));
        if (!$phone) {
            throw new QueueException('invalid_phone', 422);
        }
        $res = (new \QueuePro\Notifications\Dispatcher($this->c))->test($phone, ($this->c->settings->string('brand_name') ?: 'QueuePro') . ': test message ✓');
        $this->c->audit->log($this->adminActor(), 'sms.test', 'setting', null, ['ok' => $res->ok, 'error' => $res->error]);
        return ['ok' => $res->ok, 'error' => $res->error, 'id' => $res->id, 'note' => $res->note];
    }

    private function adminRatings(Request $r): array
    {
        return $this->c->ratings->listAdmin(100, (int) $r->param('provider_id') ?: null);
    }

    private function adminRatingVisibility(Request $r): array
    {
        $this->c->ratings->setVisible((int) $r->params['id'], (bool) $r->param('visible'), $this->adminActor());
        return ['ok' => true];
    }

    private function adminUpdateProvider(Request $r): array
    {
        return $this->c->providers->update((int) $r->params['id'], $r->body(), $this->adminActor());
    }

    private function adminSetStatus(Request $r): array
    {
        $p = $this->c->providers->setStatus((int) $r->params['id'], (string) $r->param('status'), $this->adminActor(), sanitize_text_field((string) $r->param('reason')));
        $this->flushLive((int) $r->params['id']);
        return $p;
    }

    private function adminCredits(Request $r): array
    {
        $id = (int) $r->params['id'];
        $this->c->providers->get($id);
        $actor = $this->adminActor();
        if ($r->param('package_id')) {
            $b = $this->c->credits->addPackage($id, (int) $r->param('package_id'), sanitize_text_field((string) $r->param('payment_method')), sanitize_text_field((string) $r->param('note')), $actor);
        } else {
            $b = $this->c->credits->addCredits($id, (string) $r->param('type'), (int) $r->param('amount'), $actor, [
                'reason' => sanitize_text_field((string) $r->param('reason')),
                'payment_method' => sanitize_text_field((string) $r->param('payment_method')) ?: null,
            ]);
        }
        $this->flushLive($id);
        return $b;
    }

    private function adminLedger(Request $r): array
    {
        return $this->c->credits->ledger((int) $r->params['id'], (int) ($r->param('limit') ?: 50), (int) $r->param('before'));
    }

    private function adminQueue(Request $r): array
    {
        return $this->c->queue->providerQueue((int) $r->params['id'], $this->adminActor());
    }

    private function adminPackages(Request $r): array
    {
        $db = $this->c->db;
        return $db->getResults("SELECT * FROM {$db->table('packages')} ORDER BY sort_order, id");
    }

    private function adminSavePackage(Request $r): array
    {
        $db = $this->c->db;
        $row = [
            'name_ar' => sanitize_text_field((string) $r->param('name_ar')),
            'name_en' => sanitize_text_field((string) $r->param('name_en')) ?: null,
            'credits' => max(1, (int) $r->param('credits')),
            'price' => max(0, (float) $r->param('price')),
            'currency_code' => strtoupper(substr(sanitize_text_field((string) ($r->param('currency_code') ?: 'DZD')), 0, 3)),
            'is_active' => $r->param('is_active') === null ? 1 : (int) (bool) $r->param('is_active'),
            'sort_order' => (int) $r->param('sort_order'),
        ];
        if ($row['name_ar'] === '') {
            throw new QueueException('invalid_name', 422);
        }
        if ($id = (int) $r->param('id')) {
            $db->update($db->table('packages'), $row, ['id' => $id]);
        } else {
            $id = $db->insert($db->table('packages'), $row + ['created_at' => $this->c->clock->utcString()]);
        }
        $this->c->audit->log($this->adminActor(), 'package.save', 'package', $id, $row);
        return $db->getRow("SELECT * FROM {$db->table('packages')} WHERE id = %d", [$id]);
    }

    private function adminAudit(Request $r): array
    {
        return $this->c->audit->recent((int) ($r->param('limit') ?: 50), (int) $r->param('offset'),
            $r->param('target_type') ? sanitize_key((string) $r->param('target_type')) : null,
            (int) $r->param('target_id') ?: null);
    }

    private function adminSettings(Request $r): array
    {
        return $this->c->settings->forDisplay();
    }

    private function adminSaveSettings(Request $r): array
    {
        $actor = $this->adminActor();
        foreach ((array) $r->param('settings') as $k => $v) {
            $this->c->settings->set(sanitize_key((string) $k), $v, $actor, $this->c->audit);
        }
        return $this->c->settings->forDisplay();
    }

    // ================================================================ system

    private function adminHealth(Request $r): array
    {
        $db = $this->c->db;
        $root = QP_ROOT;
        $dbFile = $db->isSqlite() ? (string) (App::config()['db']['path'] ?? '') : '';
        $probe = $root . '/storage/.probe';
        $secret = bin2hex(random_bytes(8));
        @file_put_contents($probe, $secret);
        $res = \QueuePro\Core\HttpClient::request(url('/storage/.probe'), ['timeout' => 4]);
        @unlink($probe);
        $exposed = $res['status'] === 200 && trim($res['body']) === $secret;
        $maint = Cache::get('maint_at');
        return [
            'version' => QP_VERSION,
            'php' => PHP_VERSION,
            'db' => ['driver' => $db->driver(), 'version' => $db->version(), 'size' => $dbFile && is_file($dbFile) ? filesize($dbFile) : null],
            'storage_protected' => $res['status'] === 0 ? null : !$exposed,
            'storage_writable' => is_writable($root . '/storage'),
            'uploads_writable' => is_writable($root . '/uploads'),
            'https' => stripos(App::baseUrl(), 'https://') === 0,
            'extensions' => ['gd' => function_exists('imagecreatefromstring'), 'curl' => function_exists('curl_init'), 'apcu' => function_exists('apcu_fetch'), 'mbstring' => function_exists('mb_strlen')],
            'maintenance_last_run' => $maint ? gmdate('Y-m-d H:i:s', (int) $maint) : null,
            'pending_messages' => (int) $db->getVar("SELECT COUNT(*) FROM qp_notifications WHERE status IN ('pending','sending')"),
            'failed_messages' => (int) $db->getVar("SELECT COUNT(*) FROM qp_notifications WHERE status = 'failed'"),
            'sms_driver' => $this->c->settings->string('sms_driver'),
            'disk_free' => @disk_free_space($root) ?: null,
            'books_ok' => $this->booksBalanced(),
        ];
    }

    /** Every provider: account totals == sum of ticket credit states == last ledger line. */
    private function booksBalanced(): bool
    {
        $bad = $this->c->db->getVar(
            "SELECT COUNT(*) FROM qp_credit_accounts a
             WHERE a.reserved <> (SELECT COUNT(*) FROM qp_tickets t WHERE t.provider_id = a.provider_id AND t.credit_state = 'reserved')
                OR a.consumed <> (SELECT COUNT(*) FROM qp_tickets t WHERE t.provider_id = a.provider_id AND t.credit_state = 'consumed')
                OR a.granted_total + a.purchased_total + a.adjusted_total - a.reserved - a.consumed
                   <> COALESCE((SELECT l.available_after FROM qp_credit_ledger l WHERE l.provider_id = a.provider_id ORDER BY l.id DESC LIMIT 1), 0)"
        );
        return (int) $bad === 0;
    }

    /** Download a full backup: the SQLite database file, or a JSON export for MySQL. */
    private function adminBackup(Request $r): void
    {
        $db = $this->c->db;
        $stamp = gmdate('Ymd-His');
        $this->c->audit->log($this->adminActor(), 'system.backup', 'system', null);
        if ($db->isSqlite()) {
            $file = QP_ROOT . '/storage/backups/queuepro-' . $stamp . '-' . bin2hex(random_bytes(4)) . '.sqlite';
            $db->exec("VACUUM INTO '" . str_replace("'", "''", $file) . "'");
            $name = 'queuepro-backup-' . $stamp . '.sqlite';
            $type = 'application/vnd.sqlite3';
        } else {
            $file = QP_ROOT . '/storage/backups/queuepro-' . $stamp . '-' . bin2hex(random_bytes(4)) . '.json';
            $out = ['app' => 'QueuePro', 'version' => QP_VERSION, 'created_at' => gmdate('c'), 'tables' => []];
            foreach (\QueuePro\Database\Schema::TABLES as $t) {
                $out['tables'][$t] = $db->getResults('SELECT * FROM ' . $db->table($t));
            }
            file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE));
            $name = 'queuepro-backup-' . $stamp . '.json';
            $type = 'application/json';
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
        @unlink($file);
        exit;
    }
}
