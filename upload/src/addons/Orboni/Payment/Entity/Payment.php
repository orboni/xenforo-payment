<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A purchase request paid with Orboni (`xf_orboni_payment`): its checkout
 * session, what the session was last seen as, and whether the purchase is
 * granted. XenForo's own `xf_purchase_request` has no date to find the open
 * ones by.
 *
 * COLUMNS
 *
 * @property string $request_key
 * @property string $session_id
 * @property int $payment_profile_id
 * @property int $created_date
 * @property int $expires_date
 * @property int $checked_date
 * @property string $state
 * @property int $granted
 * @property int $changes
 * @property string $return_url
 */
class Payment extends Entity
{
    /** Not granted yet. */
    const PENDING = 0;

    /** Paid, and the purchase granted. */
    const GRANTED = 1;

    /** Granted, then taken back: money that paid it left the chain. */
    const TAKEN_BACK = 2;

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_orboni_payment';
        $structure->shortName = 'Orboni\Payment:Payment';
        $structure->primaryKey = 'request_key';
        $structure->columns = [
            'request_key' => ['type' => self::BINARY, 'maxLength' => 32, 'required' => true],
            'session_id' => ['type' => self::BINARY, 'maxLength' => 100, 'required' => true],
            'payment_profile_id' => ['type' => self::UINT, 'required' => true],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'expires_date' => ['type' => self::UINT, 'default' => 0],
            'checked_date' => ['type' => self::UINT, 'default' => 0],
            'state' => ['type' => self::BINARY, 'maxLength' => 50, 'default' => ''],
            'granted' => ['type' => self::UINT, 'default' => self::PENDING, 'allowedValues' => [self::PENDING, self::GRANTED, self::TAKEN_BACK]],
            'changes' => ['type' => self::UINT, 'default' => 0, 'max' => 65535],
            'return_url' => ['type' => self::STR, 'default' => ''],
        ];

        return $structure;
    }
}
