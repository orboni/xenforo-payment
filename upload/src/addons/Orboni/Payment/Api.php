<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment;

use GuzzleHttp\Exception\GuzzleException;

/**
 * Orboni's API, as much of it as a forum needs: make a checkout session, read
 * one back. `Authorization: Bearer sk_…`, JSON both ways, through XenForo's
 * HTTP client.
 */
class Api
{
    const VERSION = '1.0.1';

    const SESSION_ID = '/^cs_(live|test)_[A-Za-z0-9]+$/';

    const SECRET_KEY = '/^sk_(live|test)_[A-Za-z0-9]+$/';

    const WEBHOOK_SECRET = '/^whsec_[A-Za-z0-9]+$/';

    /** Seconds Orboni's own "updating, retry" answer may make a buyer wait, once. */
    const MAX_RETRY_AFTER = 5;

    /** @var string */
    private $secret;

    /** @var string */
    private $base;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
        // Another Orboni only for trying the module out (ORBONI_API_URL in the environment).
        $env = getenv('ORBONI_API_URL');
        $this->base = rtrim(is_string($env) && $env !== '' ? $env : 'https://orboni.com', '/');
    }

    /**
     * A new checkout session. The key makes a retry return the session the
     * first try made instead of a second one.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function createSession(array $body, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/checkout/sessions', $body, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function getSession(string $id): array
    {
        if (preg_match(self::SESSION_ID, $id) !== 1) {
            throw new ApiException('Not an Orboni checkout session id.', 'bad_session_id', 0);
        }

        return $this->request('GET', '/v1/checkout/sessions/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->secret,
            'Accept' => 'application/json',
            'User-Agent' => 'orboni-xenforo/'.self::VERSION.'; XenForo/'.\XF::$version,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        // A GET, or a POST under an idempotency key, can be sent twice safely.
        $retryable = $method === 'GET' || $idempotencyKey !== null;

        $answer = null;
        for ($try = 0; $try < 2; $try++) {
            $answer = $this->send($method, $this->base.$path, $headers, $body === null ? null : (string) json_encode($body));
            if ($answer['status'] === 0) {
                if ($retryable && $try === 0) {
                    continue;
                }

                throw new ApiException('Orboni could not be reached: '.$answer['error'], 'connection_error', 0);
            }
            // Orboni's own deploy: a few seconds' "503, retry after".
            if ($answer['status'] === 503 && $retryable && $try === 0) {
                $wait = isset($answer['headers']['retry-after']) ? (int) $answer['headers']['retry-after'] : 1;
                sleep(min(max($wait, 1), self::MAX_RETRY_AFTER));

                continue;
            }

            break;
        }

        return self::answer($answer);
    }

    /**
     * One request through XenForo's HTTP client: its CA bundle, its proxy
     * settings and other add-ons' options (`http_client_options`) apply.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string, error: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $options = [
            'headers' => $headers,
            'http_errors' => false,
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 20,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = \XF::app()->http()->client()->request($method, $url, $options);
        } catch (GuzzleException $e) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $e->getMessage()];
        }

        $received = [];
        foreach ($response->getHeaders() as $name => $values) {
            $received[strtolower((string) $name)] = implode(', ', $values);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $received, 'body' => (string) $response->getBody(), 'error' => ''];
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string, error: string}|null  $answer
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private static function answer(?array $answer = null): array
    {
        if ($answer === null) {
            throw new ApiException('Orboni could not be reached.', 'connection_error', 0);
        }
        $data = json_decode($answer['body'], true);
        if ($answer['status'] >= 200 && $answer['status'] < 300 && is_array($data)) {
            return $data;
        }

        $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];

        throw new ApiException(
            isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Orboni answered HTTP '.$answer['status'].'.',
            isset($error['type']) && is_string($error['type']) ? $error['type'] : 'http_'.$answer['status'],
            $answer['status'],
            isset($error['param']) && is_string($error['param']) ? $error['param'] : ''
        );
    }
}
