<?php

declare(strict_types=1);

/**
 * AUD-010 — precise Step 2 validation UX copy (rules unchanged).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

function assertAud010Ux(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$MSG_FIRST = 'Името може да съдържа само букви, интервал, тире и апостроф.';
$MSG_LAST = 'Фамилията може да съдържа само букви, интервал, тире и апостроф.';
$MSG_ADDRESS = 'Адресът може да съдържа букви, цифри, интервали и стандартни знаци. Не използвайте символи като <, >, =, +, @, {, }, _, $, %, !, ?.';
$MSG_PHONE = 'Телефонът може да съдържа цифри, интервали, +, -, ( и ).';
$MSG_PHONE2 = 'Вторият телефон може да съдържа цифри, интервали, +, -, ( и ).';
$MSG_EMAIL = 'Въведете валиден e-mail адрес, например name@example.com.';
$MSG_EGN = 'ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.';
$MSG_REQUIRED = 'Полето е задължително.';

$validator = new ProductPopupCustomerValidator();
$base = [
    'first_name' => 'Иван',
    'last_name' => 'Иванов',
    'address' => 'ул. Тест 1',
    'phone' => '+359 888 123 456',
    'email' => 'ivan@example.test',
];

assertAud010Ux($validator->validName('Иван'), '1: Иван valid');
assertAud010Ux($validator->validName('Иван-Петър'), '2: Иван-Петър valid');
assertAud010Ux($validator->validName("O'Connor"), "3: O'Connor valid");
assertAud010Ux($validator->validName('Мария Луиза'), 'Мария Луиза valid');

try {
    $validator->validate(array_merge($base, ['first_name' => 'Тест1']));
    assertAud010Ux(false, '4: Тест1 must reject');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['first_name'] ?? '') === $MSG_FIRST, '4: precise first-name message');
}

try {
    $validator->validate(array_merge($base, ['last_name' => 'Петров1']));
    assertAud010Ux(false, '5: Петров1 must reject');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['last_name'] ?? '') === $MSG_LAST, '5: precise last-name message');
}

try {
    $validator->validate(array_merge($base, ['first_name' => '@Иван']));
    assertAud010Ux(false, '6: @Иван must reject');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['first_name'] ?? '') === $MSG_FIRST, '6: precise message for @');
}

try {
    $validator->validate(array_merge($base, ['first_name' => '']));
    assertAud010Ux(false, '7: empty name must reject');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['first_name'] ?? '') === $MSG_REQUIRED, '7: empty → required message');
}

assertAud010Ux($validator->validate($base)['address'] === 'ул. Тест 1', '8: Bulgarian address accepted');

try {
    $validator->validate(array_merge($base, ['address' => 'ул. Тест @ офис']));
    assertAud010Ux(false, '9: forbidden address char must reject');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['address'] ?? '') === $MSG_ADDRESS, '10: address restriction message');
}

try {
    $validator->validate(array_merge($base, ['address' => '']));
    assertAud010Ux(false, '11: empty address');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['address'] ?? '') === $MSG_REQUIRED, '11: empty address → required');
}

assertAud010Ux($validator->validPhone('+359 888 123 456'), '12: +359 phone accepted');
assertAud010Ux($validator->validPhone('(0888) 123-456'), '13: parentheses phone accepted');

try {
    $validator->validate(array_merge($base, ['phone' => '---']));
    assertAud010Ux(false, '14: alphabetic/no-digit phone rejected');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['phone'] ?? '') === $MSG_PHONE, '15: phone message lists syntax');
}

try {
    $validator->validate(array_merge($base, ['egn' => '1990010199', 'phone2' => '---']), true);
    assertAud010Ux(false, '16: phone2 invalid');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['phone2'] ?? '') === $MSG_PHONE2, '16: phone2 precise message');
}

try {
    $validator->validate(array_merge($base, ['email' => 'not-an-email']));
    assertAud010Ux(false, '17: invalid email');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['email'] ?? '') === $MSG_EMAIL, '17: helpful email message');
}

try {
    $validator->validate(array_merge($base, ['egn' => '123', 'phone2' => '0888123456']), true);
    assertAud010Ux(false, '18: short EGN');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['egn'] ?? '') === $MSG_EGN, '18: EGN length message');
}

try {
    $validator->validate(array_merge($base, ['egn' => '1990130199', 'phone2' => '0888123456']), true);
    assertAud010Ux(false, '19: invalid EGN date');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['egn'] ?? '') === $MSG_EGN, '19: EGN date message');
}

// Legacy prefilled invalid name (points 26–29): same guidance as manual entry.
try {
    $validator->validate(array_merge($base, ['last_name' => 'Тест1']));
    assertAud010Ux(false, '26-29: legacy Тест1 rejected');
} catch (ProductPopupValidationException $e) {
    assertAud010Ux(($e->errors()['last_name'] ?? '') === $MSG_LAST, '26-29: same precise last_name guidance');
    assertAud010Ux(strpos(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'PrestaShop') === false, '22: no PrestaShopException text');
    assertAud010Ux(strpos(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), '/^') === false, '23: no regex shown');
}

$ok = $validator->validate(array_merge($base, ['last_name' => 'Тест']));
assertAud010Ux($ok['last_name'] === 'Тест', '30: corrected Тест accepted');

$productTpl = (string) file_get_contents($root . '/views/templates/hook/product_calculator.tpl');
$cartTpl = (string) file_get_contents($root . '/views/templates/hook/cart_calculator.tpl');
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
foreach ([$MSG_FIRST, $MSG_LAST, $MSG_PHONE, $MSG_EMAIL] as $msg) {
    assertAud010Ux(strpos($productTpl, $msg) !== false, '20: Product tpl has message');
    assertAud010Ux(strpos($cartTpl, $msg) !== false, '21: Cart tpl has same message');
}
assertAud010Ux(strpos($js, 'showCustomerErrors(body.errors)') !== false, '20: JS surfaces server field errors');
assertAud010Ux(strpos($js, 'data-invalid-first-name-message') !== false, 'JS reads first-name guidance');
assertAud010Ux(strpos($js, 'firstInvalid.focus') !== false, 'focus first invalid field');

$validatorSrc = (string) file_get_contents($root . '/src/Product/ProductPopupCustomerValidator.php');
assertAud010Ux(
    strpos($validatorSrc, 'validName') !== false
        && strpos($validatorSrc, 'validAddressLine') !== false
        && strpos($validatorSrc, 'validPhone') !== false,
    '25: validation methods unchanged in role'
);
assertAud010Ux(strpos($validatorSrc, $MSG_FIRST) !== false && strpos($validatorSrc, $MSG_LAST) !== false, 'copy lives in shared validator');

fwrite(STDOUT, "OK (AUD-010 validation UX refinement)\n");
