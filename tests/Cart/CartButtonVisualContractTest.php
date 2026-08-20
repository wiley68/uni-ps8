<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCartVisualContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/cart_calculator.tpl');
$cssProduct = (string) file_get_contents($root . '/views/css/product-calculator.css');
$cssCart = (string) file_get_contents($root . '/views/css/cart-calculator.css');
$javascript = (string) file_get_contents($root . '/views/js/cart-calculator.js');
$module = (string) file_get_contents($root . '/unipayment.php');

assertCartVisualContract(strpos($template, 'unipayment-product-calculator__button') !== false, 'cart must reuse product button classes');
assertCartVisualContract(strpos($template, "s='Купи на изплащане'") !== false, 'cart buttons must use the same title as product');
assertCartVisualContract(strpos($template, 'data-unipayment-logo') !== false, 'standard cart button must render the official logo');
assertCartVisualContract(strpos($template, '>0%</span>') !== false, 'promo cart button must retain the textual 0% distinction');
assertCartVisualContract(strpos($template, '.installment_label|escape') !== false, 'initial cart button label must use the server-side Woo format');
assertCartVisualContract(strpos($template, 'unipayment-product-calculator__heading') !== false, 'cart must support CP heading');
assertCartVisualContract(strpos($template, 'data-logo-standard') !== false && strpos($template, 'data-logo-alternative') !== false, 'cart must expose logo URLs for dark/light swap');
assertCartVisualContract(strpos($cssProduct, '[data-unipayment-cart-calculator] button.unipayment-product-calculator__button') !== false, 'product button CSS must also target the cart root');
assertCartVisualContract(strpos($cssProduct, 'RobotoCondensed-Regular-Cyrillic.woff2') !== false, 'cart button typography depends on local Cyrillic WOFF2');
assertCartVisualContract(strpos($cssCart, 'unipayment-product-calculator__button') === false, 'cart CSS must not redefine product button visuals');
assertCartVisualContract(strpos($module, 'product-calculator.css') !== false && strpos($module, "php_self === 'cart'") !== false, 'cart page must enqueue product button CSS');
assertCartVisualContract(strpos($javascript, 'applyVisualConfig(root, next)') !== false, 'AJAX refresh must reapply cart visual configuration');
assertCartVisualContract(strpos($javascript, 'buttonInstallmentLabel(offer)') !== false, 'AJAX refresh must reuse the presenter-derived Woo label');
assertCartVisualContract(strpos($javascript, 'formatAmount(offer.monthly_installment') === false, 'AJAX cart button label must not use locale currency formatting');
assertCartVisualContract(strpos($javascript, "money(offer.monthly_installment") === false, 'AJAX cart button price must not use Intl money formatting');

fwrite(STDOUT, "OK (Cart button visual contract)\n");
