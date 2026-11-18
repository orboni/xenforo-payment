<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment\Repository;

use Orboni\Payment\Entity\Payment;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

/**
 * The purchases paid with Orboni: the ones worth reading back, a fresh read
 * of one, and a lock per purchase.
 */
class PaymentRepository extends Repository
{
    /** Seconds a request waits for another holding the same purchase. */
    const LOCK_WAIT = 5;

    /**
     * Purchases worth reading again: not granted, their page open or closed
     * less than two days ago (Orboni still sees a late payment), not read in
     * the last `$recheck` seconds. The oldest read first.
     *
     * @return Finder
     */
    public function findPaymentsToRecheck(int $recheck)
    {
        return $this->finder(Payment::class)
            ->where('granted', Payment::PENDING)
            ->where('expires_date', '>', \XF::$time - 2 * 86400)
            ->where('checked_date', '<', \XF::$time - $recheck)
            ->order('checked_date');
    }

    /**
     * The purchase as the database has it now, not as this request loaded it
     * earlier: read under its lock.
     *
     * @return Payment|null
     */
    public function getFreshPayment(string $requestKey)
    {
        $cached = $this->em->findCached(Payment::class, $requestKey);
        if ($cached) {
            $this->em->detachEntity($cached);
        }

        return $this->em->find(Payment::class, $requestKey);
    }

    /**
     * A database lock per purchase: the webhook, the member's return and the
     * cron can bring the same session in the same second. The entity system
     * has none, so MySQL's own named lock, through the database adapter.
     */
    public function lock(string $requestKey): bool
    {
        return (int) $this->db()->fetchOne('SELECT GET_LOCK(?, ?)', [$this->lockName($requestKey), self::LOCK_WAIT]) === 1;
    }

    public function unlock(string $requestKey)
    {
        $this->db()->fetchOne('SELECT RELEASE_LOCK(?)', $this->lockName($requestKey));
    }

    /** Apart from other forums on the same database server. */
    private function lockName(string $requestKey): string
    {
        $config = \XF::config('db');

        return 'orboni_'.substr(md5((isset($config['dbname']) ? $config['dbname'] : '').'|'.$requestKey), 0, 40);
    }
}
