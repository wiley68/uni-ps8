<?php

declare(strict_types=1);

/**
 * Product/Cart popup post-order CP create failure must become final Step 3.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PostOrderPopupFailureResponse;

function assertPopupCpFailure(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$product = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cart = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$mapper = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecyclePopupMapper.php');

$failed = new OrderOrchestrationException(
    'The Control Panel rejected the financing order.',
    false,
    null,
    123,
    9,
    OrderOrchestrator::TERMINAL_FAILED,
    false,
    'ABCDEFGHIJK'
);
$unknown = new OrderOrchestrationException(
    'The Control Panel result is unknown and can be retried safely.',
    true,
    null,
    124,
    10,
    OrderOrchestrator::CP_OUTCOME_UNKNOWN,
    true,
    'LMNOPQRSTUV'
);
$retryable5xx = new OrderOrchestrationException(
    'The Control Panel rejected the financing order.',
    true,
    null,
    125,
    11,
    OrderOrchestrator::CP_FAILED_RETRYABLE,
    false,
    'WXYABCDEFGH'
);
$preOrder = new OrderOrchestrationException('The financing attempt is already being processed.', true);

$responseFailed = PostOrderPopupFailureResponse::fromException($failed);
$responseUnknown = PostOrderPopupFailureResponse::fromException($unknown);
$response5xx = PostOrderPopupFailureResponse::fromException($retryable5xx);

// Test A / B — Product and Cart popup definitive CP failure
foreach (['product' => $product, 'cart' => $cart] as $label => $source) {
    assertPopupCpFailure(
        strpos($source, 'PostOrderPopupFailureResponse') !== false,
        "{$label}: must use shared post-order popup failure response"
    );
    assertPopupCpFailure(
        (bool) preg_match(
            '/isPostOrder\(\)[\s\S]*PostOrderPopupFailureResponse::fromException/s',
            $source
        ),
        "{$label}: post-order CP failure must return final popup JSON"
    );
}
assertPopupCpFailure($responseFailed['success'] === true, 'A: success true');
assertPopupCpFailure($responseFailed['step'] === 'order_created', 'A: step order_created');
assertPopupCpFailure($responseFailed['final'] === true, 'A: final flag');
assertPopupCpFailure((int) $responseFailed['order']['id_order'] === 123, 'A: order id');
assertPopupCpFailure((int) $responseFailed['order']['control_panel_order_id'] === 0, 'A: no fabricated CP id');
assertPopupCpFailure(
    strpos($responseFailed['cp_error'], 'не беше регистрирана успешно в системата на УниКредит') !== false,
    'A: definitive CP wording'
);
assertPopupCpFailure(
    strpos($responseFailed['cp_error'], 'Please try again') === false
        && strpos($responseFailed['cp_error'], 'Опитайте отново') === false,
    'A: no retry CTA'
);

// Test C / D — outcome unknown
assertPopupCpFailure($responseUnknown['step'] === 'outcome_unknown', 'C: unknown step');
assertPopupCpFailure(
    strpos($responseUnknown['cp_error'], 'потвърждението за регистрацията на финансирането не беше получено') !== false,
    'C: unknown wording'
);
assertPopupCpFailure($responseUnknown['final'] === true, 'C: unknown is still final');
assertPopupCpFailure(
    strpos($responseUnknown['cp_error'], 'не беше регистрирана успешно') === false,
    'C: unknown must not claim CP order is absent'
);

// Test E — HTTP 5xx retryable still final Step 3
assertPopupCpFailure($retryable5xx->isRetryable() && $retryable5xx->isPostOrder(), 'E: 5xx is internally retryable');
assertPopupCpFailure($response5xx['success'] === true && $response5xx['final'] === true, 'E: customer still gets final response');
assertPopupCpFailure(
    strpos($product, 'isPostOrder()') !== false
        && strpos($product, 'isRetryable()') !== false
        && strpos($product, 'isPostOrder()') < strpos($product, 'processingResponse'),
    'E: post-order check precedes processing/retryable Step 2 path'
);

// Test F — SmartUCF failure mapper unchanged
assertPopupCpFailure(
    strpos($mapper, 'OUTCOME_SMARTUCF_FAILED') !== false
        && strpos($mapper, "['smartucf_error']") !== false,
    'F: SmartUCF failure still maps to smartucf_error'
);
assertPopupCpFailure(strpos($js, 'body.smartucf_error') !== false, 'F: JS still handles smartucf_error');

// Test G / H — success Process 1/2 unchanged
assertPopupCpFailure(strpos($product, 'redirect_url') !== false, 'G/H: success redirect remains');
assertPopupCpFailure(strpos($cart, 'ShopConfigurationFlags::isProcess2') !== false, 'H: Process 2 cart redirect remains');

// Test I — pre-order validation stays Step 2
assertPopupCpFailure(!$preOrder->isPostOrder(), 'I: pre-order exception is not final');
assertPopupCpFailure(
    strpos($product, 'Please try again.') !== false
        && strpos($product, 'ProductPopupValidationException') !== false,
    'I: pre-order errors keep Step 2 messages'
);

// Test J — submission bound to existing order
assertPopupCpFailure(
    (bool) preg_match(
        '/if\s*\(\s*\$exception->isPostOrder\(\)\s*\)\s*\{[^}]*markOrderCreated\([^}]*PostOrderPopupFailureResponse/s',
        $product
    ),
    'J: post-order CP failure must mark order_created, not revert'
);
assertPopupCpFailure(
    strpos($product, "control_panel_order_id'] ?? 0) <= 0") !== false,
    'J: replay of CP-less order must not re-run SmartUCF'
);

// Test K — close does not return to visible Step 2
assertPopupCpFailure(strpos($js, 'data-unipayment-close') !== false, 'K: close button remains');
assertPopupCpFailure(
    strpos($js, 'function close()') !== false && strpos($js, 'modal.hidden = true') !== false,
    'K: close hides the popup'
);
assertPopupCpFailure(
    strpos($js, 'omitRetry') !== false,
    'K: final CP error uses informational panel without retry appendix'
);

// Test L — customer-safe content
foreach ([$responseFailed['cp_error'], $responseUnknown['cp_error']] as $message) {
    foreach (['Control Panel', 'Exception', '/api/v1', 'HTTP', 'Bearer', 'payload', 'token'] as $needle) {
        assertPopupCpFailure(stripos($message, $needle) === false, 'L: must not expose ' . $needle);
    }
}

fwrite(STDOUT, "OK (popup post-order CP failure Step 3 contract)\n");
