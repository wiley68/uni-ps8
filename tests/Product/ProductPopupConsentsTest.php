<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupPresenter;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

function assertProductPopupConsents(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$resolver = new ConsentResolver();
$shop = [
    'consents' => [
        ['id' => 4, 'name' => 'Info', 'url' => 'not-a-url', 'mandatory' => 0],
        ['id' => 3, 'name' => 'Terms', 'url' => 'https://example.test/terms', 'mandatory' => '1'],
        ['id' => 5, 'name' => '', 'mandatory' => 1],
        'skip-me',
    ],
];

$normalized = $resolver->normalize($shop);
assertProductPopupConsents(count($normalized) === 2, 'invalid consent rows must be skipped');
assertProductPopupConsents($normalized[0]['id'] === 3 && $normalized[1]['id'] === 4, 'consents must be sorted by id');
assertProductPopupConsents($normalized[0]['has_checkbox'] === true && $normalized[0]['mandatory'] === true, 'mandatory consent must render as a checkbox');
assertProductPopupConsents($normalized[1]['has_checkbox'] === false && $normalized[1]['url'] === '', 'optional consent must be informational and drop invalid URLs');

$jsonShop = ['consents' => json_encode($shop['consents'], JSON_UNESCAPED_UNICODE)];
assertProductPopupConsents($resolver->normalize($jsonShop)[0]['id'] === 3, 'JSON-encoded CP consents must be decoded');

try {
    $resolver->validate($shop, []);
    assertProductPopupConsents(false, 'missing mandatory consent was accepted');
} catch (CheckoutValidationException $exception) {
    assertProductPopupConsents(true, 'mandatory consent rejection');
}

$accepted = $resolver->validate($shop, ['3', '4']);
assertProductPopupConsents($accepted === [3, 4], 'accepted consent ids must be normalized to unique integers');
assertProductPopupConsents($resolver->validate(['consents' => []], []) === [], 'shops without consents must not block submit');

$popup = (new ProductPopupPresenter($resolver))->present($shop, 'buy');
assertProductPopupConsents($popup['consents'][0]['name'] === 'Terms' && $popup['consents'][1]['name'] === 'Info', 'product popup must expose normalized CP consents');

try {
    throw new ProductPopupValidationException(['consents' => 'Please accept all mandatory consents.']);
} catch (ProductPopupValidationException $exception) {
    assertProductPopupConsents(isset($exception->errors()['consents']), 'popup apply must surface a consents validation error');
}

fwrite(STDOUT, "OK (Product popup Step 2 consents)\n");
