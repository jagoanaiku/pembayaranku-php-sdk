<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$apiKey = getenv('PEMBAYARANKU_API_KEY');
if (!$apiKey) {
    throw new RuntimeException('Set PEMBAYARANKU_API_KEY on the server.');
}
$client = new PembayaranKu\Client($apiKey);
$invoice = $client->createInvoice([
    'amount' => 50000,
    'description' => 'Example order',
    'expires_at' => (new DateTimeImmutable('+30 minutes'))->format(DateTimeInterface::ATOM),
], 'example-order-001');
echo $invoice['payment_url'] . PHP_EOL;
