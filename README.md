# PembayaranKU PHP SDK

Official framework-agnostic PHP client for Merchant API V1. Requires PHP 8.1+, ext-curl, and ext-json.

## Install

After the package is registered with Packagist, install with `composer require pembayaranku/php-sdk:^1.0`. The distributable package lives at [github.com/jagoanaiku/pembayaranku-php-sdk](https://github.com/jagoanaiku/pembayaranku-php-sdk), with its `composer.json` at repository root and a `1.0.0` release tag. For development from the main monorepo, add its `sdks/php` directory as a Composer path repository and require `pembayaranku/php-sdk:*`; for example:

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

API errors throw `PembayaranKu\ApiException`, with `httpStatus()`, `apiCode()`, and `retryAfter()`. Network failures use status `0`/`network_error`. Retry an uncertain create request with the same idempotency key; use a new key for a new operation. Returned fee and payment status fields reflect the API and are not recalculated by the SDK.

## Sandbox

Use a test key from Dashboard → Developer, create a test invoice, and use `simulateSandboxInvoice($id, 'paid')` (or `failed`, `expired`, `cancelled`). Sandbox simulation does not perform real QRIS payment or Android notification matching and is isolated from Live by the API key environment.

## Release status

Version 1.0.0 uses the proprietary terms in [`LICENSE`](LICENSE). The license permits use to integrate with PembayaranKU without account registration or manual approval. The distribution repository and `1.0.0` tag are prepared; Packagist listing is a separate publication step.
