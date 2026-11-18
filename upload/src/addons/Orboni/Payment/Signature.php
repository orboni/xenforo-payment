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
 * `Webhook-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, the
 * scheme Stripe uses. After a secret is rolled Orboni signs with both for a
 * while, so any `v1` may match.
 */
class Signature
{
    /** Seconds a signature stays good: an old event replayed later is refused. */
    const TOLERANCE = 300;

    public static function verify(string $secret, string $body, string $header, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair, " \t"), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]][] = $kv[1];
            }
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $now = $now === null ? time() : $now;
        if ($timestamp <= 0 || abs($now - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach (isset($parts['v1']) ? $parts['v1'] : [] as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }
}
