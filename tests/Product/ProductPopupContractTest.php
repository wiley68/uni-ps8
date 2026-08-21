<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertProductPopupContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
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
assertProductPopupContract(strpos($template, "s='Попълване на лични данни'") !== false, 'Woo Step 2 heading missing');
$step2Fields = ['first_name', 'last_name', 'address', 'phone', 'email'];
foreach ($step2Fields as $field) {
    assertProductPopupContract(substr_count($template, 'name="' . $field . '"') === 1, "Step 2 field {$field} must occur exactly once");
}
assertProductPopupContract(strpos($template, '{if $unipayment_require_egn}') !== false, 'Process 2 extra fields must be gated');
assertProductPopupContract(substr_count($template, 'name="egn"') === 1 && substr_count($template, 'name="phone2"') === 1, 'Process 2 Step 2 fields EGN and secondary phone must occur once');
assertProductPopupContract(substr_count($template, 'aria-required="true"') === 7, 'all five base Step 2 fields plus Process 2 EGN and secondary phone must be required');
foreach (['Име', 'Фамилия', 'Адрес', 'Мобилен телефон', 'E-Mail', 'ЕГН', 'Втори телефон', 'Назад', 'Изпрати'] as $step2Label) {
    assertProductPopupContract(strpos($template, $step2Label) !== false, "missing Step 2 label {$step2Label}");
}
assertProductPopupContract(strpos($template, 'data-unipayment-step="3" hidden') !== false, 'final informational placeholder missing');
assertProductPopupContract(strpos($template, 'data-unipayment-popup-badge') === false && strpos($template, 'unipayment_popup_badge_url') !== false, 'official Apply badge asset missing');
assertProductPopupContract(
    strpos($template, 'data-unipayment-first') !== false
        && strpos($template, 'inputmode="numeric"') !== false
        && strpos($template, 'pattern="[0-9]*"') !== false
        && (bool) preg_match('/data-unipayment-first[\s\S]{0,200}type="text"[\s\S]{0,120}inputmode="numeric"[\s\S]{0,80}pattern="\[0-9\]\*"/', $template),
    'editable first installment must use an integer-only input contract'
);

assertProductPopupContract(strpos($javascript, 'window.setTimeout(calculateNow, 800)') !== false, 'first-installment debounce contract missing');
assertProductPopupContract(
    (bool) preg_match("/first\\.value = first\\.value\\.replace\\(\\/\\\\D\\/g, ['\\\"]{2}\\)/", $javascript),
    'first-installment non-digit filtering missing'
);
assertProductPopupContract(strpos($javascript, 'payload.set("popup_offer_type", activeType)') !== false, 'popup context must be sent for authoritative mixed-scheme validation');
assertProductPopupContract(strpos($javascript, 'payload.set("scheme_key", fields.scheme_key)') !== false && strpos($javascript, 'payload.set("kop_code", fields.kop_code)') !== false, 'full Product Popup scheme identity must be sent for server-side validation');
assertProductPopupContract(strpos($javascript, 'calculationPayload("apply")') !== false, 'Step 2 submit must send the Step 1 identity for authoritative apply');
assertProductPopupContract(strpos($javascript, 'issue_submission_token') !== false, 'Step 1→2 must issue popup_submission_token');
assertProductPopupContract(strpos($javascript, 'popup_submission_token') !== false, 'apply must send popup_submission_token');
assertProductPopupContract(strpos($javascript, 'payload.set("phone2"') !== false, 'Process 2 secondary phone must be sent with apply');
assertProductPopupContract(strpos($javascript, 'event.target.closest("[data-unipayment-back]")') !== false && strpos($javascript, 'setStep(1)') !== false, 'Step 2 Back navigation missing');
assertProductPopupContract(strpos($javascript, 'input.value = input.defaultValue') !== false, 'Cancel/new popup flow must reset transient Step 2 values');
assertProductPopupContract(strpos($template, '<form class="unipayment-product-calculator__customer-form"') === false, 'Step 2 must not create an invalid form nested inside the Product add-to-cart form');
assertProductPopupContract(strpos($javascript, 'setStep(3)') !== false, 'successful validation must transition only to the final placeholder');
assertProductPopupContract(strpos($javascript, 'new AbortController()') !== false && strpos($javascript, 'calculateSequence') !== false, 'abort/stale calculation guards missing');
assertProductPopupContract(strpos($javascript, 'event.target.closest("[data-unipayment-close]")') !== false, 'Cancel close behavior missing');
assertProductPopupContract(strpos($javascript, 'event.target.closest("[data-unipayment-overlay]")') === false, 'overlay must not close popup');
assertProductPopupContract(strpos($javascript, 'event.key === "Escape"') !== false, 'Woo Escape close parity missing');
assertProductPopupContract(strpos($javascript, '.product-add-to-cart button[data-button-action="add-to-cart"]') !== false, 'native PrestaShop add-to-cart integration missing');
assertProductPopupContract(strpos($javascript, 'button.click()') !== false, 'native Product Page add-to-cart control must perform the mutation');
assertProductPopupContract(strpos($javascript, 'requestCalculation("preselect")') !== false && strpos($javascript, 'window.prestashop.on("updatedCart"') !== false, 'Buy preselection/native-cart redirect flow missing');
assertProductPopupContract(strpos($javascript, 'setStep(2)') !== false && strpos($javascript, 'unipaymentSelectedFinancing') !== false, 'Apply Step 1 transition state missing');
assertProductPopupContract(strpos($javascript, 'productAttributeId(document)') !== false && strpos($javascript, 'quantity()') !== false, 'dynamic Product context integration missing');
assertProductPopupContract(strpos($javascript, 'unipaymentInvalidatePopup') !== false, 'dynamic Product changes must invalidate stale open-popup state immediately');

