<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment\Cron;

use Orboni\Payment\Payment\Orboni;
use Orboni\Payment\Records;
use XF\Entity\PaymentProvider;

/**
 * XenForo's cron entry "Orboni: read back open payments", every 15 minutes:
 * purchases whose payment page is open or closed less than two days ago and
 * not granted yet are read back from Orboni, in case a webhook was lost.
 */
class Recheck
{
    /** Purchases read per run. */
    const BATCH = 50;

    /** Seconds between two reads of the same purchase. */
    const RECHECK_SECONDS = 3000;

    public static function run()
    {
        $provider = \XF::em()->find(PaymentProvider::class, Orboni::PROVIDER_ID);
        $handler = $provider ? $provider->handler : null;
        if (! $handler instanceof Orboni) {
            return;
        }

        foreach (Records::open(self::RECHECK_SECONDS, self::BATCH) as $row) {
            Records::update((string) $row['request_key'], ['checked_date' => \XF::$time]);
            $handler->recheck($row);
        }
    }
}
