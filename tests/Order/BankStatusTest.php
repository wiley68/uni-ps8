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
assertBankStatus($process1['status_label'] === 'Изпратен Банка - Процес 1', 'Process 1 status label mismatch');

$process2 = BankStatus::successfulSend(true);
assertBankStatus($process2['status_id'] === 'bank_sent_process2', 'Process 2 status id must match Woo bank_sent_process2');
assertBankStatus($process2['status_label'] === 'Изпратен Банка - Процес 2', 'Process 2 status label mismatch');

$failed = BankStatus::smartUcfFailure();
assertBankStatus($failed['status_id'] === 'bank_send_failed_smartucf', 'SmartUCF failure status id mismatch');
assertBankStatus($failed['status_label'] === 'Неуспешно изпратен Банка - SmartUCF', 'SmartUCF failure label mismatch');

$popup = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/productpopup.php');
$checkout = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/validatecheckout.php');
$grid = (string) file_get_contents(dirname(__DIR__, 2) . '/unipayment.php');
assertBankStatus(strpos($popup, 'persistBankStatus($result->orderReference, $sentStatus)') !== false, 'popup must persist successful bank status for the admin list');
assertBankStatus(strpos($checkout, 'persistBankStatus($result->orderReference, $sentStatus)') !== false, 'checkout must persist successful bank status for the admin list');
assertBankStatus(strpos($grid, 'unipayment_bs.status_label') !== false, 'orders grid must read persisted bank status labels');
assertBankStatus(strpos($grid, "setName('UniCredit статус')") !== false, 'orders grid column name must match Woo UniCredit статус');
assertBankStatus(strpos($grid, 'applyBankStatusLabel') !== false, 'admin order box must overlay live bank status like Woo');

fwrite(STDOUT, "OK (Bank status persistence for admin orders list)\n");
