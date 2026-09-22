<?php
declare(strict_types=1);
$src = __DIR__ . '/../../cashela-payin-contract/test-vectors/webhook-signatures.json';
$dst = __DIR__ . '/../tests/fixtures/webhook-signatures.json';
if (!is_file($src)) { fwrite(STDERR, "contract vectors not found at {$src}\n"); exit(1); }
$upstream = file_get_contents($src);
if (in_array('--check', $argv, true)) {
    if (!is_file($dst) || file_get_contents($dst) !== $upstream) { fwrite(STDERR, "vectors out of sync: run sync-vectors\n"); exit(1); }
    echo "vectors in sync\n"; exit(0);
}
@mkdir(dirname($dst), 0777, true);
file_put_contents($dst, $upstream);
echo "vectors synced\n";
