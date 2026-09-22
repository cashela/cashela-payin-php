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
        $threw = false;
        try { Webhook::verify($c['body'], $headers, $c['secret'], $tol, $NOW); } catch (WebhookSignatureError) { $threw = true; }
        check("vector {$c['name']} rejected", $threw, $failures);
    }
}

echo ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURES\n");
exit($failures === 0 ? 0 : 1);
