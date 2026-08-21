<?php

declare(strict_types=1);

/**
 * AUD-010 — popup address/email contract (authoritative Step 2 for direct orders).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_NEW_COOKIE_KEY_', 'aud010-test-key');

final class PhpEncryption
{
    public function __construct(string $key) {}

    public function encrypt(string $value): string
    {
        return 'enc:' . base64_encode($value);
    }

    public function decrypt(string $value)
    {
        if (strpos($value, 'enc:') !== 0) {
            return false;
        }
        $decoded = base64_decode(substr($value, 4), true);

        return is_string($decoded) ? $decoded : false;
    }
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\CalculationResult;
use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\FirstInstallmentState;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Product\PopupPreferredAddressSelector;

function assertAud010(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$selector = new PopupPreferredAddressSelector();
$addresses = [
    ['id_address' => 10, 'firstname' => 'A', 'address1' => 'Home 1', 'city' => 'Sofia', 'postcode' => '1000', 'address2' => ''],
    ['id_address' => 20, 'firstname' => 'B', 'address1' => 'Office 2', 'city' => 'Plovdiv', 'postcode' => '4000', 'address2' => ''],
];
assertAud010((int) ($selector->select($addresses, 20, 10)['id_address'] ?? 0) === 20, '2: prefer delivery');
assertAud010((int) ($selector->select($addresses, 0, 20)['id_address'] ?? 0) === 20, 'prefer invoice');
assertAud010((int) ($selector->select($addresses, 0, 0)['id_address'] ?? 0) === 10, 'fallback first');
assertAud010(
    $selector->joinAddress($addresses[1]) === 'Office 2, Plovdiv, 4000',
    'join address for prefill/match'
);

$productApply = (string) file_get_contents($root . '/src/Product/ProductPopupApplyService.php');
$cartApply = (string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php');
assertAud010(strpos($productApply, 'getFirstCustomerAddressId') === false, 'Product apply no longer uses first address only');
assertAud010(strpos($cartApply, 'getFirstCustomerAddressId') === false, 'Cart apply no longer uses first address only');
assertAud010(strpos($productApply, 'PopupOrderAddressResolver') !== false, 'Product uses resolver');
assertAud010(strpos($cartApply, 'PopupOrderAddressResolver') !== false, 'Cart uses resolver');

$resolverSrc = (string) file_get_contents($root . '/src/Product/PopupOrderAddressResolver.php');
assertAud010(strpos($resolverSrc, 'Never mutates existing') !== false
    || strpos($resolverSrc, 'never mutate') !== false
    || strpos($resolverSrc, 'Never mutates') !== false, 'resolver documents no mutation');
assertAud010(strpos($resolverSrc, 'ALIAS_BASE') !== false, 'alias policy present');
assertAud010(strpos($resolverSrc, 'phone_mobile') !== false, 'phone_mobile mapping');
assertAud010(
    strpos($resolverSrc, 'effectiveContactPhone') !== false,
    'exact-match phone uses prefill-compatible effectiveContactPhone'
);

$prefillSrc = (string) file_get_contents($root . '/src/Product/ProductPopupCustomerPrefill.php');
assertAud010(strpos($prefillSrc, 'PopupPreferredAddressSelector') !== false, '20: prefill shares preferred selector');

$snapSrc = (string) file_get_contents($root . '/src/Order/FinancingSnapshotFactory.php');
assertAud010(strpos($snapSrc, 'product_popup') !== false && strpos($snapSrc, 'cart_popup') !== false, '10: financing email overlay for popup sources');
assertAud010(strpos($snapSrc, 'Customer.email') !== false || strpos($snapSrc, 'financing contact email') !== false, 'email overlay documented');

$purgerSrc = (string) file_get_contents($root . '/src/Uninstall/ModuleDataPurger.php');
assertAud010(strpos($purgerSrc, 'ps_address') === false && strpos($purgerSrc, 'Address::') === false, '19: AUD-006 does not delete native addresses');

$moduleSrc = (string) file_get_contents($root . '/unipayment.php');
assertAud010(strpos($moduleSrc, "version = '2.0.0'") !== false, 'version 2.0.0');

// Snapshot financing email overlay
$scheme = new AvailableScheme('standard', 'K1', 12, 0, null, ['coeff' => 1.0]);
$calc = new CalculationResult(
    $scheme,
    1000.0,
    new FirstInstallmentState(0.0, true, true),
    1000.0,
    100.0,
    1200.0,
    0.0,
    0.0
);
$request = new ValidatedPaymentRequest(
    $calc,
    [
        'first_name' => 'Ivan',
        'last_name' => 'Petrov',
        'address' => 'Street 1',
        'phone' => '0888123456',
        'email' => 'financing@example.com',
        'egn' => '',
        'phone2' => '',
    ],
    [],
    'fp',
    []
);
$order = new CreatedOrder(
    1,
    'ABCDEFGHIJKLM',
    1000.0,
    'EUR',
    1,
    [
        'first_name' => 'Ivan',
        'last_name' => 'Petrov',
        'email' => 'account@example.com',
        'phone' => '0888123456',
    ],
    [
        'invoice' => ['address1' => 'Street 1', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'Bulgaria'],
        'delivery' => ['address1' => 'Street 1', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'Bulgaria'],
    ],
    []
);
$factory = new FinancingSnapshotFactory(new SensitiveDataCipher());
$popupSnap = $factory->create($request, $order, 'product_popup');
assertAud010($popupSnap['customer_json']['email'] === 'financing@example.com', '24: popup snapshot email = financing email');
$checkoutSnap = $factory->create($request, $order, 'checkout');
assertAud010($checkoutSnap['customer_json']['email'] === 'account@example.com', '32: checkout snapshot keeps Customer.email');
$cartSnap = $factory->create($request, $order, 'cart_popup');
assertAud010($cartSnap['customer_json']['email'] === 'financing@example.com', 'cart_popup financing email overlay');

assertAud010(
    !is_file($root . '/upgrade/upgrade-2.0.1.php')
        && (glob($root . '/upgrade/upgrade-*.php') ?: []) === [],
    'no upgrade scripts'
);

fwrite(STDOUT, "OK (AUD-010 popup address/email contracts)\n");
