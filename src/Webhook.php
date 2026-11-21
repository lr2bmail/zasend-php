<?php

declare(strict_types=1);

namespace ZaSend;

/**
 * Verify webhook deliveries. Pass the raw request body, not a re-encoded array.
 *
 *   $body = file_get_contents('php://input');
 *   $ok = Webhook::verifySignatureV2($body, $secret, $_SERVER['HTTP_X_ZASEND_SIGNATURE_V2'] ?? '');
 */
final class Webhook
{
    /** X-zaSend-Signature: "sha256=" + HMAC-SHA256(secret, body). */
    public static function verifySignature(string $payload, string $secret, string $signatureHeader): bool
    {
        $received = preg_replace('/^sha256=/', '', $signatureHeader) ?? '';
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $received);
    }

    /**
     * X-zaSend-Signature-V2: "t=<unix>,v1=" + HMAC-SHA256(secret, "<t>.<body>").
     * Also rejects deliveries older (or newer) than $toleranceSeconds, which stops replays.
     */
    public static function verifySignatureV2(string $payload, string $secret, string $signatureHeader, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $item) {
            $pair = explode('=', trim($item), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        $timestamp = (int) $parts['t'];
        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $payload, $secret);
        return hash_equals($expected, $parts['v1']);
    }
}
