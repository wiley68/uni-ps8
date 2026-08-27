<?php

declare(strict_types=1);

/**
 * AUD-010 runtime correction — name validation, phone exact-match parity, error classification.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\PopupOrderAddressResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

function assertAud010Runtime(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$validator = new ProductPopupCustomerValidator();
assertAud010Runtime($validator->validName('Иван') && $validator->validName('Maria'), '1: valid first_name accepted');
assertAud010Runtime($validator->validName('Иванов') && $validator->validName('Petrov'), '2: valid last_name accepted');

try {
    $validator->validate([
        'first_name' => 'Иван1',
        'last_name' => 'Иванов',
        'address' => 'ул. Тест 1',
        'phone' => '0888123456',
        'email' => 'a@b.test',
    ]);
    assertAud010Runtime(false, '3: invalid first_name must be rejected');
} catch (ProductPopupValidationException $exception) {
    assertAud010Runtime(isset($exception->errors()['first_name']), '3/5: first_name field error');
    assertAud010Runtime(
        $exception->errors()['first_name'] === 'Името може да съдържа само букви, интервал, тире и апостроф.',
        '3: customer-safe first_name message'
    );
}

try {
    $validator->validate([
        'first_name' => 'Иван',
        'last_name' => 'Иванов2',
        'address' => 'ул. Тест 1',
        'phone' => '0888123456',
        'email' => 'a@b.test',
    ]);
    assertAud010Runtime(false, '4: invalid last_name must be rejected');
} catch (ProductPopupValidationException $exception) {
    assertAud010Runtime(isset($exception->errors()['last_name']), '4/5: last_name field error');
    assertAud010Runtime(
        $exception->errors()['last_name'] === 'Фамилията може да съдържа само букви, интервал, тире и апостроф.',
        '4: customer-safe last_name message'
    );
}

assertAud010Runtime(
    PopupOrderAddressResolver::effectiveContactPhone('0888111222', '0888000000') === '0888111222',
    '9: phone_mobile preferred when set'
);
assertAud010Runtime(
    PopupOrderAddressResolver::effectiveContactPhone('', '0888999888') === '0888999888',
    '10: phone fallback when phone_mobile empty'
);
assertAud010Runtime(
    PopupOrderAddressResolver::effectiveContactPhone('  ', '0888999888') === '0888999888',
    '10b: whitespace phone_mobile falls back to phone'
);

$resolverSrc = (string) file_get_contents($root . '/src/Product/PopupOrderAddressResolver.php');
assertAud010Runtime(
    strpos($resolverSrc, 'effectiveContactPhone') !== false
        && strpos($resolverSrc, 'ProductPopupValidationException') !== false,
    '16: Address::add failure normalized to ProductPopupValidationException'
);
assertAud010Runtime(
    strpos($resolverSrc, 'effectiveContactPhone(') !== false
        && strpos($resolverSrc, 'phone_mobile') !== false
        && strpos($resolverSrc, '(string) $address->phone') !== false,
    'exact-match uses effective phone (mobile ?: phone)'
);

$productCtrl = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cartCtrl = (string) file_get_contents($root . '/controllers/front/cartpopup.php');

foreach (['product' => $productCtrl, 'cart' => $cartCtrl] as $label => $src) {
    assertAud010Runtime(
        strpos($src, 'UnavailableSchemeException') !== false,
        "18: {$label} maps UnavailableSchemeException explicitly"
    );
    assertAud010Runtime(
        preg_match(
            '/catch\s*\(\s*UnavailableSchemeException[^)]*\)\s*\{[^}]*The financing selection is unavailable/s',
            $src
        ) === 1,
        "18: {$label} scheme unavailable remains distinct"
    );
    assertAud010Runtime(
        strpos($src, 'customerValidationMessage') !== false,
        "17: {$label} returns customer-safe validation message"
    );
    // Apply Throwable fallback must NOT claim financing selection unavailable.
    assertAud010Runtime(
        preg_match(
            '/catch\s*\(\s*Throwable[^)]*\)\s*\{[^}]*Заявката не може да бъде обработена/s',
            $src
        ) === 1,
        "17: {$label} Address/runtime failure is not scheme unavailable"
    );
}

$productApply = (string) file_get_contents($root . '/src/Product/ProductPopupApplyService.php');
$cartApply = (string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php');
assertAud010Runtime(
    strpos($productApply, 'ProductPopupCustomerValidator') !== false
        && strpos($productApply, 'PopupOrderAddressResolver') !== false,
    '14: Product Popup uses corrected validator/resolver'
);
assertAud010Runtime(
    strpos($cartApply, 'ProductPopupCustomerValidator') !== false
        && strpos($cartApply, 'PopupOrderAddressResolver') !== false,
    '15: Cart Popup uses corrected validator/resolver'
);

foreach (['product' => $productApply, 'cart' => $cartApply] as $label => $src) {
    $validatePos = strpos($src, 'customerValidator->validate');
    $orchestratePos = strpos($src, 'orchestrator->orchestrate');
    assertAud010Runtime(
        $validatePos !== false && $orchestratePos !== false && $validatePos < $orchestratePos,
        "6-8: {$label} validates customer before order orchestration (no CP/SmartUCF on invalid data)"
    );
    $schemePos = strpos($src, 'UnavailableSchemeException');
    assertAud010Runtime(
        $schemePos !== false && $schemePos < $validatePos,
        "{$label}: scheme gate remains before customer validation"
    );
}

$validatorSrc = (string) file_get_contents($root . '/src/Product/ProductPopupCustomerValidator.php');
assertAud010Runtime(
    strpos($validatorSrc, 'validName') !== false
        && strpos($validatorSrc, 'Фамилията може да съдържа само букви, интервал, тире и апостроф.') !== false
        && strpos($validatorSrc, 'Името може да съдържа само букви, интервал, тире и апостроф.') !== false,
    'customer-safe Bulgarian name messages present'
);

$checkoutCtrl = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
assertAud010Runtime(
    strpos($checkoutCtrl, 'PopupOrderAddressResolver') === false,
    '22: native Checkout unchanged (no popup address resolver)'
);

$moduleSrc = (string) file_get_contents($root . '/unipayment.php');
assertAud010Runtime(strpos($moduleSrc, "version = '2.0.2'") !== false, 'version 2.0.2');

fwrite(STDOUT, "OK (AUD-010 runtime correction)\n");
