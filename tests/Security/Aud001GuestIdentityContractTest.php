<?php

declare(strict_types=1);

/**
 * AUD-001 regression: guest identity must never reuse registered customers by e-mail.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\PopupCustomerIdentityGate;

function assertAud001(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

$factory = (string) file_get_contents($root . '/src/Product/GuestCustomerFactory.php');
$productApply = (string) file_get_contents($root . '/src/Product/ProductPopupApplyService.php');
$cartApply = (string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php');
$gateSrc = (string) file_get_contents($root . '/src/Product/PopupCustomerIdentityGate.php');
$productController = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cartController = (string) file_get_contents($root . '/controllers/front/cartpopup.php');

assertAud001(strpos($factory, 'customerExists') === false, 'GuestCustomerFactory must not look up customers by e-mail');
assertAud001(strpos($factory, 'is_guest = 1') !== false, 'GuestCustomerFactory must create is_guest=1 customers');
assertAud001(strpos($factory, 'PS_GUEST_GROUP') !== false, 'GuestCustomerFactory must assign PS_GUEST_GROUP');
assertAud001(strpos($factory, 'createGuestCustomer') !== false, 'GuestCustomerFactory must always create a guest');

assertAud001(strpos($gateSrc, 'isLogged()') !== false, 'identity gate must require authenticated login');
assertAud001(strpos($productApply, 'PopupCustomerIdentityGate') !== false, 'product apply must use identity gate');
assertAud001(strpos($cartApply, 'PopupCustomerIdentityGate') !== false, 'cart apply must use identity gate');
assertAud001(strpos($productApply, 'shouldUseAuthenticatedCustomer') !== false, 'product apply must gate on authenticated customer');
assertAud001(strpos($cartApply, 'shouldUseAuthenticatedCustomer') !== false, 'cart apply must gate on authenticated customer');
assertAud001(strpos($productApply, 'guestFactory->ensure') !== false, 'product anonymous path must still create guest');
assertAud001(strpos($cartApply, 'guestFactory->ensure') !== false, 'cart anonymous path must still create guest');

// Cookie must only receive identity from guestFactory result in anonymous branch.
assertAud001(
    (bool) preg_match('/cookie->passwd\s*=\s*\$result\[\'customer\'\]->passwd/', $productApply),
    'product anonymous cookie passwd must come from freshly created guest'
);
assertAud001(
    (bool) preg_match('/cookie->passwd\s*=\s*\$result\[\'customer\'\]->passwd/', $cartApply),
    'cart anonymous cookie passwd must come from freshly created guest'
);

assertAud001(strpos($productController, 'GuestCustomerFactory') !== false, 'product popup controller must wire GuestCustomerFactory');
assertAud001(strpos($cartController, 'GuestCustomerFactory') !== false, 'cart popup controller must wire GuestCustomerFactory');

// Regression: orchestrator / CP / SmartUCF paths untouched by this remediation.
$orchestrator = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$cpPayload = (string) file_get_contents($root . '/src/Order/ControlPanelOrderPayloadBuilder.php');
assertAud001(strpos($orchestrator, 'function orchestrate') !== false, 'OrderOrchestrator must remain present');
assertAud001(strpos($cpPayload, "'status_id'") !== false, 'CP payload contract markers must remain');

$gate = new PopupCustomerIdentityGate();
assertAud001($gate->shouldUseAuthenticatedCustomer(null) === false, 'null customer must not be trusted');
assertAud001($gate->shouldUseAuthenticatedCustomer(new stdClass()) === false, 'non-Customer object must not be trusted');

fwrite(STDOUT, "OK (AUD-001 guest identity source / gate contract)\n");
