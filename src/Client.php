<?php

declare(strict_types=1);

namespace PembayaranKu;

use JsonException;
use InvalidArgumentException;
use RuntimeException;

final class Client
{
    private const DEFAULT_BASE_URL = 'https://www.pembayaranku.com/api/v1';

    private readonly string $environment;
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        ?string $environment = null,
        ?string $baseUrl = null,
        private readonly int $timeoutSeconds = 15,
    ) {
        $keyEnvironment = match (true) {
            str_starts_with($apiKey, 'pk_live_') => 'live',
            str_starts_with($apiKey, 'pk_test_') => 'sandbox',
            default => throw new InvalidArgumentException('apiKey must use the pk_live_ or pk_test_ prefix.'),
        };
        if ($environment !== null && $environment !== $keyEnvironment) {
            throw new InvalidArgumentException('environment does not match the API key prefix.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('timeoutSeconds must be between 1 and 120.');
        }

        $this->environment = $environment ?? $keyEnvironment;
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl ?? self::DEFAULT_BASE_URL);
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /** @param array<string, mixed> $invoice @return array<string, mixed> */
    public function createInvoice(array $invoice, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 200 || preg_match('/[\r\n]/', $idempotencyKey)) {
            throw new InvalidArgumentException('idempotencyKey must contain 1–200 characters and no line breaks.');
        }
        return $this->request('POST', '/invoices', $invoice, ['Idempotency-Key: ' . $idempotencyKey]);
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', '/invoices/' . rawurlencode($invoiceId));
    }

    /** @param array{page?: int, limit?: int, status?: string} $options @return array<string, mixed> */
    public function listInvoices(array $options = []): array
    {
        $page = $options['page'] ?? 1;
        $limit = $options['limit'] ?? 20;
        if (!is_int($page) || $page < 1 || $page > 10000) {
            throw new InvalidArgumentException('page must be an integer from 1 to 10000.');
        }
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be an integer from 1 to 100.');
        }
        if (isset($options['status'])) {
            $statuses = ['pending', 'matched', 'paid', 'failed', 'expired', 'cancelled'];
            if (!in_array($options['status'], $statuses, true)) {
                throw new InvalidArgumentException('status is not a supported invoice status.');
            }
            if ($this->environment === 'sandbox' && $options['status'] === 'matched') {
                throw new InvalidArgumentException('matched is not a Sandbox invoice status.');
            }
        }

        $query = ['page' => $page, 'limit' => $limit];
        if (isset($options['status'])) {
            $query['status'] = $options['status'];
        }
        return $this->request('GET', '/invoices?' . http_build_query($query));
    }

    /** Cancellation is supported for Live invoices; Sandbox uses its simulator. @return array<string, mixed> */
    public function cancelInvoice(string $invoiceId): array
    {
        return $this->request('POST', '/invoices/' . rawurlencode($invoiceId) . '/cancel');
    }

    /** @return array<string, mixed> */
    public function simulateSandboxInvoice(string $invoiceId, string $status): array
    {
        if ($this->environment !== 'sandbox') {
            throw new InvalidArgumentException('simulateSandboxInvoice requires a Sandbox API key.');
        }
        if (!in_array($status, ['paid', 'failed', 'expired', 'cancelled'], true)) {
            throw new InvalidArgumentException('status must be paid, failed, expired, or cancelled.');
        }
        return $this->request('POST', '/sandbox/invoices/' . rawurlencode($invoiceId) . '/simulate', ['status' => $status]);
    }

    /** @param array<string, mixed>|null $body @param list<string> $extraHeaders @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ], $extraHeaders);
        $encoded = null;
        if ($body !== null) {
            try {
                $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Request body could not be encoded as JSON.', 0, $exception);
            }
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new ApiException('Could not initialize the HTTP client.', 0, 'network_error');
        }
        $retryAfter = null;
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($header, strlen('Retry-After:')));
                }
                return strlen($header);
            },
        ]);
        if ($encoded !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
        }

        $raw = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false) {
            throw new ApiException($curlError !== '' ? $curlError : 'Network request failed.', 0, 'network_error');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('PembayaranKU returned invalid JSON (HTTP ' . $status . ').', $status, 'invalid_response');
        }
        if (!is_array($payload)) {
            throw new ApiException('PembayaranKU returned an unexpected JSON response.', $status, 'invalid_response');
        }
        if ($status < 200 || $status >= 300) {
            $error = $payload['error'] ?? [];
            throw new ApiException(
                is_string($error['message'] ?? null) ? $error['message'] : 'PembayaranKU request failed with HTTP ' . $status . '.',
                $status,
                is_string($error['code'] ?? null) ? $error['code'] : 'api_error',
                $retryAfter,
            );
        }
        if (!array_key_exists('data', $payload)) {
            throw new ApiException('PembayaranKU returned a response without the data envelope.', $status, 'invalid_response');
        }
        if (!is_array($payload['data'])) {
            throw new ApiException('PembayaranKU returned an unexpected data payload.', $status, 'invalid_response');
        }
        return $payload['data'];
    }

    private function normalizeBaseUrl(string $value): string
    {
        $parts = parse_url($value);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('baseUrl must be an absolute URL at the Merchant API V1 root.');
        }
        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true);
        if ($parts['scheme'] !== 'https' && !($local && $parts['scheme'] === 'http')) {
            throw new InvalidArgumentException('baseUrl must use HTTPS (HTTP is allowed only for localhost).');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('baseUrl must not contain credentials, query parameters, or a fragment.');
        }
        return rtrim($value, '/');
    }
}
