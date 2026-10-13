<?php
namespace QueuePro\Domain;

defined('QP_APP') || exit;

/**
 * Ticket lifecycle.
 *
 *   waiting ──call──▶ called ──start──▶ in_service ──complete──▶ completed
 *      │  ▲             │  │                  │
 *      │  └───delay─────┘  └──no_show / cancel┴──▶ no_show / cancelled
 *      └──────────── cancel / no_show / end-of-day(expired)
 *
 * "Delayed" is an action, not a resting state: the ticket goes back to `waiting`
 * at the end of the queue with delayed_count = 1 (allowed once). The UI shows it
 * with a "Delayed" badge. This keeps "people ahead of me" a single indexed COUNT.
 */
final class TicketStatus
{
    public const WAITING    = 'waiting';
    public const CALLED     = 'called';
    public const IN_SERVICE = 'in_service';
    public const COMPLETED  = 'completed';
    public const CANCELLED  = 'cancelled';
    public const NO_SHOW    = 'no_show';
    public const EXPIRED    = 'expired';

    public const ACTIVE = [self::WAITING, self::CALLED, self::IN_SERVICE];

    /** action => [allowed from-states, resulting state] */
    public const TRANSITIONS = [
        'call'     => [[self::WAITING], self::CALLED],
        'start'    => [[self::WAITING, self::CALLED], self::IN_SERVICE],
        'complete' => [[self::CALLED, self::IN_SERVICE], self::COMPLETED],
        'delay'    => [[self::WAITING, self::CALLED], self::WAITING],
        'no_show'  => [[self::WAITING, self::CALLED], self::NO_SHOW],
        'cancel'   => [[self::WAITING, self::CALLED, self::IN_SERVICE], self::CANCELLED],
    ];

    /** Customers may only cancel before service starts. */
    public const CUSTOMER_CANCELLABLE = [self::WAITING, self::CALLED];

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }
}
