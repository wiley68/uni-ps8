<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCartPopupContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/cart_calculator.tpl');
$css = (string) file_get_contents($root . '/views/css/cart-calculator.css');
$module = (string) file_get_contents($root . '/unipayment.php');
$jsProduct = (string) file_get_contents($root . '/views/js/product-calculator.js');
$controller = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$apply = (string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php');

assertCartPopupContract(strpos($template, 'data-unipayment-source="cart"') !== false, 'cart root must declare source=cart');
assertCartPopupContract(strpos($template, 'data-unipayment-calculator') !== false, 'cart must reuse product popup JS root');
assertCartPopupContract(strpos($template, "s='Цена на количката'") !== false, 'cart popup must label cart total');
assertCartPopupContract(strpos($template, 'data-unipayment-apply') !== false, 'cart Step 1 must expose Кандидатствай');
assertCartPopupContract(strpos($template, 'data-unipayment-submit') !== false, 'cart Step 2 must expose Изпрати');
assertCartPopupContract(strpos($template, 'data-unipayment-customer-form') !== false, 'cart popup must include customer Step 2');
assertCartPopupContract(substr_count($template, 'data-unipayment-secondary') === 1, 'cart must keep a hidden secondary stub only');
assertCartPopupContract(strpos($template, 'data-hide-secondary="1"') !== false, 'cart must hide add-to-cart secondary action');
assertCartPopupContract(strpos($css, 'data-unipayment-source="cart"') !== false, 'cart CSS must hide secondary for cart source');
assertCartPopupContract(strpos($module, 'cartpopup') !== false, 'module must wire cartpopup endpoint');
assertCartPopupContract(strpos($module, 'product-calculator.js') !== false && strpos($module, "php_self === 'cart'") !== false, 'cart page must enqueue product popup JS');
assertCartPopupContract(strpos($jsProduct, 'isCartSource') !== false, 'product popup JS must detect cart source');
assertCartPopupContract(strpos($controller, 'CartPopupApplyService') !== false, 'cartpopup controller must apply via CartPopupApplyService');
assertCartPopupContract(strpos($apply, "'cart_popup'") !== false, 'apply must persist submission_source=cart_popup');
assertCartPopupContract(strpos($apply, 'neutralizeShipping') !== false, 'apply must align order total with contents-only financing');

fwrite(STDOUT, "OK (Cart popup contract)\n");
