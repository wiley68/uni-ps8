<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\OrderCurrencyGuard;

function assertEurOrder(bool $value, string $message): void
{
    if (!$value) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function rejectsEurOrder(callable $call, string $message): void
{
    try { $call(); } catch (RuntimeException $e) { return; }
    assertEurOrder(false, $message);
}

$order = new CreatedOrder(55, 'ORDER55', 100.0, 'EUR', 1, [], [], []);
$snapshot = ['id_order' => 55, 'id_currency' => 1, 'currency_iso' => 'EUR'];
$guard = new OrderCurrencyGuard(static function (int $id): array { return ['id_currency' => 1, 'currency_iso' => 'EUR']; });
$guard->assertOrder($order);
$guard->assertMatchesSnapshot($order, $snapshot);
$guard->assertNativeSnapshot($snapshot);
$guard->assertSavedCpPayload(['currency' => 'EUR']);
assertEurOrder($guard->decodeSavedCpPayload('{"currency":"EUR"}')['currency'] === 'EUR', 'valid persisted CP payload rejected');
rejectsEurOrder(static function () use ($guard): void { $guard->assertOrder(new CreatedOrder(55, 'ORDER55', 100.0, 'BGN', 2, [], [], [])); }, 'BGN order accepted');
foreach ([['currency_iso' => 'BGN'], ['id_currency' => 2], ['id_order' => 56], ['currency_iso' => null]] as $change) {
    $changed = array_replace($snapshot, $change);
    rejectsEurOrder(static function () use ($guard, $order, $changed): void { $guard->assertMatchesSnapshot($order, $changed); }, 'mismatched snapshot accepted');
    if (!array_key_exists('id_order', $change)) {
        rejectsEurOrder(static function () use ($guard, $changed): void { $guard->assertNativeSnapshot($changed); }, 'mismatched native snapshot accepted');
    }
}
$nativeBgn = new OrderCurrencyGuard(static function (int $id): array { return ['id_currency' => 2, 'currency_iso' => 'BGN']; });
rejectsEurOrder(static function () use ($nativeBgn, $snapshot): void { $nativeBgn->assertNativeSnapshot($snapshot); }, 'native BGN accepted');
foreach ([null, '', ' ', '{', '[]', '{}', '{"currency":"BGN"}', '{"currency":"USD"}'] as $raw) {
    rejectsEurOrder(static function () use ($guard, $raw): void { $guard->decodeSavedCpPayload($raw); }, 'unsafe serialized CP payload accepted');
}
foreach ([['currency' => 'BGN'], [], ['currency' => 'eur']] as $payload) {
    rejectsEurOrder(static function () use ($guard, $payload): void { $guard->assertSavedCpPayload($payload); }, 'unsafe CP replay payload accepted');
}
$builder = new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder($guard);
foreach ([array_replace($snapshot, ['currency_iso' => 'BGN']), array_replace($snapshot, ['id_currency' => 2])] as $unsafe) {
    rejectsEurOrder(static function () use ($builder, $unsafe): void { $builder->build([], $unsafe); }, 'direct SmartUCF builder accepted unsafe order currency');
}
fwrite(STDOUT, "OK (EUR order snapshot and replay currency guard)\n");