assertProductPopupContract(strpos($controller, 'ProductContextFactory())->create') !== false, 'server-authoritative product price reconstruction missing');
assertProductPopupContract(strpos($controller, 'ProductPopupCalculator(new Calculator())') !== false, 'Phase 5 calculator integration missing');
assertProductPopupContract(strpos($controller, "if (\$action === 'validate_step2')") !== false && strpos($controller, 'ProductPopupCustomerValidator') !== false, 'Step 2 server validation contract missing');
assertProductPopupContract(strpos($controller, "issue_submission_token") !== false, 'Product popup must issue submission tokens before apply');
assertProductPopupContract(strpos($controller, 'OrderOrchestrator') !== false, 'Apply must orchestrate order creation after Step 2');
assertProductPopupContract(strpos($controller, 'Tools::getValue(\'monthly_installment\'') === false && strpos($controller, 'Tools::getValue(\'total_payable\'') === false, 'browser financial values must not be trusted during Step 2 validation');
foreach (['delete', 'deleteProduct', 'updateQty'] as $forbiddenCartMutation) {
    assertProductPopupContract(strpos($controller, $forbiddenCartMutation) === false, 'shortcut controller must not remove or independently mutate existing cart products');
}
assertProductPopupContract(strpos($preferenceStore, "'cart_id'") !== false && strpos($preferenceStore, "'customer_id'") !== false, 'preselection must be tied to cart and customer/session identity');
assertProductPopupContract(strpos($preferenceStore, 'TTL_SECONDS = 1800') !== false, 'transient preference TTL contract missing');
assertProductPopupContract(strpos($preferenceStore, 'function clear(') !== false, 'preselection must support safe invalidation');
assertProductPopupContract(strpos($checkoutJs, 'data-module-name="unipayment"') !== false, 'module-scoped Checkout payment UX preselection missing');
assertProductPopupContract(strpos($checkoutJs, '.click()') !== false && strpos($checkoutJs, '.submit()') === false, 'payment preselection must not submit an order');
assertProductPopupContract(strpos($css, '[data-unipayment-calculator] .unipayment-product-calculator__modal') !== false, 'popup CSS is not module-scoped');
assertProductPopupContract(strpos($css, '[data-unipayment-calculator] .unipayment-product-calculator__customer-form') !== false, 'Step 2 CSS is not module-scoped');
assertProductPopupContract(strpos($css, "popup-calc-bg.png") !== false, 'Woo financing block asset missing');
assertProductPopupContract(is_file($root . '/views/img/product/popup-calc-bg.png') && is_file($root . '/views/img/product/uni_mini_logo.png'), 'Woo popup assets were not copied locally');

fwrite(STDOUT, "OK (Product popup Step 1 static contract)\n");
