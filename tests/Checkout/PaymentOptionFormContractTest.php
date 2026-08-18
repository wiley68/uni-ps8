<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

function assertPaymentForm(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/checkout_payment.tpl');
$module = (string) file_get_contents($root . '/unipayment.php');

assertPaymentForm(substr_count($template, '<form ') === 1 && substr_count($template, '</form>') === 1, 'custom PaymentOption must contain exactly one form');
assertPaymentForm(strpos($template, 'method="post"') !== false && strpos($template, 'action="{$unipayment_checkout_action') !== false, 'custom form POST action is missing');

$requiredNames = [
    'unipayment_checkout_submit',
    'unipayment_checkout_token',
    'unipayment_cart_snapshot',
    'unipayment_scheme_key',
    'unipayment_kop_code',
    'unipayment_first_installment',
    'unipayment_egn',
    'unipayment_phone2',
    'unipayment_consent[]',
];
foreach ($requiredNames as $name) {
    assertPaymentForm(strpos($template, 'name="' . $name . '"') !== false, "missing submitted field {$name}");
}

assertPaymentForm(strpos($module, '->setForm($this->fetch(') !== false, 'PaymentOption does not use setForm()');
assertPaymentForm(strpos($module, "'unipayment_checkout_action' =>") !== false, 'custom form action was not assigned');

fwrite(STDOUT, "OK (Phase 8 native PaymentOption form contract)\n");
