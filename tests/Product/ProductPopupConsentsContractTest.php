<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertProductPopupConsentsContract(bool $condition, string $message): void
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
$applyService = (string) file_get_contents($root . '/src/Product/ProductPopupApplyService.php');
$presenter = (string) file_get_contents($root . '/src/Product/ProductPopupPresenter.php');

assertProductPopupConsentsContract(strpos($template, '{foreach from=$unipayment_popup.consents item=consent}') !== false, 'Step 2 must iterate CP consents dynamically');
assertProductPopupConsentsContract(strpos($template, 'data-unipayment-consent-checkbox') !== false, 'mandatory consents must render as checkboxes');
assertProductPopupConsentsContract(strpos($template, 'name="unipayment_consent[]"') !== false, 'consent checkbox name contract missing');
assertProductPopupConsentsContract(strpos($template, 'consent.has_checkbox') !== false, 'optional consents must stay informational when they have no checkbox');
assertProductPopupConsentsContract(strpos($template, "s='Please accept all mandatory consents.'") !== false, 'consents required wording missing');
assertProductPopupConsentsContract(strpos($javascript, 'areMandatoryConsentsChecked()') !== false, 'Step 2 submit must stay gated by mandatory consents');
assertProductPopupConsentsContract(strpos($javascript, 'appendAcceptedConsents(payload)') !== false, 'accepted consents must be sent with the apply request');
assertProductPopupConsentsContract(strpos($javascript, 'payload.append("unipayment_consent[]"') !== false, 'apply payload must include accepted consent ids');
assertProductPopupConsentsContract(strpos($javascript, 'resetConsents()') !== false, 'Cancel/new popup flow must uncheck consents');
assertProductPopupConsentsContract(strpos($css, '[data-unipayment-calculator] .unipayment-product-calculator__consents') !== false, 'consent CSS is not module-scoped');
assertProductPopupConsentsContract(strpos($presenter, "'consents' => \$this->consents->normalize(\$shop)") !== false, 'popup presenter must expose normalized CP consents');
assertProductPopupConsentsContract(strpos($controller, "Tools::getValue('unipayment_consent', [])") !== false, 'popup apply must read posted consent ids');
assertProductPopupConsentsContract(strpos($applyService, '$this->consents->validate($shop, $posted[\'consent\'] ?? [])') !== false, 'popup apply must re-validate consents server-side');

fwrite(STDOUT, "OK (Product popup Step 2 consents contract)\n");
