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

$cpFailed = BankStatus::controlPanelFailure(false);
assertBankStatus($cpFailed['status_id'] === 'bank_send_failed_cp', 'Process 1 CP failure status id must be bank_send_failed_cp');
assertBankStatus($cpFailed['status_label'] === 'Неуспешно изпратен Банка - КП', 'Process 1 CP failure label mismatch');

$process2Failed = BankStatus::controlPanelFailure(true);
assertBankStatus($process2Failed['status_id'] === 'bank_send_failed_cp', 'Process 2 CP failure must use bank_send_failed_cp (not generic bank_send_failed)');
assertBankStatus($process2Failed['status_label'] === 'Неуспешно изпратен Банка - КП', 'Process 2 CP failure label must be Неуспешно изпратен Банка - КП');
assertBankStatus(
    $process2Failed['status_id'] === $cpFailed['status_id']
        && $process2Failed['status_label'] === $cpFailed['status_label'],
    'CP create failure public status is identical for Process 1 and Process 2'
);

$frozenPublicLabels = [
    BankStatus::LABEL_SEND_FAILED_CP,
    BankStatus::LABEL_SEND_FAILED_SMARTUCF,
    BankStatus::LABEL_SENT_PROCESS1,
    BankStatus::LABEL_SENT_PROCESS2,
];
foreach (
    [
        BankStatus::controlPanelFailure(false),
        BankStatus::controlPanelFailure(true),
        BankStatus::smartUcfFailure(),
        BankStatus::successfulSend(false),
        BankStatus::successfulSend(true),
    ] as $status
) {
    assertBankStatus(
        in_array($status['status_label'], $frozenPublicLabels, true),
        'initial public bank status must use frozen vocabulary: ' . $status['status_label']
    );
    assertBankStatus(
        $status['status_label'] !== BankStatus::LABEL_SEND_FAILED,
        'generic Неуспешно изпратен Банка must not be used as public bank status'
    );
}

$popup = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/productpopup.php');
$checkout = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/validatecheckout.php');
$grid = (string) file_get_contents(dirname(__DIR__, 2) . '/unipayment.php');
$gateway = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/NativePrestaShopOrderGateway.php');
assertBankStatus(strpos($popup, 'SmartUcfSessionCoordinator') !== false, 'popup must use shared SmartUCF coordinator');
assertBankStatus(strpos($checkout, 'SmartUcfSessionCoordinator') !== false, 'checkout must use shared SmartUCF coordinator');
assertBankStatus(strpos($grid, 'unipayment_bs.status_label') !== false, 'orders grid must read persisted bank status labels');
assertBankStatus(strpos($grid, "setName('UniCredit статус')") !== false, 'orders grid column name must match Woo UniCredit status');
$lifecycleService = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/PostControlPanelLifecycleService.php');
assertBankStatus(strpos($popup, 'PostControlPanelLifecycleService') !== false, 'popup must use shared post-CP lifecycle service');
assertBankStatus(strpos($checkout, 'PostControlPanelLifecycleService') !== false, 'checkout must use shared post-CP lifecycle service');
assertBankStatus(strpos($lifecycleService, 'BankStatus::smartUcfFailure') !== false, 'lifecycle service must set final bank status before email');
assertBankStatus(strpos($lifecycleService, 'LeasingMailDispatchPort') !== false || strpos($lifecycleService, 'FinancingOrderMailDispatcher') !== false, 'lifecycle service must dispatch leasing email centrally');
assertBankStatus(strpos($gateway, 'DeferredOrderMailQueue::start') !== false, 'Process 1 order_conf must be deferred until SmartUCF');
$orchestratorSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/OrderOrchestrator.php');
assertBankStatus(
    strpos($orchestratorSrc, 'finalizeDefinitiveControlPanelFailureEmails') !== false,
    'Process 1 definitive CP failure must finalize deferred order_conf + leasing emails'
);
assertBankStatus(
    strpos($orchestratorSrc, 'CP_OUTCOME_UNKNOWN') !== false
        && (bool) preg_match(
            '/CP_OUTCOME_UNKNOWN[\s\S]*?DeferredOrderMailQueue::discard\(\)/s',
            $orchestratorSrc
        ),
    'Ambiguous CP create must still discard deferred order_conf'
);
assertBankStatus(strpos($grid, 'hookActionEmailSendBefore') !== false, 'order_conf deferral hook must be registered');
assertBankStatus(strpos($grid, 'hookDisplayPaymentReturn') !== false, 'Process 2 thank-you leasing block hook must be registered');
$payloadBuilder = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/ControlPanelOrderPayloadBuilder.php');
$coordinator = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
assertBankStatus(strpos($payloadBuilder, "'status'") === false && strpos($payloadBuilder, "'status_id'") === false, 'P1/P2 CP create must not send lifecycle status fields');
assertBankStatus(strpos($coordinator, 'synchronizeAfterHandoff') !== false, 'Process 1 success must durable-sync CP status after SmartUCF creation');
assertBankStatus(strpos($lifecycleService, 'synchronizeAfterHandoff') !== false, 'Process 2 success must durable-sync CP status after handoff');
assertBankStatus(strpos($lifecycleService, 'ControlPanelStatusSyncService') !== false || strpos($lifecycleService, 'statusSync') !== false, 'lifecycle wires CP status sync service');
assertBankStatus(strpos($leasingPresenter = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/OrderLeasingDetailsPresenter.php'), 'applyBankStatusLabel') !== false, 'admin/thank-you leasing rows must overlay live bank status like Woo');
$cart = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/cartpopup.php');
assertBankStatus(strpos($cart, 'SmartUcfSessionCoordinator') !== false, 'cart popup must use shared SmartUCF coordinator');
assertBankStatus(strpos($popup, 'createSession(') === false, 'popup must not call createSession directly');
assertBankStatus(strpos($checkout, 'createSession(') === false, 'checkout must not call createSession directly');
assertBankStatus(strpos($cart, 'createSession(') === false, 'cart must not call createSession directly');

$bankRepo = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/OrderBankStatusRepository.php');
assertBankStatus(strpos($bankRepo, 'executeS(sprintf') !== false, 'bank status lookup must use Db::executeS for fail-closed counting');
assertBankStatus(strpos($bankRepo, "LIMIT 1'") === false, 'authorized lookup queries must not include LIMIT 1');

fwrite(STDOUT, "OK (Bank status persistence for admin orders list)\n");
