<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;

function assertBankStatus(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$process1 = BankStatus::successfulSend(false);
assertBankStatus($process1['status_id'] === 'bank_sent_process1', 'Process 1 status id must match Woo bank_sent_process1');
assertBankStatus($process1['status_label'] === 'Sent to bank - Process 1', 'Process 1 status label mismatch');

$process2 = BankStatus::successfulSend(true);
assertBankStatus($process2['status_id'] === 'bank_sent_process2', 'Process 2 status id must match Woo bank_sent_process2');
assertBankStatus($process2['status_label'] === 'Sent to bank - Process 2', 'Process 2 status label mismatch');

$failed = BankStatus::smartUcfFailure();
assertBankStatus($failed['status_id'] === 'bank_send_failed_smartucf', 'SmartUCF failure status id mismatch');
assertBankStatus($failed['status_label'] === 'Bank send failed - SmartUCF', 'SmartUCF failure label mismatch');

$popup = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/productpopup.php');
$checkout = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/validatecheckout.php');
$grid = (string) file_get_contents(dirname(__DIR__, 2) . '/unipayment.php');
$gateway = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/NativePrestaShopOrderGateway.php');
assertBankStatus(strpos($popup, 'persistBankStatus($result->orderReference, $finalStatus)') !== false, 'popup must persist successful bank status for the admin list');
assertBankStatus(strpos($checkout, 'persistBankStatus($result->orderReference, $finalStatus)') !== false, 'checkout must persist successful bank status for the admin list');
assertBankStatus(strpos($grid, 'unipayment_bs.status_label') !== false, 'orders grid must read persisted bank status labels');
assertBankStatus(strpos($grid, "setName('UniCredit status')") !== false, 'orders grid column name must match Woo UniCredit status');
assertBankStatus(strpos($popup, 'FinancingOrderMailDispatcher') !== false && strpos($popup, 'BankStatus::smartUcfFailure') !== false, 'popup must send emails after the final bank status is known');
assertBankStatus(strpos($checkout, 'FinancingOrderMailDispatcher') !== false && strpos($checkout, 'BankStatus::smartUcfFailure') !== false, 'checkout must send emails after the final bank status is known');
assertBankStatus(strpos($gateway, 'DeferredOrderMailQueue::start') !== false, 'Process 1 order_conf must be deferred until SmartUCF');
assertBankStatus(strpos($grid, 'hookActionEmailSendBefore') !== false, 'order_conf deferral hook must be registered');
assertBankStatus(strpos($grid, 'hookDisplayPaymentReturn') !== false, 'Process 2 thank-you leasing block hook must be registered');
$leasingPresenter = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/OrderLeasingDetailsPresenter.php');
assertBankStatus(strpos($leasingPresenter, 'applyBankStatusLabel') !== false, 'admin/thank-you leasing rows must overlay live bank status like Woo');

fwrite(STDOUT, "OK (Bank status persistence for admin orders list)\n");
