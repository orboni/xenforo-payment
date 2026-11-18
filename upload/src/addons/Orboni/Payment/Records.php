<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment;

/**
 * The table `xf_orboni_payment`: one row per purchase request paid with
 * Orboni — its checkout session, what the session was last seen as, and
 * whether the purchase is granted. XenForo's own `xf_purchase_request` has no
 * date to find the open ones by.
 */
class Records
{
    const TABLE = 'xf_orboni_payment';

    /** Not granted yet. */
    const PENDING = 0;

    /** Paid, and the purchase granted. */
    const GRANTED = 1;

    /** Granted, then taken back: money that paid it left the chain. */
    const TAKEN_BACK = 2;

    /** Seconds a request waits for another holding the same purchase. */
    const LOCK_WAIT = 5;

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $requestKey)
    {
        $row = \XF::db()->fetchRow('SELECT * FROM '.self::TABLE.' WHERE request_key = ?', $requestKey);

        return is_array($row) ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function insert(array $row)
    {
        \XF::db()->insert(self::TABLE, $row, false, 'session_id = VALUES(session_id), expires_date = VALUES(expires_date)');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function update(string $requestKey, array $changes)
    {
        \XF::db()->update(self::TABLE, $changes, 'request_key = ?', $requestKey);
    }

    /**
     * Purchases worth reading again: not granted, their page open or closed
     * less than two days ago (Orboni still sees a late payment), not read in
     * the last `$recheck` seconds. The oldest read first.
     *
     * @return list<array<string, mixed>>
     */
    public static function open(int $recheck, int $limit)
    {
        $now = \XF::$time;

        return array_values(\XF::db()->fetchAll(
            'SELECT * FROM '.self::TABLE.' WHERE granted = ? AND expires_date > ? AND checked_date < ? ORDER BY checked_date LIMIT '.(int) $limit,
            [self::PENDING, $now - 2 * 86400, $now - $recheck]
        ));
    }

    /**
     * A database lock per purchase: the webhook, the member's return and the
     * cron can bring the same session in the same second.
     */
    public static function lock(string $requestKey): bool
    {
        return (int) \XF::db()->fetchOne('SELECT GET_LOCK(?, ?)', [self::lockName($requestKey), self::LOCK_WAIT]) === 1;
    }

    public static function unlock(string $requestKey)
    {
        \XF::db()->fetchOne('SELECT RELEASE_LOCK(?)', self::lockName($requestKey));
    }

    /** Apart from other forums on the same database server. */
    private static function lockName(string $requestKey): string
    {
        $config = \XF::config('db');

        return 'orboni_'.substr(md5((isset($config['dbname']) ? $config['dbname'] : '').'|'.$requestKey), 0, 40);
    }
}
