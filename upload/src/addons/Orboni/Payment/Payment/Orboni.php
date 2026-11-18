<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment\Payment;

use Orboni\Payment\Api;
use Orboni\Payment\ApiException;
use Orboni\Payment\Cron\Recheck;
use Orboni\Payment\Currencies;
use Orboni\Payment\Pub\Controller\PaymentReturn;
use Orboni\Payment\Records;
use Orboni\Payment\Signature;
use Orboni\Payment\Sync;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;
use XF\Repository\PaymentRepository;

/**
 * Orboni as one of XenForo's payment providers: a member choosing it is sent
 * to Orboni's payment page, pays in crypto from any wallet or exchange, and
 * the money goes straight to the forum's own wallets.
 *
 * The purchase is granted once Orboni shows the session paid — told by the
 * webhook (payment_callback.php?_xfProvider=orboni), the member's return
 * (orboni/return) or the cron, whichever comes first. Each reads the
 * session back from Orboni before anything changes, under a lock per
 * purchase; {@see Sync} decides. Everything is written to the payment
 * provider log.
 */
class Orboni extends AbstractProvider
{
    const PROVIDER_ID = 'orboni';

    public function getTitle()
    {
        return 'Orboni';
    }

    public function renderConfig(PaymentProfile $profile)
    {
        return \XF::app()->templater()->renderTemplate('admin:payment_profile_'.$this->providerId, [
            'profile' => $profile,
            'webhookUrl' => $this->getCallbackUrl(),
        ]);
    }

    /** A key or a secret pasted in the wrong field is refused, with a word why. */
    public function verifyConfig(array &$options, &$errors = [])
    {
        $options['secret_key'] = trim(isset($options['secret_key']) ? (string) $options['secret_key'] : '');
        $options['webhook_secret'] = trim(isset($options['webhook_secret']) ? (string) $options['webhook_secret'] : '');

        if (preg_match(Api::SECRET_KEY, $options['secret_key']) !== 1) {
            $errors[] = \XF::phrase('orboni_secret_key_invalid');
        }
        if ($options['webhook_secret'] !== '' && preg_match(Api::WEBHOOK_SECRET, $options['webhook_secret']) !== 1) {
            $errors[] = \XF::phrase('orboni_webhook_secret_invalid');
        }

        return ! $errors;
    }

    /** Orboni takes one payment per checkout session: no subscriptions. */
    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        $result = self::ERR_NO_RECURRING;

