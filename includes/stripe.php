<?php
/**
 * Smart Eats - Stripe client
 *
 * A deliberately small wrapper around the Stripe REST API using cURL.
 *
 * The official SDK would normally be installed with Composer, but that
 * adds a dependency and a vendor folder to every copy of the project.
 * Card details never touch this server: the browser sends them straight
 * to Stripe through Stripe.js, and this file only creates and reads
 * PaymentIntents. That narrow surface makes four short functions a
 * reasonable substitute for the full library on a XAMPP install.
 */

require_once __DIR__ . '/../config/config.php';

define('STRIPE_API_BASE', 'https://api.stripe.com/v1/');

/** True when real test keys have been configured. */
function stripe_configured(): bool
{
    return STRIPE_SECRET_KEY !== 'sk_test_replace_me'
        && str_starts_with(STRIPE_SECRET_KEY, 'sk_')
        && STRIPE_PUBLISHABLE_KEY !== 'pk_test_replace_me'
        && str_starts_with(STRIPE_PUBLISHABLE_KEY, 'pk_');
}

/**
 * Send a request to the Stripe API.
 *
 * @return array{ok:bool, status:int, data:array, error:string}
 */
function stripe_request(string $method, string $path, array $params = []): array
{
    $url = STRIPE_API_BASE . ltrim($path, '/');

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
    ];

    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST]       = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif ($params) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok'     => false,
            'status' => 0,
            'data'   => [],
            'error'  => 'Could not reach the payment provider. ' . $curlError,
        ];
    }

    $data = json_decode($body, true) ?: [];

    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
    }

    return [
        'ok'     => false,
        'status' => $status,
        'data'   => $data,
        'error'  => $data['error']['message'] ?? 'The payment provider returned an error.',
    ];
}

/**
 * Create a PaymentIntent for an order.
 * The amount is always passed in the smallest currency unit.
 */
function stripe_create_payment_intent(float $amount, string $currency, array $metadata = []): array
{
    $params = [
        'amount'   => (int) round($amount * 100),
        'currency' => strtolower($currency),
        'automatic_payment_methods' => ['enabled' => 'true'],
    ];

    foreach ($metadata as $key => $value) {
        $params['metadata'][$key] = (string) $value;
    }

    return stripe_request('POST', 'payment_intents', $params);
}

/** Read a PaymentIntent back from Stripe to confirm its real status. */
function stripe_retrieve_payment_intent(string $intentId): array
{
    return stripe_request('GET', 'payment_intents/' . urlencode($intentId));
}

/**
 * Verify a webhook signature.
 *
 * Stripe signs the raw request body with the endpoint secret and sends
 * the result in the Stripe-Signature header as "t=<timestamp>,v1=<hash>".
 * Recomputing that hash proves the request really came from Stripe, so
 * nobody can mark an order paid by posting to the endpoint directly.
 */
function stripe_verify_webhook(string $payload, string $signatureHeader, string $secret, int $tolerance = 300): bool
{
    if ($secret === '' || $secret === 'whsec_replace_me' || $signatureHeader === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't') {
            $timestamp = (int) $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if (!$timestamp || !$signatures) {
        return false;
    }

    // Reject old payloads so a captured request cannot be replayed.
    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}
