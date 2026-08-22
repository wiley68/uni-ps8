<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;

function assertProductPopupPreselect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$controller = (string) file_get_contents($root . '/controllers/front/productpopup.php');
if (
    preg_match(
        "/if \\(\\\$action === 'preselect'\\)[\\s\\S]*?CheckoutPreferenceStore\\(\\)\\)->save\\(\\\$this->context->cookie, \\[(.*?)\\], \\(int\\) \\\$cart->id/s",
        $controller,
        $matches
    ) === 1
) {
    assertProductPopupPreselect(false, 'preselect must not save checkout preference directly in controller');
}
assertProductPopupPreselect(
    strpos($controller, 'ProductPopupCheckoutPreselectionService') !== false,
    'preselect must delegate to server-side checkout preselection service'
);
assertProductPopupPreselect(
    strpos((string) file_get_contents($root . '/src/Product/ProductPopupCheckoutPreselectionService.php'), "'product_amount' =>") !== false,
    'preselect service must persist authoritative product amount for checkout preference matching'
);

require dirname(__DIR__) . '/Calculator/fixtures.php';

$popup = new ProductPopupCalculator(new Calculator('2026-08-17'));
$product = new PrestaShop\Module\Unipayment\Calculator\ProductContext(42, [7, 9], 1000.0);
$shop = calculatorFixture(['uni_eur' => 3]);

$calculate = $popup->calculate(
    $shop,
    $product,
    'EUR',
    'standard',
    'standard',
    'STD',
    12,
    0,
    'standard|STD|12|0',
    100.0
);
$preselect = $popup->calculate(
    $shop,
    $product,
    'EUR',
    'standard',
    (string) $calculate['scheme_type'],
    (string) $calculate['kop_code'],
    (int) $calculate['months'],
    (int) $calculate['filter_id'],
    (string) $calculate['scheme_key'],
    (float) $calculate['first_installment']
);

assertProductPopupPreselect($calculate['scheme_key'] === 'standard|STD|12|0', 'calculate must return canonical scheme key');
assertProductPopupPreselect($preselect['scheme_key'] === $calculate['scheme_key'], 'preselect identity must match calculate result');
assertProductPopupPreselect($preselect['months'] === 12 && $preselect['first_installment'] === 100.0, 'preselect must preserve validated financing identity');

$promo = $popup->calculate(
    $shop,
    $product,
    'EUR',
    'promo',
    'promo',
    'PROMO',
    12,
    0,
    'promo|PROMO|12|0',
    0.0
);
$promoPreselect = $popup->calculate(
    $shop,
    $product,
    'EUR',
    'promo',
    (string) $promo['scheme_type'],
    (string) $promo['kop_code'],
    (int) $promo['months'],
    (int) $promo['filter_id'],
    (string) $promo['scheme_key'],
    (float) $promo['first_installment']
);
assertProductPopupPreselect($promoPreselect['scheme_type'] === 'promo', 'promo preselect must preserve promo scheme type');

fwrite(STDOUT, "OK (Product popup calculate/preselect identity parity)\n");
