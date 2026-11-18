<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class CashelaPayIn
{
    /** Version of the published Pay-In contract (cashela-payin-contract) this SDK covers. */
    public const CONTRACT_VERSION = '4.0.0';

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
        // An empty PHP array would encode as `[]`; every body of this API is a JSON object.
        $payload = $body === null ? null : ($body === [] ? '{}' : json_encode($body));
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
        $pairs = [];
        foreach ($params as $key => $value) {
            foreach ((array) $value as $v) {
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $v);
            }
        }
        $qs = implode('&', $pairs);
        return $this->request('GET', '/deposit-creation/countries' . ($qs !== '' ? "?{$qs}" : ''));
    }
    public function listPaymentMethods(array $body): array { return $this->request('POST', '/deposit-creation/available-payment-methods', $body); }
    public function getExchangeRates(array $body): array { return $this->request('POST', '/deposit-creation/exchange-rates', $body); }
    /** Is a US bank account open? Checked once, then answered from the stored verdict (`cached`). */
    public function verifyBankAccount(array $body): array { return $this->request('POST', '/bank-accounts/verify', $body); }
    public function createDeposit(array $request, ?string $idempotencyKey = null): array
    {
        // Never retry this POST: retrying after the deposit opened risks a double charge.
        $extra = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];
        return $this->request('POST', '/deposit-creation', $request, $extra);
    }
    public function getTransaction(string $reference): array { return $this->request('GET', '/transactions/' . rawurlencode($reference)); }
    private static function query(array $params): string
    {
        $qs = http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''));
        return $qs !== '' ? "?{$qs}" : '';
    }
    public function listTransactions(array $params = []): array { return $this->request('GET', '/transactions' . self::query($params)); }
    public function getWallet(): array { return $this->request('GET', '/wallet'); }
    public function listWalletMovements(array $params = []): array { return $this->request('GET', '/wallet/movements' . self::query($params)); }
    public function listSettlements(array $params = []): array { return $this->request('GET', '/settlements' . self::query($params)); }
    public function getSettlement(string $id): array { return $this->request('GET', '/settlements/' . rawurlencode($id)); }
    /** Your own limits and what is left of them. */
    public function getLimits(): array { return $this->request('GET', '/limits'); }
    /** Can this deposit be voided or refunded, and what does the reversal need (e.g. a bank account)? */
    public function getReversalOptions(string $reference): array { return $this->request('GET', '/transactions/' . rawurlencode($reference) . '/reversal-options'); }
    /** Cancels a deposit before it settles (contract 4.0.0). Whole transaction, no amount. Moves money; never retried. */
    public function voidTransaction(string $reference, array $body = []): array { return $this->request('POST', '/transactions/' . rawurlencode($reference) . '/void', $body); }
    /** Pays a settled deposit back (contract 4.0.0). Whole transaction, no amount. Moves money; never retried. */
    public function refundTransaction(string $reference, array $body = []): array { return $this->request('POST', '/transactions/' . rawurlencode($reference) . '/refund', $body); }
    /** SANDBOX ONLY: drive your own deposit to a final status. */
    public function simulateTransaction(string $reference, string $status): array { return $this->request('POST', '/sandbox/transactions/' . rawurlencode($reference) . '/simulate', ['status' => $status]); }
    /** Stores a PINless debit card (raw card data: only from a PCI-scoped server). Idempotent with $idempotencyKey. */
    public function createPaymentInstrument(array $body, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/payment-instruments', $body, $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey]);
    }
    public function listPaymentInstruments(): array { return $this->request('GET', '/payment-instruments'); }
    /** Opens a hosted card capture, so card data never reaches your server. */
    public function openCheckoutSession(): array { return $this->request('POST', '/payment-instruments/checkout-session'); }
    /** Stores a card from the temporary token the hosted capture returned. */
    public function storePaymentInstrumentFromCheckout(array $body): array { return $this->request('POST', '/payment-instruments/checkout', $body); }
    /** Revokes a stored card. */
    public function deletePaymentInstrument(string $paymentInstrumentId): array { return $this->request('DELETE', '/payment-instruments/' . rawurlencode($paymentInstrumentId)); }
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
