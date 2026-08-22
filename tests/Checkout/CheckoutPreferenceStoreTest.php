<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;

function assertCheckoutPreferenceStore(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function checkoutPreferenceCookieGuard(string $cookieName, string $value): void
{
    if (preg_match('/¤|\|/', $cookieName . $value)) {
        throw new Exception('Forbidden chars in cookie');
    }
}

$preference = [
    'product_id' => 42,
    'product_attribute_id' => 7,
    'quantity' => 2,
    'scheme_type' => 'standard',
    'kop_code' => 'POS COM 50',
    'months' => 12,
    'filter_id' => 5,
    'first_installment' => 100.0,
    'product_amount' => 999.99,
];

$safePayload = json_encode($preference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
checkoutPreferenceCookieGuard('unipayment_checkout_preference', $safePayload);
assertCheckoutPreferenceStore(strpos($safePayload, '|') === false, 'checkout preference payload must remain cookie-safe without nested calculation');

$unsafePreference = $preference + [
    'calculation' => [
        'scheme_key' => 'standard|POS%20COM%2050|12|5',
        'price_display' => ['primary' => '999.99 евро'],
    ],
];
$unsafePayload = json_encode($unsafePreference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
assertCheckoutPreferenceStore(strpos($unsafePayload, '|') !== false, 'nested calculation fixture must contain forbidden pipe characters');

try {
    checkoutPreferenceCookieGuard('unipayment_checkout_preference', $unsafePayload);
    assertCheckoutPreferenceStore(false, 'nested calculation payload must not pass PrestaShop cookie guard');
} catch (Exception $exception) {
    assertCheckoutPreferenceStore(
        $exception->getMessage() === 'Forbidden chars in cookie',
        'PrestaShop cookie guard must reject nested calculation payloads'
    );
}

$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (is_file($config)) {
    require $config;

    /** @var Context $context */
    $context = Context::getContext();
    $context->cookie = new Cookie('ps-checkout-preference-test');
    if ((int) $context->cookie->id_guest <= 0) {
        Guest::setNewGuest($context->cookie);
    }

    $store = new CheckoutPreferenceStore();
    $store->save($context->cookie, $preference, 91001, 0);
    $loaded = $store->load($context->cookie, 91001, 0);
    assertCheckoutPreferenceStore(is_array($loaded), 'saved checkout preference must load for the same cart/customer');
    assertCheckoutPreferenceStore((int) ($loaded['months'] ?? 0) === 12, 'months must survive cookie roundtrip');
    assertCheckoutPreferenceStore($store->load($context->cookie, 91002, 0) === null, 'preference must be scoped to cart id');
    $store->clear($context->cookie);
    assertCheckoutPreferenceStore($store->load($context->cookie, 91001, 0) === null, 'clear must invalidate checkout preference');
}

fwrite(STDOUT, "OK (Checkout preference cookie-safe persistence)\n");
