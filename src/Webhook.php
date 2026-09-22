<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class Webhook
{
    /**
     * @param array<string,string> $headers
     * @return array<string,mixed> decoded payload
     */
    public static function verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds = 300, ?int $now = null): array
    {
        $lower = [];
        foreach ($headers as $k => $v) { $lower[strtolower((string) $k)] = $v; }
        $signature = $lower['x-cashela-signature'] ?? '';
        $timestamp = $lower['x-cashela-timestamp'] ?? '';
        $nonce = $lower['x-cashela-nonce'] ?? '';
        if ($signature === '' || $timestamp === '' || $nonce === '') {
            throw new WebhookSignatureError('missing-header');
        }
        $now ??= time();
        if (!is_numeric($timestamp) || abs($now - (int) $timestamp) > $toleranceSeconds) {
            throw new WebhookSignatureError('stale-timestamp');
        }
        $expected = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$rawBody}", $secret);
        if (!hash_equals($expected, $signature)) {
            throw new WebhookSignatureError('bad-signature');
        }
        return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    }
}
