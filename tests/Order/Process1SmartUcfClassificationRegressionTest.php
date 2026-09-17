<?php

declare(strict_types=1);

/**
 * Process 1 SmartUCF classification regression:
 * - valid sucfOnlineSessionID → bank_sent_process1 + redirect
 * - definitive remote reject → bank_send_failed_smartucf
 * - transport ambiguity → not Process 1 success / created
 * - Product popup mapper exposes redirect, not generic error
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecyclePopupMapper;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassifier;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionClient;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static $logs = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$logs[] = $message;
            unset($severity);
        }
    }
}

function assertP1Smart(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class P1SmartMemorySnapshots implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private $rows = [];

    /** @param array<string, mixed> $snapshot */
    public function seed(int $attemptId, array $snapshot): void
    {
        $this->rows[$attemptId] = $snapshot;
    }

    public function save(int $attemptId, array $snapshot): void
    {
        $this->rows[$attemptId] = $snapshot;
    }

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->rows[$attemptId] ?? null;
    }

    public function update(int $attemptId, array $changes): void
    {
        if (!isset($this->rows[$attemptId])) {
            return;
        }
        $this->rows[$attemptId] = array_merge($this->rows[$attemptId], $changes);
    }
}

final class P1SmartFakePort implements PostControlPanelSmartUcfPort
{
    /** @var SmartUcfCoordinationResult */
    public $next;

    public function __construct(SmartUcfCoordinationResult $next)
    {
        $this->next = $next;
    }

    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult
    {
        unset($attemptId, $shop, $process2, $snapshot);

        return $this->next;
    }

    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        unset($attemptId, $shop, $process2);

        return $this->next;
    }
}

final class P1SmartMailSpy implements LeasingMailDispatchPort
{
    /** @var list<array{status_id: string, status_label: string}> */
    public $statuses = [];

    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop);
        $this->statuses[] = $status;
    }
}

final class P1SmartBankSpy implements \PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort
{
    /** @var list<string> */
    public $ids = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        unset($idShop, $orderReference, $statusLabel);
        $this->ids[] = $statusId;

        return ['ps_order_id' => 1];
    }
}

$order = new OrderOrchestrationResult(42, 'cp_created', 120, 'FAMWPNNSF', 373);
$shop = ['uni_proces' => 0];
$snapshot = ['currency_iso' => 'BGN', 'customer_json' => [], 'address_json' => []];
$context = new PostControlPanelLifecycleContext(1, 'BGN');

$trustedRedirect = (new SmartUcfEndpointPolicy())->buildApplicationRedirect(
    'https://online.ucfin.bg/sucf-online/Request/Start',
    'SESSION-P1-OK-001'
);

// --- A. Realistic success fixture: sucfOnlineSessionID parsed ---
$successJson = '{"sucfOnlineSessionID":"SESSION-P1-OK-001","errorCode":0,"errorText":""}';
$decoded = json_decode($successJson, false);
assertP1Smart(is_object($decoded), 'A: fixture JSON decodes');
$sessionId = isset($decoded->sucfOnlineSessionID) ? trim((string) $decoded->sucfOnlineSessionID) : '';
assertP1Smart($sessionId === 'SESSION-P1-OK-001', 'A: valid SmartUCF session parsed from sucfOnlineSessionID');

$clientSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/SmartUcfSessionClient.php');
assertP1Smart(
    strpos($clientSrc, 'sucfOnlineSessionID') !== false,
    'A: SmartUcfSessionClient uses sucfOnlineSessionID success field'
);

// --- B. Success classification → bank_sent_process1 + redirect + emails ---
$storeB = new P1SmartMemorySnapshots();
$storeB->seed(42, $snapshot);
$mailB = new P1SmartMailSpy();
$bankB = new P1SmartBankSpy();
$resultB = (new PostControlPanelLifecycleService($storeB, $mailB, $bankB))->handle(
    $order,
    $shop,
    $context,
    new P1SmartFakePort(SmartUcfCoordinationResult::created($trustedRedirect, 'SESSION-P1-OK-001'))
);
assertP1Smart($resultB->isCreated(), 'B: success classification is created');
assertP1Smart($resultB->redirectUrl() === $trustedRedirect, 'B: frontend redirect URL present');
assertP1Smart(
    ($resultB->finalBankStatus()['status_id'] ?? '') === BankStatus::SENT_PROCESS1,
    'B: bank_sent_process1 persisted on lifecycle result'
);
assertP1Smart(
    ($resultB->finalBankStatus()['status_label'] ?? '') === BankStatus::LABEL_SENT_PROCESS1,
    'B: label Изпратен Банка - Процес 1'
);
assertP1Smart($resultB->emailSent() === true, 'B: leasing emails dispatched');
assertP1Smart(count($mailB->statuses) === 1, 'B: one email status payload');
assertP1Smart(
    ($mailB->statuses[0]['status_id'] ?? '') === BankStatus::SENT_PROCESS1
        && ($mailB->statuses[0]['status_label'] ?? '') === BankStatus::LABEL_SENT_PROCESS1,
    'B: standard emails use Изпратен Банка - Процес 1'
);

$responseB = ['success' => true, 'step' => 'order_created'];
PostControlPanelLifecyclePopupMapper::apply($responseB, $resultB);
assertP1Smart(($responseB['redirect_url'] ?? '') === $trustedRedirect, 'B: Product popup redirect payload');
assertP1Smart(!isset($responseB['smartucf_error']), 'B: no generic SmartUCF error popup field');
assertP1Smart(($responseB['step'] ?? '') === 'order_created', 'B: Product step remains order_created');

