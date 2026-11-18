<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment;

use XF\Payment\CallbackState;

/**
 * What a checkout session, as Orboni shows it now, means for its purchase.
 * The webhook, the member's return and the cron all bring the session read
 * back, so the purchase follows the payment whatever arrives first, twice or
 * out of order:
 *
 * - complete + paid → granted (XenForo's "payment received": the upgrade,
 *   its alert and the receipt email);
 * - granted, then no longer paid → taken back (XenForo's "reversed"): only
 *   when money that paid it left the chain, since a complete session is
 *   otherwise final; paid again → granted again ("reinstated");
 * - on its way, part paid, held for review, expired → nothing granted, a line
 *   in the payment provider log saying so.
 */
class Sync
{
    const EXTRA = 'checkout.session.extra_received';

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    /**
     * The session made for this purchase by this forum: its id is the one
     * kept, and it names the purchase.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $session
     */
    public static function belongs(array $row, array $session): bool
    {
        $metadata = isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : [];

        return isset($session['id']) && (string) $session['id'] === (string) $row['session_id']
            && (isset($session['client_reference_id']) ? (string) $session['client_reference_id'] : '') === (string) $row['request_key']
            && (isset($metadata['xenforo_request_key']) ? (string) $metadata['xenforo_request_key'] : '') === (string) $row['request_key'];
    }

    /**
     * What to do: `result` one of CallbackState's PAYMENT_* or null for
     * nothing to change, the `transaction` id to log it under, the row's new
     * `granted`, `changes` and `state`, and a `logType` and `message` when
     * something is worth a line in the log.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $session
     * @return array{result: int|null, transaction: string, granted: int, changes: int, state: string, logType: string|null, message: string}
     */
    public static function decide(array $row, array $session, string $event = ''): array
    {
        $id = (string) $session['id'];
        $status = isset($session['status']) ? (string) $session['status'] : '';
        $payment = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
        $state = $status.':'.$payment;
        $paid = $status === 'complete' && $payment === 'paid';
        $granted = (int) $row['granted'];
        $changes = (int) $row['changes'];

        $decision = [
            'result' => null,
            'transaction' => $id,
            'granted' => $granted,
            'changes' => $changes,
            'state' => $state,
            'logType' => null,
            'message' => '',
        ];

        if ($granted === Records::GRANTED) {
            if (! $paid) {
                return array_merge($decision, [
                    'result' => CallbackState::PAYMENT_REVERSED,
                    'transaction' => $id.'-reversed-'.($changes + 1),
                    'granted' => Records::TAKEN_BACK,
                    'changes' => $changes + 1,
                    'message' => sprintf('Money that paid it left the chain (a reorganisation or a double spend); %s still counts as received. Taken back until Orboni shows it paid again.', self::received($session)),
                ]);
            }
            if ($event === self::EXTRA) {
                return array_merge($decision, [
                    'logType' => 'info',
                    'message' => sprintf('More arrived after it was paid: %s in all. Send the difference back from your wallet if the member asks.', self::received($session)),
                ]);
            }

            return $decision;
        }

        if ($paid) {
            return $granted === Records::TAKEN_BACK
                ? array_merge($decision, [
                    'result' => CallbackState::PAYMENT_REINSTATED,
                    'transaction' => $id.'-reinstated-'.($changes + 1),
                    'granted' => Records::GRANTED,
                    'changes' => $changes + 1,
                    'message' => self::paidNote($session),
                ])
                : array_merge($decision, [
                    'result' => CallbackState::PAYMENT_RECEIVED,
                    'granted' => Records::GRANTED,
                    'message' => self::paidNote($session),
                ]);
        }

        if ($state === (string) $row['state']) {
            return $decision;
        }

        if ($payment === 'processing') {
            return array_merge($decision, ['logType' => 'info', 'message' => 'On the chain, waiting for confirmations: applied by itself once final.']);
        }
        if ($payment === 'partially_paid') {
            return $status === 'expired'
                ? array_merge($decision, ['logType' => 'error', 'message' => sprintf('The payment page closed part paid: %s of %s arrived in your wallet. Not applied: settle it with the member (send it back, or apply the purchase by hand).', self::received($session), self::due($session))])
                : array_merge($decision, ['logType' => 'info', 'message' => sprintf('Part paid so far: %s of %s. The payment page asks the member for the rest.', self::received($session), self::due($session))]);
        }
        if ($payment === 'held') {
            return array_merge($decision, ['logType' => 'error', 'message' => sprintf('The money is in your wallet but held for your review in the Orboni dashboard (%s). Accept it there and the purchase is applied.', isset($session['review_reason']) ? (string) $session['review_reason'] : 'no reason given')]);
        }
        if ($status === 'expired') {
            return array_merge($decision, ['logType' => 'info', 'message' => 'The payment page expired with nothing paid.']);
        }

        return $decision;
    }

    /**
     * What the log keeps of a session: never a key, never the member's email.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public static function summary(array $session): array
    {
        $keep = ['id', 'livemode', 'status', 'payment_status', 'amount_total', 'currency', 'amount_due', 'amount_received', 'amount_refunded', 'paid_late', 'review_reason', 'expires_at'];
        $summary = array_intersect_key($session, array_flip($keep));
        if (isset($session['payment']) && is_array($session['payment'])) {
            $summary['payment'] = array_intersect_key($session['payment'], array_flip(['coin', 'chain', 'amount', 'amount_usd']));
        }

        return $summary;
    }

    /** "Paid with 25.00 USDT on TRON (25.00 USD), session cs_live_…." @param array<string, mixed> $session */
    public static function paidNote(array $session): string
    {
        $payment = isset($session['payment']) && is_array($session['payment']) ? $session['payment'] : [];
        $chain = isset($payment['chain']) ? (string) $payment['chain'] : '';
        $note = isset($payment['coin'], $payment['amount'])
            ? sprintf('Paid with %s %s on %s (%s), session %s.', (string) $payment['amount'], (string) $payment['coin'], isset(self::CHAIN_NAMES[$chain]) ? self::CHAIN_NAMES[$chain] : ucfirst($chain), self::received($session), (string) $session['id'])
            : sprintf('Paid %s, session %s.', self::received($session), (string) $session['id']);

        if (empty($session['livemode'])) {
            $note .= ' Test payment.';
        }
        if (! empty($session['paid_late'])) {
            $note .= ' It arrived after the payment page expired.';
        }

        return $note;
    }

    /** @param array<string, mixed> $session */
    private static function received(array $session): string
    {
        return Currencies::formatDecimal(isset($session['amount_received']) ? $session['amount_received'] : 0, isset($session['currency']) ? (string) $session['currency'] : 'usd');
    }

    /** @param array<string, mixed> $session */
    private static function due(array $session): string
    {
        return Currencies::formatDecimal(isset($session['amount_due']) ? $session['amount_due'] : 0, isset($session['currency']) ? (string) $session['currency'] : 'usd');
    }
}
