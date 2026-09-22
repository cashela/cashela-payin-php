<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Cashela\PayIn\Environment;
use Cashela\PayIn\Webhook;
use Cashela\PayIn\WebhookSignatureError;

$failures = 0;
function check(string $name, bool $cond, int &$failures): void {
    if ($cond) { echo "  ok   {$name}\n"; } else { echo "  FAIL {$name}\n"; $failures++; }
}

check('sandbox base url', Environment::resolveBaseUrl('sandbox') === 'https://sandbox-api.cashela.com/api/v1/pay-in', $failures);
check('override wins', Environment::resolveBaseUrl('sandbox', 'http://x') === 'http://x', $failures);
$threw = false; try { Environment::resolveBaseUrl('prod'); } catch (\InvalidArgumentException) { $threw = true; }
check('rejects unknown env', $threw, $failures);

$NOW = 1758518400; // same base instant the contract vectors use
$vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/webhook-signatures.json'), true);

// Expected .reason per failing shared-contract case name (fixture file stays untouched;
// this map is SDK-test-local knowledge of why each vector is invalid).
$EXPECTED_REJECT_REASON = [
    'tampered-body' => 'bad-signature',
    'wrong-secret' => 'bad-signature',
    'stale-timestamp' => 'stale-timestamp',
];

foreach ($vectors['cases'] as $c) {
    $headers = [
        'X-Cashela-Signature' => $c['expected_signature'],
        'X-Cashela-Timestamp' => $c['timestamp'],
        'X-Cashela-Nonce' => $c['nonce'],
    ];
    $tol = $c['tolerance_seconds'] ?? 300;
    if ($c['expect_valid']) {
        $payload = Webhook::verify($c['body'], $headers, $c['secret'], $tol, $NOW);
        check("vector {$c['name']} valid", ($payload['status'] ?? null) === (json_decode($c['body'], true)['status'] ?? null), $failures);
    } else {
        $reason = null;
        try { Webhook::verify($c['body'], $headers, $c['secret'], $tol, $NOW); } catch (WebhookSignatureError $e) { $reason = $e->reason; }
        check("vector {$c['name']} reason", $reason === $EXPECTED_REJECT_REASON[$c['name']], $failures);
    }
}

// SDK-local adversarial cases (not part of the shared contract fixtures): each asserts
// the exact WebhookSignatureError::$reason, not just the error class. Mirrors
// cashela-payin-node/test/webhook-signature.test.ts.

$VALID = null;
foreach ($vectors['cases'] as $c) { if ($c['name'] === 'valid') { $VALID = $c; break; } }
$baseHeaders = [
    'X-Cashela-Signature' => $VALID['expected_signature'],
    'X-Cashela-Timestamp' => $VALID['timestamp'],
    'X-Cashela-Nonce' => $VALID['nonce'],
];

function assertReason(string $name, callable $fn, string $expectedReason, int &$failures): void {
    $reason = null;
    try { $fn(); } catch (WebhookSignatureError $e) { $reason = $e->reason; }
    check($name, $reason === $expectedReason, $failures);
}

assertReason(
    'missing signature header -> missing-header',
    function () use ($VALID, $baseHeaders, $NOW) {
        $headers = $baseHeaders;
        unset($headers['X-Cashela-Signature']);
        Webhook::verify($VALID['body'], $headers, $VALID['secret'], 300, $NOW);
    },
    'missing-header',
    $failures
);

assertReason(
    'empty signature string -> missing-header',
    function () use ($VALID, $baseHeaders, $NOW) {
        Webhook::verify($VALID['body'], array_merge($baseHeaders, ['X-Cashela-Signature' => '']), $VALID['secret'], 300, $NOW);
    },
    'missing-header',
    $failures
);

assertReason(
    'non-hex / wrong-length signature with valid timestamp -> bad-signature',
    function () use ($VALID, $baseHeaders, $NOW) {
        Webhook::verify($VALID['body'], array_merge($baseHeaders, ['X-Cashela-Signature' => 'zz']), $VALID['secret'], 300, $NOW);
    },
    'bad-signature',
    $failures
);

assertReason(
    'non-numeric timestamp -> stale-timestamp',
    function () use ($VALID, $baseHeaders, $NOW) {
        Webhook::verify($VALID['body'], array_merge($baseHeaders, ['X-Cashela-Timestamp' => 'abc']), $VALID['secret'], 300, $NOW);
    },
    'stale-timestamp',
    $failures
);

echo ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURES\n");
exit($failures === 0 ? 0 : 1);
