<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCheckoutUi(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/checkout_payment.tpl');
$css = (string) file_get_contents($root . '/views/css/checkout-payment.css');
$js = (string) file_get_contents($root . '/views/js/checkout-payment.js');
$validate = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$module = (string) file_get_contents($root . '/unipayment.php');

assertCheckoutUi(strpos($template, "s='Order total'") !== false, 'checkout must use order total label');
assertCheckoutUi(strpos($template, 'redirected to the UniCredit page') !== false, 'checkout intro must describe UniCredit redirect');
assertCheckoutUi(strpos($template, "s='Number of repayment months'") !== false, 'months label must be present');
assertCheckoutUi(strpos($template, "s='Down payment /EUR/'") !== false, 'first installment label must be present');
assertCheckoutUi(strpos($template, "s='Total loan amount'") !== false, 'loan amount label must be present');
assertCheckoutUi(strpos($template, "s='EGN'") !== false && strpos($template, "s='Secondary phone'") !== false, 'Process 2 fields must be present');
assertCheckoutUi(strpos($template, 'data-unipayment-consent-checkbox') !== false, 'checkout consents must expose checkbox actions');
assertCheckoutUi(strpos($template, 'unipayment-checkout__consent--info') !== false, 'optional consent info rows must remain');
assertCheckoutUi(strpos($css, '--unipayment-checkout-red: #ed1c24') !== false, 'checkout CSS must use UniCredit red token');
assertCheckoutUi(strpos($css, 'border-bottom: 1px solid #b0b0b0') !== false, 'checkout controls must use bottom-only border like product/cart/Woo');
assertCheckoutUi(strpos($css, 'color: var(--unipayment-checkout-red)') !== false && strpos($css, '.unipayment-checkout__select') !== false, 'checkout select/input must use red text');
assertCheckoutUi(strpos($css, 'font-size: inherit') !== false, 'nested checkout labels must inherit row label size');
assertCheckoutUi(strpos($js, 'validateBeforeSubmit') !== false, 'checkout JS must gate submission');
assertCheckoutUi(strpos($js, 'consentsOk') !== false, 'checkout JS must enforce consents');
assertCheckoutUi(strpos($js, 'markSubmitting') !== false, 'checkout JS must block double submit');
assertCheckoutUi(strpos($js, 'data-calculate-endpoint') !== false || strpos($js, 'calculate-endpoint') !== false, 'checkout JS must recalculate via endpoint');
assertCheckoutUi(strpos($validate, 'CheckoutSubmitLock') !== false, 'validatecheckout must acquire submit lock');
assertCheckoutUi(strpos($validate, "'checkout'") !== false, 'orchestrate must persist submission_source=checkout');
assertCheckoutUi(strpos($module, 'checkoutcalculate') !== false, 'module must expose checkout calculate endpoint');
assertCheckoutUi(is_file($root . '/controllers/front/checkoutcalculate.php'), 'checkoutcalculate controller missing');
assertCheckoutUi(is_file($root . '/src/Checkout/CheckoutSubmitLock.php'), 'CheckoutSubmitLock missing');

fwrite(STDOUT, "OK (Checkout UI / Woo parity contract)\n");
