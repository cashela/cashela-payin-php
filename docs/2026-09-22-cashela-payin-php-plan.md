# Cashela Pay-In PHP SDK — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Portar el SDK `@cashela/payin` (Node) a PHP como paquete Composer `cashela/payin`, cumpliendo el MISMO contrato OpenAPI y pasando los MISMOS vectores HMAC, para comportamiento idéntico.

**Architecture:** PHP 8.1+, cero dependencias de runtime (curl + `hash_hmac` + `hash_equals` del core). Cliente `CashelaPayIn` con transporte inyectable (para testear sin red), `verifyWebhookSignature` como función de primera clase, errores tipados. Se ejecuta con el binario `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.

**Tech Stack:** PHP 8.1+, Composer (PSR-4 autoload), tests en PHP plano (un runner con `assert`, sin PHPUnit) contra `test-vectors/webhook-signatures.json` del repo `cashela-payin-contract`.

**Spec:** `cashela-payin-contract/docs/2026-09-22-payin-contract-and-node-sdk-design.md` (el contrato) + el SDK Node `cashela-payin-node/src/*` como referencia de comportamiento.

## Global Constraints

- PHP 8.1+; `declare(strict_types=1);` en todos los archivos. Namespace `Cashela\PayIn\`.
- CERO dependencias de runtime en `composer.json require` (solo `php`, `ext-curl`, `ext-json`). Nada en `require`.
- Ejecutar todo con `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` (NO el `php` 7.4 del PATH).
- Firma del webhook: `hash_hmac('sha256', "{timestamp}.{nonce}.{rawBody}", $secret)` en hex minúscula; comparar con `hash_equals` (tiempo constante); tolerancia default 300 s; verificar sobre los BYTES CRUDOS.
- Orden de chequeos del verificador: header faltante → timestamp fuera de tolerancia → firma inválida. Estados terminales del payload: `COMPLETED|EXPIRED|CANCELLED|VOID|REFUND`.
- Base URL: `https://<env>-api.cashela.com/api/v1/pay-in`, `<env>` ∈ dev|staging|sandbox|production; sin default silencioso a producción.
- Auth HTTP Basic (`api_key:api_secret`); `Idempotency-Key` solo en creación; NUNCA reintentar el POST de creación.
- El SDK NO lee variables de entorno por su cuenta: recibe credenciales por constructor.
- Código y comentarios en inglés.

---

## File structure

```
cashela-payin-php/
  composer.json
  .gitignore
  README.md
  src/Environment.php          # resolveBaseUrl + env list
  src/CashelaApiError.php
  src/WebhookSignatureError.php
  src/Webhook.php              # verifyWebhookSignature()
  src/CashelaPayIn.php         # the client
  tests/fixtures/webhook-signatures.json   # synced from contract
  tests/run.php                # plain-PHP test runner (asserts), exit non-zero on failure
  scripts/sync-vectors.php     # copy vectors from ../cashela-payin-contract, --check mode
```

---

### Task 1: Scaffold + errors + environment

**Files:**
- Create: `composer.json`, `.gitignore`, `src/Environment.php`, `src/CashelaApiError.php`, `src/WebhookSignatureError.php`, `tests/run.php`

**Interfaces:**
- Produces: `Cashela\PayIn\Environment::resolveBaseUrl(string $env, ?string $override = null): string`; `CashelaApiError extends \RuntimeException` with `public int $status` + `public mixed $raw`; `WebhookSignatureError extends \RuntimeException` with `public string $reason` (`missing-header|bad-signature|stale-timestamp`).

- [ ] **Step 1: `composer.json`**

```json
{
  "name": "cashela/payin",
  "description": "Cashela Pay-In client SDK",
  "type": "library",
  "license": "MIT",
  "require": { "php": ">=8.1", "ext-curl": "*", "ext-json": "*" },
  "autoload": { "psr-4": { "Cashela\\PayIn\\": "src/" } },
  "scripts": {
    "test": "php tests/run.php",
    "sync-vectors": "php scripts/sync-vectors.php"
  }
}
```

- [ ] **Step 2: `.gitignore`**

```
/vendor/
composer.lock
```

- [ ] **Step 3: `src/Environment.php`**

```php
<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class Environment
{
    private const ENVS = ['dev', 'staging', 'sandbox', 'production'];

    public static function resolveBaseUrl(string $env, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        if (!in_array($env, self::ENVS, true)) {
            throw new \InvalidArgumentException("unknown environment: {$env}");
        }
        return "https://{$env}-api.cashela.com/api/v1/pay-in";
    }
}
```

- [ ] **Step 4: `src/CashelaApiError.php` and `src/WebhookSignatureError.php`**

```php
<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class CashelaApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly mixed $raw)
    {
        parent::__construct($message);
    }
}
```

```php
<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class WebhookSignatureError extends \RuntimeException
{
    /** @param 'missing-header'|'bad-signature'|'stale-timestamp' $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("webhook signature invalid: {$reason}");
    }
}
```

- [ ] **Step 5: `tests/run.php` (plain-PHP runner; grows each task)**

```php
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
```

- [ ] **Step 6: Install autoload, run**

Run:
```bash
cd cashela-payin-php && "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/../../composer/composer.phar" dump-autoload 2>/dev/null || composer dump-autoload; "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" tests/run.php
```
(If the composer.phar path differs, just run `composer dump-autoload` with the default composer, then run tests with the php 8.3 binary.)
Expected: `ALL PASS`.

- [ ] **Step 7: Commit** — `git init && git add -A && git commit -m "feat(php): scaffold composer package, environment and typed errors"`

---

### Task 2: `verifyWebhookSignature` + shared vectors (the hero)

**Files:**
- Create: `scripts/sync-vectors.php`, `src/Webhook.php`, `tests/fixtures/webhook-signatures.json` (synced)
- Modify: `tests/run.php` (add vector cases)

**Interfaces:**
- Produces: `Cashela\PayIn\Webhook::verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds = 300, ?int $now = null): array` — returns the decoded payload (assoc array) or throws `WebhookSignatureError`. `$headers` keys are matched case-insensitively for `x-cashela-signature`, `x-cashela-timestamp`, `x-cashela-nonce`.

- [ ] **Step 1: `scripts/sync-vectors.php`** (copy from contract, `--check` mode)

```php
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
```

- [ ] **Step 2: Sync the vectors** — Run: `"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" scripts/sync-vectors.php` → `tests/fixtures/webhook-signatures.json` created.

- [ ] **Step 3: `src/Webhook.php`**

```php
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
```

- [ ] **Step 4: Add vector cases to `tests/run.php`** (before the final echo)

```php
use Cashela\PayIn\Webhook;
use Cashela\PayIn\WebhookSignatureError;

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
```

- [ ] **Step 5: Run** — `"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" tests/run.php` → `ALL PASS` (env + all vector cases).

- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat(php): verifyWebhookSignature validated against shared HMAC vectors"`

---

### Task 3: `CashelaPayIn` client (curl, injectable transport)

**Files:**
- Create: `src/CashelaPayIn.php`
- Modify: `tests/run.php` (client cases with a fake transport)

**Interfaces:**
- Consumes: `Environment`, `CashelaApiError`.
- Produces: `new CashelaPayIn(array $opts)` where `$opts = ['environment'=>..., 'apiKey'=>..., 'apiSecret'=>..., 'baseUrl'=>?, 'timeoutMs'=>?, 'transport'=>?callable]`. Transport signature: `function(string $method, string $url, array $headers, ?string $body): array{status:int, body:string}`. Methods: `listCountries(array $params = [])`, `listPaymentMethods(array $body)`, `getExchangeRates(array $body)`, `createDeposit(array $request, ?string $idempotencyKey = null)`, `getTransaction(string $reference)`, `resendCallback(string $reference)` — each returns the decoded envelope (assoc array) or throws `CashelaApiError`.

- [ ] **Step 1: Add failing client tests to `tests/run.php`** (fake transport, no network)

```php
use Cashela\PayIn\CashelaPayIn;
use Cashela\PayIn\CashelaApiError;

$cap = new stdClass();
$fakeOk = function (string $m, string $u, array $h, ?string $b) use ($cap) {
    $cap->method = $m; $cap->url = $u; $cap->headers = $h; $cap->body = $b;
    return ['status' => 200, 'body' => json_encode(['success' => true, 'message' => 'ok', 'data' => ['reference' => 'r1']])];
};
$c = new CashelaPayIn(['environment' => 'sandbox', 'apiKey' => 'k', 'apiSecret' => 's', 'transport' => $fakeOk]);
$c->getTransaction('r1');
check('basic auth header', ($cap->headers['Authorization'] ?? '') === 'Basic ' . base64_encode('k:s'), $failures);
check('sandbox url', $cap->url === 'https://sandbox-api.cashela.com/api/v1/pay-in/transactions/r1', $failures);
$c->createDeposit(['external_identifier' => 'e1'], 'idem-1');
check('idempotency header', ($cap->headers['Idempotency-Key'] ?? '') === 'idem-1', $failures);
check('create is POST', $cap->method === 'POST', $failures);

$fakeErr = fn($m, $u, $h, $b) => ['status' => 422, 'body' => json_encode(['success' => false, 'message' => 'bad field'])];
$c2 = new CashelaPayIn(['environment' => 'sandbox', 'apiKey' => 'k', 'apiSecret' => 's', 'transport' => $fakeErr]);
$threw = false;
try { $c2->getTransaction('r1'); } catch (CashelaApiError $e) { $threw = ($e->status === 422); }
check('maps non-2xx to CashelaApiError(422)', $threw, $failures);
```

- [ ] **Step 2: Run to see FAIL** — `php tests/run.php` → the client cases FAIL (class missing).

- [ ] **Step 3: `src/CashelaPayIn.php`**

```php
<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class CashelaPayIn
{
    private string $baseUrl;
    private string $auth;
    private int $timeoutMs;
    /** @var callable */
    private $transport;

    public function __construct(array $opts)
    {
        $this->baseUrl = Environment::resolveBaseUrl((string) ($opts['environment'] ?? ''), $opts['baseUrl'] ?? null);
        $this->auth = 'Basic ' . base64_encode(($opts['apiKey'] ?? '') . ':' . ($opts['apiSecret'] ?? ''));
        $this->timeoutMs = (int) ($opts['timeoutMs'] ?? 30000);
        $this->transport = $opts['transport'] ?? [$this, 'curlTransport'];
    }

    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Authorization' => $this->auth,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $extraHeaders);
        $payload = $body === null ? null : json_encode($body);
        $res = ($this->transport)($method, $this->baseUrl . $path, $headers, $payload);
        $json = json_decode((string) $res['body'], true);
        $status = (int) $res['status'];
        if ($status < 200 || $status >= 300 || (is_array($json) && ($json['success'] ?? null) === false)) {
            $message = is_array($json) && isset($json['message']) ? (string) $json['message'] : "HTTP {$status}";
            throw new CashelaApiError($message, $status, $json);
        }
        return is_array($json) ? $json : [];
    }

    public function listCountries(array $params = []): array
    {
        $qs = http_build_query($params);
        return $this->request('GET', '/deposit-creation/countries' . ($qs !== '' ? "?{$qs}" : ''));
    }
    public function listPaymentMethods(array $body): array { return $this->request('POST', '/deposit-creation/available-payment-methods', $body); }
    public function getExchangeRates(array $body): array { return $this->request('POST', '/deposit-creation/exchange-rates', $body); }
    public function createDeposit(array $request, ?string $idempotencyKey = null): array
    {
        // Never retry this POST: retrying after the deposit opened risks a double charge.
        $extra = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];
        return $this->request('POST', '/deposit-creation', $request, $extra);
    }
    public function getTransaction(string $reference): array { return $this->request('GET', '/transactions/' . rawurlencode($reference)); }
    public function resendCallback(string $reference): array { return $this->request('POST', '/transactions/' . rawurlencode($reference) . '/callback'); }

    private function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $hlist = [];
        foreach ($headers as $k => $v) { $hlist[] = "{$k}: {$v}"; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hlist,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
        ]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $resp = curl_exec($ch);
        if ($resp === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException("network error: {$err}"); }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => (int) $status, 'body' => (string) $resp];
    }
}
```

- [ ] **Step 4: Run to see PASS** — `php tests/run.php` → `ALL PASS`.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(php): CashelaPayIn client with basic auth, idempotency and error mapping"`

