<?php

declare(strict_types=1);

/**
 * AUD-002A static contracts: token issue, apply gate, cart/checkout unchanged, no SmartUCF lifecycle change.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAud002aContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$apply = (string) file_get_contents($root . '/src/Product/ProductPopupApplyService.php');
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$module = (string) file_get_contents($root . '/unipayment.php');
$cartApply = (string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php');
$cartController = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$smartUcf = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfPayloadBuilder.php');
$guest = (string) file_get_contents($root . '/src/Product/GuestCustomerFactory.php');
$repo = (string) file_get_contents($root . '/src/Product/PopupSubmissionRepository.php');

assertAud002aContract(is_file($root . '/src/Product/PopupSubmissionRepository.php'), 'popup submission repository missing');
assertAud002aContract(strpos($module, 'PopupSubmissionRepository') !== false, 'module install must create popup submission table');
assertAud002aContract(strpos($repo, 'unipayment_popup_submission') !== false, 'table name must match approved schema');
assertAud002aContract(strpos($repo, 'uniq_popup_submission_token') !== false, 'token unique index required');
assertAud002aContract(strpos($repo, 'claimForProcessing') !== false, 'atomic claim required');
assertAud002aContract(strpos($repo, 'attachCart') !== false, 'id_cart must be attachable before orchestrator');
assertAud002aContract(strpos($repo, "ORDER_CREATED_TTL_SECONDS = 2592000") !== false, 'completed mapping must retain long TTL');
assertAud002aContract(strpos($repo, 'markFailed') !== false && strpos($repo, 'stale') === false, 'no automatic stale-processing→failed TTL policy in repository API surface');

assertAud002aContract(strpos($controller, "issue_submission_token") !== false, 'controller must issue submission tokens');
assertAud002aContract(strpos($controller, 'resolvePopupSubmissionGate') !== false, 'apply must gate on submission token');
assertAud002aContract(strpos($controller, 'existingOrderResponse') !== false, 'replay must return existing order');
assertAud002aContract(strpos($controller, 'processingResponse') !== false, 'concurrent loser must get processing');
assertAud002aContract(strpos($controller, 'selection_changed') !== false, 'changed binding must be rejected');
assertAud002aContract(strpos($controller, 'revertProcessingWithoutCart') !== false, 'validation failure without cart must revert');

assertAud002aContract(strpos($apply, 'attachCart') !== false, 'apply must persist id_cart before orchestrator');
assertAud002aContract(strpos($apply, 'attachExistingCart') !== false, 'recover path must reuse cart');
assertAud002aContract(strpos($apply, 'reuseCartId') !== false, 'reuse cart parameter required');
assertAud002aContract(
    strpos($apply, 'createFreshCart') !== false
        && strpos($apply, 'if ($reuseCartId > 0)') !== false,
    'fresh cart only for winning first path'
);

assertAud002aContract(strpos($js, 'issue_submission_token') !== false, 'JS must request submission token');
assertAud002aContract(strpos($js, 'popup_submission_token') !== false, 'JS must send submission token on apply');
assertAud002aContract(strpos($js, 'body.step === "processing"') !== false, 'JS must handle processing without generic error');
assertAud002aContract(strpos($js, 'isCartSource') !== false && strpos($js, 'issue_submission_token') !== false, 'cart source must skip product token issue');

assertAud002aContract(strpos($cartApply, 'PopupSubmissionRepository') === false, 'cart apply must stay outside AUD-002A table');
assertAud002aContract(strpos($cartController, 'issue_submission_token') === false, 'cart controller unchanged by product token issue');
assertAud002aContract(strpos($checkout, 'PopupSubmissionRepository') === false, 'checkout unchanged');

assertAud002aContract(strpos($smartUcf, "orderNo' => (string) \$snapshot['order_reference']") !== false, 'SmartUCF orderNo contract unchanged');
assertAud002aContract(strpos($guest, 'customerExists') === false, 'AUD-001 must remain: no email customer lookup');
assertAud002aContract(strpos($guest, 'createGuestCustomer') !== false, 'AUD-001 fresh guest path remains');

assertAud002aContract(
    (bool) preg_match('/json_encode\(\$canonical/', (string) file_get_contents($root . '/src/Product/PopupSubmissionSelectionHash.php')),
    'selection_hash must use structured JSON canonicalization'
);

fwrite(STDOUT, "OK (AUD-002A static contract)\n");
