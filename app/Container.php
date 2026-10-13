<?php
namespace QueuePro;

use QueuePro\Database\Connection;
use QueuePro\Services\AuditLogger;
use QueuePro\Services\CreditService;
use QueuePro\Services\NotificationService;
use QueuePro\Services\OtpService;
use QueuePro\Services\RatingService;
use QueuePro\Services\ProviderService;
use QueuePro\Services\QueueService;
use QueuePro\Services\RateLimiter;
use QueuePro\Services\Settings;
use QueuePro\Services\StatsService;
use QueuePro\Services\UserService;
use QueuePro\Support\Clock;

defined('QP_APP') || exit;

/** Wires services together. Same wiring in the web app, the CLI and the test suite. */
final class Container
{
    public Connection $db;
    public Clock $clock;
    public Settings $settings;
    public AuditLogger $audit;
    public RateLimiter $limiter;
    public CreditService $credits;
    public QueueService $queue;
    public ProviderService $providers;
    public StatsService $stats;
    public NotificationService $notify;
    public OtpService $otp;
    public RatingService $ratings;
    public UserService $users;

    public function __construct(Connection $db, ?Clock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new Clock();
        $this->settings = new Settings($db);
        $this->audit = new AuditLogger($db, $this->clock);
        $this->limiter = new RateLimiter($db, $this->clock);
        $this->credits = new CreditService($db, $this->clock, $this->audit);
        $this->notify = new NotificationService($db, $this->clock, $this->settings);
        $this->otp = new OtpService($db, $this->clock, $this->settings, $this->limiter, $this->notify);
        $this->ratings = new RatingService($db, $this->clock, $this->settings, $this->audit);
        $this->queue = new QueueService($db, $this->clock, $this->credits, $this->settings, $this->limiter, $this->notify, $this->otp);
        $this->providers = new ProviderService($db, $this->clock, $this->credits, $this->settings, $this->audit);
        $this->stats = new StatsService($db, $this->clock);
        $this->users = new UserService($db);
    }
}