---

### Task 4: README + vectors:check + final green

**Files:**
- Create: `README.md`
- Modify: none (verification task)

- [ ] **Step 1: `README.md`** — install (`composer require cashela/payin`), a `CashelaPayIn` usage example, a `Webhook::verify` example inside a controller reading `file_get_contents('php://input')` and `getallheaders()`, and the SERVER-ONLY note (api_secret + webhook secret never reach the browser). Note PHP 8.1+.

- [ ] **Step 2: Final verification** — Run:
```bash
cd cashela-payin-php && "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" scripts/sync-vectors.php --check && "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" tests/run.php
```
Expected: `vectors in sync` and `ALL PASS`.

- [ ] **Step 3: Commit** — `git add -A && git commit -m "docs(php): README and vector-sync check"`

---

## Self-Review (hecho)

- **Cobertura:** environment+errors (T1), verify webhook contra vectores compartidos (T2), cliente con transporte inyectable + auth/idempotencia/errores (T3), README + check (T4). Espeja el SDK Node.
- **Placeholders:** ninguno; código completo (verify, cliente, tests). Paridad de firma garantizada por los MISMOS vectores del contrato.
- **Consistencia:** `Environment::resolveBaseUrl`, `Webhook::verify`, `CashelaPayIn`, `CashelaApiError`, `WebhookSignatureError` con firmas estables entre tareas. Se corre con el binario php 8.3, no el 7.4 del PATH.
