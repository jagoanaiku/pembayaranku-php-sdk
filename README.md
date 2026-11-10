# PembayaranKU PHP SDK

Official framework-agnostic PHP client for Merchant API V1. Requires PHP 8.1+, ext-curl, and ext-json.

## Install

Install release 1.1.0 with `composer require pembayaranku/php-sdk:^1.1` after publication. See the [Packagist listing](https://packagist.org/packages/pembayaranku/php-sdk) and distribution repository [jagoanaiku/pembayaranku-php-sdk](https://github.com/jagoanaiku/pembayaranku-php-sdk). For development from the main monorepo, add its `sdks/php` directory as a Composer path repository and require `pembayaranku/php-sdk:*`; for example:

```json
{
  "repositories": [{"type":"path","url":"../pembayarankuqris/sdks/php","options":{"symlink":true}}],
  "require": {"pembayaranku/php-sdk":"*"}
}
```

Adjust the path to your checkout location. The distribution repository is generated from the canonical `sdks/php` source in `jagoanaiku/pembayarankuqris`; SDK changes should be made in the monorepo and synchronized into the distribution repository before a new release tag is created.

## Configure and create an invoice

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$client = new PembayaranKu\Client(getenv('PEMBAYARANKU_API_KEY'));
$invoice = $client->createInvoice([
    'amount' => 50000,
    'description' => 'Order 2026-001',
    'expires_at' => '2026-11-10T15:00:00+07:00',
], 'order-2026-001');
echo $invoice['payment_url'];
```

`pk_live_` and `pk_test_` select Live and Sandbox. The optional constructor arguments are environment, API root (`.../api/v1`), and timeout seconds. Keep keys on the server; do not output them in HTML or browser JavaScript.

## Methods

- `createInvoice(array $input, string $idempotencyKey)` — returns `data` from `POST /invoices`.
- `getInvoice(string $invoiceId)` — `GET /invoices/{invoiceId}`.
- `listInvoices(array $options = [])` — `GET /invoices`; options `page`, `limit` (1–100), `status`.
- `cancelInvoice(string $invoiceId)` — `POST /invoices/{invoiceId}/cancel` (Live only).
- `simulateSandboxInvoice(string $invoiceId, string $status)` — Sandbox status simulation.

API errors throw `PembayaranKu\ApiException`, with `httpStatus()`, `apiCode()`, and `retryAfter()`. Network failures use status `0`/`network_error`. Retry an uncertain create request with the same idempotency key; use a new key for a new operation. `cancelInvoice()` is Live-only and rejects Sandbox keys locally. Returned fee and payment fields reflect the immutable invoice snapshot and API lifecycle; the SDK does not recalculate fees. Live reaches `paid` after validated matching, while Finance runs asynchronously after that state.

## Webhooks

The PHP SDK is an outbound REST client; it does not receive webhooks. Receive events on your HTTPS application server, verify the raw request body with the configured webhook secret and `X-PembayaranKu-Signature`, and persist `X-PembayaranKu-Event-Id` under a unique constraint before applying effects. Process `payment.paid` idempotently by event ID and invoice/order ID. Do not wait for asynchronous Finance to fulfill a paid order. See the [webhook guide](../../docs/api/public-api-webhook-v1.md#webhooks).

## Sandbox

Use a test key from Dashboard → Developer, create a test invoice, and use `simulateSandboxInvoice($id, 'paid')` (or `failed`, `expired`, `cancelled`). Sandbox simulation does not perform real QRIS payment or Android notification matching and is isolated from Live by the API key environment.

## Release status

Version 1.1.0 is published on [Packagist](https://packagist.org/packages/pembayaranku/php-sdk) and can be installed with `composer require pembayaranku/php-sdk:^1.1`. The SDK uses the proprietary terms in [`LICENSE`](LICENSE), which allow anyone to integrate with PembayaranKU without merchant registration or manual approval.