// --- C. Definitive remote reject → bank_send_failed_smartucf ---
$classifier = new SmartUcfFailureClassifier();
$remote = $classifier->classify(new SmartUcfSessionException(
    'SmartUCF did not return a session identifier.',
    false,
    '{"errorCode":1,"errorText":"Rejected by bank","sucfOnlineSessionID":""}',
    400,
    SmartUcfSessionException::KIND_REMOTE
));
assertP1Smart($remote->targetState() === SmartUcfLifecycleStates::FAILED, 'C: remote reject → failed');
assertP1Smart($remote->errorClass() === SmartUcfFailureClassification::CLASS_REMOTE_REJECT, 'C: remote_reject class');
assertP1Smart($remote->isRetryable() === false, 'C: remote reject not retryable');

$storeC = new P1SmartMemorySnapshots();
$storeC->seed(42, $snapshot);
$mailC = new P1SmartMailSpy();
$bankC = new P1SmartBankSpy();
$resultC = (new PostControlPanelLifecycleService($storeC, $mailC, $bankC))->handle(
    $order,
    $shop,
    $context,
    new P1SmartFakePort(SmartUcfCoordinationResult::failed(
        SmartUcfSessionCoordinator::CUSTOMER_FAILED,
        false,
        SmartUcfFailureClassification::CLASS_REMOTE_REJECT
    ))
);
assertP1Smart($resultC->isFailed(), 'C: lifecycle failed');
assertP1Smart(
    ($resultC->finalBankStatus()['status_id'] ?? '') === BankStatus::SEND_FAILED_SMARTUCF,
    'C: bank_send_failed_smartucf'
);
assertP1Smart(
    $bankC->ids === [BankStatus::SEND_FAILED_SMARTUCF],
    'C: shop bank status persisted as SmartUCF failure'
);
assertP1Smart(
    ($mailC->statuses[0]['status_label'] ?? '') === BankStatus::LABEL_SEND_FAILED_SMARTUCF,
    'C: emails use Неуспешно изпратен Банка - SmartUCF'
);

$responseC = ['success' => true, 'step' => 'order_created'];
PostControlPanelLifecyclePopupMapper::apply($responseC, $resultC);
assertP1Smart(isset($responseC['smartucf_error']), 'C: Product shows SmartUCF error');
assertP1Smart(!isset($responseC['redirect_url']), 'C: Product must not redirect on reject');

// --- D. Transport ambiguity → not Process 1 created/success ---
$transport = $classifier->classify(new SmartUcfSessionException(
    'SmartUCF connection failed: Operation timed out',
    false,
    '',
    0,
    SmartUcfSessionException::KIND_TRANSPORT
));
assertP1Smart($transport->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN, 'D: timeout → outcome_unknown');
assertP1Smart(
    $transport->errorClass() === SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
    'D: transport_ambiguous class'
);

$storeD = new P1SmartMemorySnapshots();
$storeD->seed(42, $snapshot);
$mailD = new P1SmartMailSpy();
$bankD = new P1SmartBankSpy();
$resultD = (new PostControlPanelLifecycleService($storeD, $mailD, $bankD))->handle(
    $order,
    $shop,
    $context,
    new P1SmartFakePort(SmartUcfCoordinationResult::outcomeUnknown(
        SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN
    ))
);
assertP1Smart($resultD->isOutcomeUnknown(), 'D: lifecycle outcome_unknown');
assertP1Smart(!$resultD->isCreated(), 'D: transport is not created/success');
assertP1Smart($resultD->redirectUrl() === '', 'D: no SmartUCF redirect on transport ambiguity');
assertP1Smart($bankD->ids === [], 'D: must not persist bank_sent_process1 from transport path');

$responseD = ['success' => true, 'step' => 'order_created'];
PostControlPanelLifecyclePopupMapper::apply($responseD, $resultD);
assertP1Smart(($responseD['step'] ?? '') === 'outcome_unknown', 'D: Product step outcome_unknown');
assertP1Smart(!isset($responseD['redirect_url']), 'D: Product no redirect on transport');

// --- E. Pre-send must not claim definitive SmartUCF rejection status ---
$storeE = new P1SmartMemorySnapshots();
$storeE->seed(42, $snapshot);
$mailE = new P1SmartMailSpy();
$bankE = new P1SmartBankSpy();
$resultE = (new PostControlPanelLifecycleService($storeE, $mailE, $bankE))->handle(
    $order,
    $shop,
    $context,
    new P1SmartFakePort(SmartUcfCoordinationResult::failed(
        SmartUcfSessionCoordinator::CUSTOMER_FAILED,
        true,
        SmartUcfFailureClassification::CLASS_PRE_SEND
    ))
);
assertP1Smart($resultE->isFailed(), 'E: pre-send still customer-failed');
assertP1Smart($resultE->finalBankStatus() === null, 'E: pre-send must not set bank_send_failed_smartucf');
assertP1Smart($bankE->ids === [], 'E: pre-send must not persist SmartUCF failure bank status');
assertP1Smart($mailE->statuses === [], 'E: pre-send must not finalize leasing emails');

$responseE = ['success' => true, 'step' => 'order_created'];
PostControlPanelLifecyclePopupMapper::apply($responseE, $resultE);
assertP1Smart(isset($responseE['smartucf_error']), 'E: Product still shows error on pre-send');
assertP1Smart(!isset($responseE['redirect_url']), 'E: Product no redirect on pre-send');

// --- F. Product controller still uses shared lifecycle (not a product-only branch) ---
$productPopup = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/productpopup.php');
assertP1Smart(strpos($productPopup, 'PostControlPanelLifecycleService') !== false, 'F: Product uses shared lifecycle');
assertP1Smart(strpos($productPopup, 'PostControlPanelLifecyclePopupMapper') !== false, 'F: Product uses shared mapper');

fwrite(STDOUT, "OK (Process 1 SmartUCF classification regression)\n");
