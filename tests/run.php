<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Cashela\PayIn\Environment;

$failures = 0;
function check(string $name, bool $cond, int &$failures): void {
    if ($cond) { echo "  ok   {$name}\n"; } else { echo "  FAIL {$name}\n"; $failures++; }
}

check('sandbox base url', Environment::resolveBaseUrl('sandbox') === 'https://sandbox-api.cashela.com/api/v1/pay-in', $failures);
check('override wins', Environment::resolveBaseUrl('sandbox', 'http://x') === 'http://x', $failures);
$threw = false; try { Environment::resolveBaseUrl('prod'); } catch (\InvalidArgumentException) { $threw = true; }
check('rejects unknown env', $threw, $failures);

echo ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURES\n");
exit($failures === 0 ? 0 : 1);
