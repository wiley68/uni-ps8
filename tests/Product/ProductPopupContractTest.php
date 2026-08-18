<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

function assertProductPopupContract(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/product_calculator.tpl');
$javascript = (string) file_get_contents($root . '/views/js/product-calculator.js');
$css = (string) file_get_contents($root . '/views/css/product-calculator.css');
$controller = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$preferenceStore = (string) file_get_contents($root . '/src/Checkout/CheckoutPreferenceStore.php');
$checkoutJs = (string) file_get_contents($root . '/views/js/checkout-payment.js');

assertProductPopupContract(strpos($template, "s='Избор на схема за лизинг'") !== false, 'Woo Step 1 heading missing');
foreach (['Цена на артикула', 'Брой месеци за погасяване', 'Първоначална вноска /евро/', 'Обща сума на заема', 'Размер на погасителна вноска', 'Обща дължима сума', 'ГЛП', 'ГПР'] as $label) {
    assertProductPopupContract(strpos($template, $label) !== false, "missing popup field {$label}");
}
assertProductPopupContract(strpos($template, 'data-unipayment-close') !== false && strpos($template, "s='Отказ'") !== false, 'explicit Cancel control missing');
assertProductPopupContract(strpos($template, 'class="unipayment-product-calculator__overlay" aria-hidden="true"') !== false, 'overlay must be presentation-only');
assertProductPopupContract(strpos($template, 'unipayment_popup.banner_url') !== false && strpos($template, 'unipayment_popup.banner_url_mobile') !== false, 'CP responsive banner sources missing');
assertProductPopupContract(strpos($template, 'data-unipayment-step="2" hidden') !== false, 'Step 2 placeholder contract missing');
assertProductPopupContract(strpos($template, 'data-unipayment-popup-badge') === false && strpos($template, 'unipayment_popup_badge_url') !== false, 'official Apply badge asset missing');

assertProductPopupContract(strpos($javascript, "window.setTimeout(calculateNow, 400)") !== false, 'first-installment debounce contract missing');
assertProductPopupContract(strpos($javascript, 'new AbortController()') !== false && strpos($javascript, 'calculateSequence') !== false, 'abort/stale calculation guards missing');
assertProductPopupContract(strpos($javascript, "event.target.closest('[data-unipayment-close]')") !== false, 'Cancel close behavior missing');
assertProductPopupContract(strpos($javascript, "event.target.closest('[data-unipayment-overlay]')") === false, 'overlay must not close popup');
assertProductPopupContract(strpos($javascript, "event.key === 'Escape'") !== false, 'Woo Escape close parity missing');
assertProductPopupContract(strpos($javascript, '.product-add-to-cart button[data-button-action="add-to-cart"]') !== false, 'native PrestaShop add-to-cart integration missing');
assertProductPopupContract(strpos($javascript, 'button.click()') !== false, 'native Product Page add-to-cart control must perform the mutation');
assertProductPopupContract(strpos($javascript, "requestCalculation('preselect')") !== false && strpos($javascript, "window.prestashop.on('updatedCart'") !== false, 'Buy preselection/native-cart redirect flow missing');
assertProductPopupContract(strpos($javascript, "setStep(2)") !== false && strpos($javascript, 'unipaymentSelectedFinancing') !== false, 'Apply Step 1 transition state missing');
assertProductPopupContract(strpos($javascript, 'productAttributeId(document)') !== false && strpos($javascript, 'quantity()') !== false, 'dynamic Product context integration missing');
assertProductPopupContract(strpos($javascript, 'unipaymentInvalidatePopup') !== false, 'dynamic Product changes must invalidate stale open-popup state immediately');

assertProductPopupContract(strpos($controller, 'ProductContextFactory())->create') !== false, 'server-authoritative product price reconstruction missing');
assertProductPopupContract(strpos($controller, 'ProductPopupCalculator(new Calculator())') !== false, 'Phase 5 calculator integration missing');
foreach (['validateOrder', 'OrderOrchestrator', 'ControlPanel', 'SmartUcf'] as $forbidden) {
    assertProductPopupContract(strpos($controller, $forbidden) === false, "popup must not invoke {$forbidden}");
}
foreach (['delete', 'deleteProduct', 'updateQty'] as $forbiddenCartMutation) {
    assertProductPopupContract(strpos($controller, $forbiddenCartMutation) === false, 'shortcut controller must not remove or independently mutate existing cart products');
}
assertProductPopupContract(strpos($preferenceStore, "'cart_id'") !== false && strpos($preferenceStore, "'customer_id'") !== false, 'preselection must be tied to cart and customer/session identity');
assertProductPopupContract(strpos($preferenceStore, 'TTL_SECONDS = 1800') !== false, 'transient preference TTL contract missing');
assertProductPopupContract(strpos($preferenceStore, 'function clear(') !== false, 'preselection must support safe invalidation');
assertProductPopupContract(strpos($checkoutJs, 'data-module-name="unipayment"') !== false, 'module-scoped Checkout payment UX preselection missing');
assertProductPopupContract(strpos($checkoutJs, '.click()') !== false && strpos($checkoutJs, '.submit()') === false, 'payment preselection must not submit an order');
assertProductPopupContract(strpos($css, '[data-unipayment-calculator] .unipayment-product-calculator__modal') !== false, 'popup CSS is not module-scoped');
assertProductPopupContract(strpos($css, "popup-calc-bg.png") !== false, 'Woo financing block asset missing');
assertProductPopupContract(is_file($root . '/views/img/product/popup-calc-bg.png') && is_file($root . '/views/img/product/uni_mini_logo.png'), 'Woo popup assets were not copied locally');

fwrite(STDOUT, "OK (Product popup Step 1 static contract)\n");