        return false;
    }

    public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode)
    {
        return Currencies::supports((string) $currencyCode);
    }

    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $profile = $purchase->paymentProfile;
        $key = isset($profile->options['secret_key']) ? (string) $profile->options['secret_key'] : '';
        $currency = strtoupper((string) $purchaseRequest->cost_currency);
        $amount = Currencies::minor((float) $purchaseRequest->cost_amount, $currency);
        $requestKey = (string) $purchaseRequest->request_key;

        if ($key === '' || $purchase->recurring || ! Currencies::supports($currency)) {
            $this->logError($requestKey, $key === '' ? 'No secret key saved in the payment profile.' : 'A recurring purchase, or a currency Orboni does not take: '.$currency.'.');

            return $controller->error(\XF::phrase('orboni_not_available'));
        }
        if ($currency === 'USD' && $amount < Currencies::MIN_USD_CENTS) {
            return $controller->error(\XF::phrase('orboni_below_minimum'));
        }

        try {
            $session = (new Api($key))->createSession($this->sessionBody($controller, $purchaseRequest, $purchase, $amount, $currency), 'xf-'.$requestKey);
        } catch (ApiException $e) {
            $this->logError($requestKey, sprintf('Orboni refused a session (%s): %s', $e->type, $e->getMessage()));
            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            $small = $e->type === 'amount_too_small' || ($e->type === 'invalid_request_error' && $e->param === 'amount');

            return $controller->error(\XF::phrase($small ? 'orboni_below_minimum' : 'orboni_not_available'));
        }

        if (! isset($session['id'], $session['url']) || ! is_string($session['id']) || ! is_string($session['url']) || preg_match(Api::SESSION_ID, $session['id']) !== 1) {
            $this->logError($requestKey, 'Orboni answered without a session id or url.');

            return $controller->error(\XF::phrase('orboni_not_available'));
        }

        Records::insert([
            'request_key' => $requestKey,
            'session_id' => $session['id'],
            'payment_profile_id' => (int) $purchaseRequest->payment_profile_id,
            'return_url' => (string) $purchase->returnUrl,
            'created_date' => \XF::$time,
            'expires_date' => (isset($session['expires_at']) ? (int) $session['expires_at'] : 0) ?: \XF::$time + 3600,
            // The cron's first read ten minutes from now (see Records::open()).
            'checked_date' => \XF::$time - Recheck::RECHECK_SECONDS + 600,
        ]);

        return $controller->redirect($session['url']);
    }

    /**
     * XenForo's purchase/process, for a link that names it: the same as
     * orboni/return ({@see PaymentReturn}),
     * which the payment page sends the member back to.
     */
    public function processPayment(Controller $controller, PurchaseRequest $purchaseRequest, PaymentProfile $paymentProfile, Purchase $purchase)
    {
        $row = Records::get((string) $purchaseRequest->request_key);
        if ($row !== null) {
            $this->recheck($row, $purchaseRequest, $paymentProfile);
        }

        return $controller->redirect($purchase->returnUrl);
    }

    /**
     * One purchase read back and settled, outside a webhook: the member's
     * return and the cron.
     *
     * @param  array<string, mixed>  $row
     */
    public function recheck(array $row, ?PurchaseRequest $purchaseRequest = null, ?PaymentProfile $paymentProfile = null)
    {
        $state = new CallbackState;
        if ($purchaseRequest !== null) {
            $state->purchaseRequest = $purchaseRequest;
        } else {
            $state->requestKey = (string) $row['request_key'];
        }
        if ($paymentProfile !== null) {
            $state->paymentProfile = $paymentProfile;
        }
        $state->eventType = '';

        $profile = $state->getPaymentProfile();
        if (! $state->getPurchaseRequest() || ! $profile) {
            return;
        }

        try {
            $state->session = (new Api(isset($profile->options['secret_key']) ? (string) $profile->options['secret_key'] : ''))->getSession((string) $row['session_id']);
        } catch (ApiException $e) {
            $this->logError((string) $row['request_key'], sprintf('Could not read session %s back: %s', $row['session_id'], $e->getMessage()));

            return;
        }

        if (! $this->claim($state)) {
            return;
        }

        if ($this->validateTransaction($state)
            && $this->validatePurchasableHandler($state)
            && $this->validatePurchaser($state)
            && $this->validateCost($state)
        ) {
            $this->completeTransaction($state);
        }
        if ($state->logType) {
            $this->log($state);
        }
        Records::unlock((string) $row['request_key']);
    }

    public function setupCallback(Request $request)
    {
        $state = new CallbackState;
        $state->inputRaw = $request->getInputRaw();
        $state->signature = (string) $request->getServer('HTTP_WEBHOOK_SIGNATURE', '');
        $state->ip = $request->getIp();

        $event = json_decode((string) $state->inputRaw, true);
        $object = is_array($event) && isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : null;
        $state->event = is_array($event) && is_string(isset($event['type']) ? $event['type'] : null) && $object !== null ? $event : null;
        $state->eventType = $state->event !== null ? $event['type'] : '';
        $state->eventId = $state->event !== null && isset($event['id']) ? (string) $event['id'] : '';
        $state->sessionId = $object !== null && isset($object['id']) ? (string) $object['id'] : '';
        $metadata = $object !== null && isset($object['metadata']) && is_array($object['metadata']) ? $object['metadata'] : [];
        $state->eventRequestKey = isset($metadata['xenforo_request_key']) ? (string) $metadata['xenforo_request_key'] : '';

        return $state;
    }

    /**
     * Answers: 200 for an event taken or one that is not this forum's
     * (Orboni sends every event of a project to each of its endpoints, and
     * a non-2xx is sent again for days); 401 for a bad signature; 400 for a
     * body that is no event; 503 while the forum cannot decide yet (no
     * signing secret saved, Orboni not answering, the purchase busy): Orboni
     * sends it again.
     */
    public function validateCallback(CallbackState $state)
    {
        $state->httpCode = 200;
        if ($state->event === null) {
            $state->httpCode = 400;
            $state->logMessage = 'Not an Orboni event.';

            return false;
        }

        // Another forum's, a payment link's or not a session's: not ours to act on.
        $requestKey = (string) $state->eventRequestKey;
        if (strpos((string) $state->eventType, 'checkout.session.') !== 0 || preg_match(Api::SESSION_ID, (string) $state->sessionId) !== 1 || $requestKey === '' || strlen($requestKey) > 32) {
            $state->logMessage = 'Ignored.';

            return false;
        }
        $row = Records::get($requestKey);
        if ($row === null || (string) $row['session_id'] !== $state->sessionId) {
            $state->logMessage = 'Ignored: no purchase of this forum has this session.';

            return false;
        }

        $state->requestKey = $requestKey;
        $profile = $state->getPaymentProfile();
        if (! $state->getPurchaseRequest() || ! $profile || $profile->provider_id !== $this->providerId) {
            $state->logType = 'error';
            $state->logMessage = 'Its purchase request or payment profile is gone.';

            return false;
        }

        $secret = isset($profile->options['webhook_secret']) ? (string) $profile->options['webhook_secret'] : '';
        $state->transactionId = $state->sessionId;
        if ($secret === '') {
            $state->logType = 'error';
            $state->logMessage = 'No webhook signing secret saved in the payment profile "'.$profile->title.'": Orboni sends it again.';
            $state->httpCode = 503;

            return false;
        }
        if (! Signature::verify($secret, (string) $state->inputRaw, (string) $state->signature)) {
            $state->logType = 'error';
            $state->logMessage = 'Webhook refused: the signature does not match the payment profile\'s signing secret.';
            $state->httpCode = 401;

            return false;
        }

        try {
            $state->session = (new Api(isset($profile->options['secret_key']) ? (string) $profile->options['secret_key'] : ''))->getSession($state->sessionId);
        } catch (ApiException $e) {
            $state->logType = 'error';
            $state->logMessage = sprintf('Could not read the session back (%s): Orboni sends it again.', $e->getMessage());
            $state->httpCode = 503;

            return false;
        }

        if (! $this->claim($state)) {
            $state->httpCode = $state->httpCode ?: 503;

            return false;
        }

        return true;
    }

    /**
     * Decides, under the lock {@see claim()} took: true when the purchase is
     * to be granted, taken back or granted again; otherwise false, and a
     * line in the log when something changed.
     */
    public function validateTransaction(CallbackState $state)
    {
        $decision = Sync::decide($state->row, $state->session, (string) $state->eventType);
        $state->decision = $decision;
        $state->transactionId = $decision['transaction'];
        $state->paymentResult = $decision['result'];
        $state->note = $decision['message'];

        if ($decision['result'] === null) {
            Records::update((string) $state->requestKey, ['state' => $decision['state']]);
            $state->logType = $decision['logType'];
            $state->logMessage = $decision['message'] !== '' ? $decision['message'] : 'Nothing to change.';
            $state->httpCode = 200;
            Records::unlock((string) $state->requestKey);

            return false;
        }

        return true;
    }

    /** A purchase that cannot be granted now never will be by a retry: 200, and the error in the log. */
    public function validatePurchasableHandler(CallbackState $state)
    {
        return $this->settled($state, parent::validatePurchasableHandler($state));
    }

    public function validatePurchaser(CallbackState $state)
    {
        return $this->settled($state, parent::validatePurchaser($state));
    }

    public function validatePaymentProfile(CallbackState $state)
    {
        return $this->settled($state, parent::validatePaymentProfile($state));
    }

    /** What was paid is what the purchase costs, in its currency. */
    public function validateCost(CallbackState $state)
    {
        if ($state->paymentResult === CallbackState::PAYMENT_REVERSED) {
            return true;
        }

        $purchaseRequest = $state->getPurchaseRequest();
        $currency = strtoupper((string) $purchaseRequest->cost_currency);
        $due = Currencies::minor((float) $purchaseRequest->cost_amount, $currency);
        $paid = isset($state->session['amount_total']) ? (int) $state->session['amount_total'] : 0;
        $paidIn = strtoupper(isset($state->session['currency']) ? (string) $state->session['currency'] : '');
        if ($paidIn === $currency && $paid === $due) {
            return true;
        }

        $state->logType = 'error';
        $state->logMessage = sprintf('Orboni completed a payment of %s, but this purchase costs %s: not applied.', Currencies::format($paid, $paidIn), Currencies::format($due, $currency));

        return $this->settled($state, false);
    }

    public function getPaymentResult(CallbackState $state)
    {
        $state->paymentResult = isset($state->decision['result']) ? $state->decision['result'] : null;
    }

    /**
     * XenForo grants or takes back the purchase; the row records it only
     * after, so a purchase XenForo failed on is tried again.
     */
    public function completeTransaction(CallbackState $state)
    {
        parent::completeTransaction($state);

        Records::update((string) $state->requestKey, [
            'granted' => $state->decision['granted'],
            'changes' => $state->decision['changes'],
            'state' => $state->decision['state'],
        ]);
        if ($state->note !== '' && $state->logType) {
            $state->logMessage = trim($state->logMessage.' '.$state->note);
        }
        Records::unlock((string) $state->requestKey);
    }

    public function prepareLogData(CallbackState $state)
    {
        $details = [];
        if ($state->eventType) {
            $details['event'] = $state->eventType;
            $details['event_id'] = $state->eventId;
        } else {
            $details['source'] = 'read back';
        }
        if (is_array($state->session)) {
            $details['session'] = Sync::summary($state->session);
        } elseif ($state->sessionId) {
            $details['session'] = ['id' => $state->sessionId];
        }
        if ($state->ip) {
            $details['ip'] = $state->ip;
        }
        $state->logDetails = $details;
    }

    public function log(CallbackState $state)
    {
        parent::log($state);
        if ($state->requestKey) {
            Records::unlock((string) $state->requestKey);
        }
    }

    /**
     * The purchase's lock, and its row read under it, for a session that is
     * this purchase's. False, with the reason set, when either fails.
     */
    private function claim(CallbackState $state): bool
    {
        $requestKey = (string) $state->requestKey;
        if (! Records::lock($requestKey)) {
            $state->logMessage = 'Busy: another request is settling this purchase.';
            $state->httpCode = 503;

            return false;
        }

        $row = Records::get($requestKey);
        if ($row === null || ! Sync::belongs($row, $state->session)) {
            Records::unlock($requestKey);
            $state->logType = 'error';
            $state->logMessage = 'The session read back is not this purchase\'s; left alone.';
            $state->httpCode = 200;

            return false;
        }
        $state->row = $row;
        Records::update($requestKey, ['checked_date' => \XF::$time]);

        return true;
    }

    private function settled(CallbackState $state, bool $valid): bool
    {
        if (! $valid) {
            $state->httpCode = 200;
            Records::unlock((string) $state->requestKey);
        }

        return $valid;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionBody(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase, int $amount, string $currency): array
    {
        $requestKey = (string) $purchaseRequest->request_key;
        $user = $purchase->purchaser;
        $email = $user ? (string) $user->email : '';
        $locale = str_replace('_', '-', (string) \XF::language()->getLanguageCode());
        $board = self::plain((string) \XF::options()->boardTitle);

        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit(self::plain((string) $purchase->title), 250),
            'details' => self::fit(sprintf('%s at %s', self::plain((string) $purchase->purchasableTitle), $board), 500),
            'client_reference_id' => $requestKey,
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'locale' => preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}$/', $locale) === 1 ? $locale : null,
            'success_url' => $controller->buildLink('canonical:orboni/return', null, ['request_key' => $requestKey]),
            'cancel_url' => (string) $purchase->cancelUrl,
            'metadata' => [
                'xenforo_request_key' => $requestKey,
                'xenforo_purchasable' => (string) $purchaseRequest->purchasable_type_id,
                'xenforo_user_id' => (string) $purchaseRequest->user_id,
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /** A line in Logs → Payment provider log. */
    private function logError(string $requestKey, string $message)
    {
        \XF::repository(PaymentRepository::class)->logCallback($requestKey, $this->providerId, null, 'error', $message, []);
    }

    /** Text XenForo may keep escaped for HTML, as plain text. */
    private static function plain(string $text): string
    {
        return trim(strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }
}
