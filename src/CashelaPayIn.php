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
