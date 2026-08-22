<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$service = (string) file_get_contents($root . '/src/Product/ProductPopupCheckoutPreselectionService.php');
$javascript = (string) file_get_contents($root . '/views/js/product-calculator.js');

function assertSilentBuyContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertSilentBuyContract(strpos($controller, 'ProductPopupCheckoutPreselectionService') !== false, 'preselect controller must delegate to silent checkout service');
assertSilentBuyContract(strpos($controller, 'ProductPopupCheckoutPreselectionException') !== false, 'preselect cart failures must be handled explicitly');
assertSilentBuyContract(strpos($service, 'updateQty(') !== false, 'silent buy must mutate cart server-side');
assertSilentBuyContract(strpos($service, 'CheckoutPreferenceStore') !== false, 'silent buy must persist checkout preference after cart mutation');
assertSilentBuyContract(strpos($service, "'calculation'") === false, 'silent buy must not store nested calculation in cookie');
assertSilentBuyContract(strpos($service, 'unipayment_preselect_mutation') !== false, 'silent buy must track idempotent cart mutation');
assertSilentBuyContract(strpos($javascript, 'redirectToCheckout(') !== false, 'buy flow must redirect directly to checkout');
assertSilentBuyContract(
    (bool) preg_match('/handleSecondary\\([\\s\\S]*?requestCalculation\\("preselect"\\)[\\s\\S]*?redirectToCheckout\\(/', $javascript),
    'buy flow must not chain preselect into native add-to-cart click'
);
assertSilentBuyContract(strpos($javascript, 'secondaryActionUsesNativeAddToCart') !== false, 'secondary action mode split must remain explicit');

fwrite(STDOUT, "OK (silent Product Buy checkout preselection contract)\n");
