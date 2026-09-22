# cashela/payin

PHP SDK for the Cashela Pay-In API. PHP 8.1+, zero runtime dependencies (uses only `ext-curl` and `ext-json`).

## Install

```bash
composer require cashela/payin
```

## Usage

The SDK never reads credentials from the environment — you pass them in explicitly.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Cashela\PayIn\CashelaPayIn;
use Cashela\PayIn\CashelaApiError;

$client = new CashelaPayIn([
    'environment' => 'sandbox', // dev|staging|sandbox|production
    'apiKey' => $_ENV['CASHELA_API_KEY'],
    'apiSecret' => $_ENV['CASHELA_API_SECRET'],
]);

try {
    $countries = $client->listCountries();

    $deposit = $client->createDeposit(
        ['external_identifier' => 'order-123', /* ... */],
        idempotencyKey: 'order-123' // sent only on creation; never retry this call
    );
} catch (CashelaApiError $e) {
    // $e->status is the HTTP status, $e->raw is the decoded error envelope
    error_log("Cashela API error {$e->status}: {$e->getMessage()}");
}
```

## Verifying webhooks

`Webhook::verify()` checks the HMAC signature over the **raw** request body and returns the
decoded payload, or throws `WebhookSignatureError` (`reason` is one of `missing-header`,
`stale-timestamp`, `bad-signature`).

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Cashela\PayIn\Webhook;
use Cashela\PayIn\WebhookSignatureError;

$rawBody = file_get_contents('php://input');
$headers = getallheaders();

try {
    $payload = Webhook::verify($rawBody, $headers, $_ENV['CASHELA_WEBHOOK_SECRET']);
} catch (WebhookSignatureError $e) {
    http_response_code(400);
    exit;
}

// ... handle $payload (e.g. update the order matching $payload['reference'])
http_response_code(200);
```

## Server-only

`apiSecret` and the webhook secret authenticate as your business and must **never** reach the
browser or a mobile client. Keep this SDK — and both secrets — entirely server-side; only call
it from backend code, never from client-side JavaScript or an app bundle.
