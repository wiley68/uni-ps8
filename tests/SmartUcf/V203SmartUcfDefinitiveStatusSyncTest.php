<?php

declare(strict_types=1);

/**
 * v2.0.3 definitive SmartUCF failure → durable CP status sync (no best-effort PATCH).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncService;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStates;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStoreInterface;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\SmartUcf\DefinitiveSmartUcfFailureFinalizer;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassifier;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionGatewayInterface;

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        public static function addLog(string $message, int $severity = 1): void {}
    }
}

function assertSuDef(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$classifier = new SmartUcfFailureClassifier();

$preSend = $classifier->classify(new SmartUcfSessionException(
    'local',
    true,
    '',
    0,
    SmartUcfSessionException::KIND_PRE_SEND
));
assertSuDef(
    $preSend->isRetryable()
        && $preSend->errorClass() === SmartUcfFailureClassification::CLASS_PRE_SEND
        && $preSend->targetState() === SmartUcfLifecycleStates::FAILED,
    'pre-send is retryable failed lifecycle, not definitive bank status'
);

$transport = $classifier->classify(new SmartUcfSessionException(
    'timeout',
    false,
    '',
    0,
    SmartUcfSessionException::KIND_TRANSPORT
));
assertSuDef(
    $transport->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN
        && $transport->errorClass() === SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
    'transport stays outcome_unknown'
);

$http5 = $classifier->classify(new SmartUcfSessionException(
    'server',
    false,
    'error',
    503,
    SmartUcfSessionException::KIND_REMOTE
));
assertSuDef($http5->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN, '5xx stays outcome_unknown');

$remote = $classifier->classify(new SmartUcfSessionException(
    'rejected',
    false,
    'rejected',
    400,
    SmartUcfSessionException::KIND_REMOTE
));
assertSuDef(
    !$remote->isRetryable()
        && $remote->errorClass() === SmartUcfFailureClassification::CLASS_REMOTE_REJECT
        && $remote->targetState() === SmartUcfLifecycleStates::FAILED,
    'remote reject is definitive failed'
);

$unexpected = $classifier->classifyThrowable(new RuntimeException('credentials'));
assertSuDef(
    $unexpected->isRetryable() && $unexpected->errorClass() === SmartUcfFailureClassification::CLASS_PRE_SEND,
    'unexpected local throwable maps to pre_send'
);

final class SuDefSnapshots implements FinancingSnapshotStoreInterface, ControlPanelStatusSyncStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var array<int, array<string, mixed>>|null */
    private $working = null;
    /** @var bool */
    public $failNextPendingTarget = false;

    public function beginWork(): void
    {
        $this->working = $this->rows;
    }

    public function commitWork(): void
    {
        if ($this->working !== null) {
            $this->rows = $this->working;
            $this->working = null;
        }
    }

    public function rollbackWork(): void
    {
        $this->working = null;
    }

    /** @return array<int, array<string, mixed>> */
    private function map(): array
    {
        return $this->working ?? $this->rows;
    }

    /** @param array<int, array<string, mixed>> $map */
    private function setMap(array $map): void
    {
        if ($this->working !== null) {
            $this->working = $map;
        } else {
            $this->rows = $map;
        }
    }

    public function save(int $attemptId, array $snapshot): void
    {
        $this->update($attemptId, $snapshot);
    }

    public function findByAttempt(int $attemptId): ?array
    {
        $map = $this->map();

        return $map[$attemptId] ?? null;
    }

    public function update(int $attemptId, array $changes): void
    {
        $map = $this->map();
        $map[$attemptId] = array_replace($map[$attemptId] ?? [], $changes);
        $this->setMap($map);
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        if ($this->failNextPendingTarget) {
            $this->failNextPendingTarget = false;
            throw new RuntimeException('cp target persistence failed');
        }
        $map = $this->map();
        $row = $map[$attemptId] ?? null;
        if ($row === null) {
            return false;
        }
        $state = (string) ($row['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        $statusId = $row['cp_status_sync_status_id'] ?? null;
        $status = $row['cp_status_sync_status'] ?? null;
        $statusId = $statusId === '' ? null : $statusId;
        $status = $status === '' ? null : $status;
        if ($state !== $expectedState || $statusId !== $expectedStatusId || $status !== $expectedStatus) {
            return false;
        }
        $map[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::PENDING;
        $map[$attemptId]['cp_status_sync_status_id'] = $newStatusId;
        $map[$attemptId]['cp_status_sync_status'] = $newStatus;
        $map[$attemptId]['cp_status_sync_error_class'] = null;
        $this->setMap($map);

        return true;
    }

    public function compareAndSetConfirmed(int $attemptId, string $expectedStatusId, string $expectedStatus): bool
    {
        $map = $this->map();
        $row = $map[$attemptId] ?? null;
        if ($row === null || (string) ($row['cp_status_sync_state'] ?? '') !== ControlPanelStatusSyncStates::PENDING) {
            return false;
        }
        if ((string) ($row['cp_status_sync_status_id'] ?? '') !== $expectedStatusId) {
            return false;
        }
        $map[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::CONFIRMED;
        $this->setMap($map);

        return true;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        $map = $this->map();
        $row = $map[$attemptId] ?? null;
        if ($row === null || (string) ($row['cp_status_sync_state'] ?? '') !== ControlPanelStatusSyncStates::PENDING) {
            return false;
        }
        $map[$attemptId]['cp_status_sync_state'] = $newState;
        $map[$attemptId]['cp_status_sync_error_class'] = $errorClass;
        $this->setMap($map);

        return true;
    }
}

final class SuDefCp implements ControlPanelOrderClientInterface
{
    /** @var list<array<string, string>> */
    public $patches = [];
    /** @var list<mixed> */
    public $queue = [];

    public function createOrder(array $payload): array
    {
        throw new RuntimeException('unused');
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        $this->patches[] = compact('orderId', 'status', 'statusId');
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return ['success' => true, 'error' => null, 'data' => [
            'order_id' => $orderId,
            'status' => $status,
            'status_id' => $statusId,
        ]];
    }

    public function reportOrderOrphan(string $orderId, string $orderDate): array
    {
        throw new RuntimeException('orphan must not be used for SmartUCF failure');
    }
}

final class SuDefLifecycle
{
    /** @var array<int, array<string, mixed>> */
    public $marks = [];
    /** @var array<int, array<string, mixed>>|null */
    private $working = null;

    public function beginWork(): void
    {
        $this->working = $this->marks;
    }

    public function commitWork(): void
    {
        if ($this->working !== null) {
            $this->marks = $this->working;
            $this->working = null;
        }
    }

    public function rollbackWork(): void
    {
        $this->working = null;
    }

    public function markFailed(int $attemptId, string $errorClass, bool $retryable, int $httpCode = 0): void
    {
        $row = compact('attemptId', 'errorClass', 'retryable', 'httpCode');
        if ($this->working !== null) {
            $this->working[$attemptId] = $row;
        } else {
            $this->marks[$attemptId] = $row;
        }
    }
}

final class SuDefBank implements BankStatusPersistencePort
{
    /** @var list<array<string, mixed>> */
    public $updates = [];
    /** @var array<int, array<string, mixed>>|null */
    private $working = null;
    /** @var bool */
    public $returnNull = false;

    public function beginWork(): void
    {
        $this->working = $this->updates;
    }

    public function commitWork(): void
    {
        if ($this->working !== null) {
            $this->updates = $this->working;
            $this->working = null;
        }
    }

    public function rollbackWork(): void
    {
        $this->working = null;
    }

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        if ($this->returnNull) {
            return null;
        }
        $row = compact('idShop', 'orderReference', 'statusId', 'statusLabel');
        if ($this->working !== null) {
            $this->working[] = $row;
        } else {
            $this->updates[] = $row;
        }

        return $row;
    }
}

final class SuDefTxnBoundary implements MutationBoundaryInterface
{
    /** @var list<object> */
    private $stores;
    /** @var bool */
    public $committed = false;

    /** @param list<object> $stores */
    public function __construct(array $stores)
    {
        $this->stores = $stores;
    }

    public function runExclusive(string $lockName, callable $callback)
    {
        foreach ($this->stores as $store) {
            $store->beginWork();
        }
        try {
            $result = $callback();
            foreach ($this->stores as $store) {
                $store->commitWork();
            }
            $this->committed = true;

            return $result;
        } catch (Throwable $exception) {
            foreach ($this->stores as $store) {
                $store->rollbackWork();
            }
            $this->committed = false;
            throw $exception;
        }
    }
}

$store = new SuDefSnapshots();
$store->rows[11] = [
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
    'control_panel_order_id' => 99,
];
$cp = new SuDefCp();
$queueOnly = new ControlPanelStatusSyncService($store, null);
$failed = BankStatus::smartUcfFailure();
$queued = $queueOnly->synchronizeAfterHandoff(11, 'SMARTFAIL0001', $failed);
assertSuDef($queued === ControlPanelStatusSyncStates::PENDING, 'queue pending without PATCH');
assertSuDef($store->rows[11]['cp_status_sync_status_id'] === BankStatus::SEND_FAILED_SMARTUCF, 'pending target is bank_send_failed_smartucf');
assertSuDef($cp->patches === [], 'no PATCH before retryPending');

$withClient = new ControlPanelStatusSyncService($store, $cp);
$confirmed = $withClient->retryPending(11, 'SMARTFAIL0001');
assertSuDef($confirmed === ControlPanelStatusSyncStates::CONFIRMED, 'PATCH success confirms');
assertSuDef(count($cp->patches) === 1 && $cp->patches[0]['statusId'] === BankStatus::SEND_FAILED_SMARTUCF, 'one PATCH for smartucf failure');

$store->rows[12] = [
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
];
$cpFail = new SuDefCp();
$cpFail->queue[] = new ConnectionException('down');
$svcFail = new ControlPanelStatusSyncService($store, $cpFail);
$svcFail->synchronizeAfterHandoff(12, 'SMARTFAIL0002', $failed);
assertSuDef($store->rows[12]['cp_status_sync_state'] === ControlPanelStatusSyncStates::PENDING, 'transport leaves pending');

$store->rows[13] = [
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SEND_FAILED_SMARTUCF,
    'cp_status_sync_status' => BankStatus::LABEL_SEND_FAILED_SMARTUCF,
];
$cpSame = new SuDefCp();
$svcSame = new ControlPanelStatusSyncService($store, $cpSame);
assertSuDef($svcSame->retryPending(13, 'SMARTFAIL0003') === ControlPanelStatusSyncStates::CONFIRMED, 'same-status PATCH confirms');

// Missing CP id guard — no bank / no target / no PATCH.
$lifeMissing = new SuDefLifecycle();
$bankMissing = new SuDefBank();
$snapMissing = new SuDefSnapshots();
$snapMissing->rows[21] = [
    'order_reference' => 'NOCPORDER0001',
    'control_panel_order_id' => 0,
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
];
$cpMissing = new SuDefCp();
$finalizerMissing = new DefinitiveSmartUcfFailureFinalizer(
    $lifeMissing,
    $bankMissing,
    $snapMissing,
    new SuDefTxnBoundary([$lifeMissing, $bankMissing, $snapMissing])
);
$okMissing = $finalizerMissing->finalize(21, $snapMissing->rows[21], $remote, 1);
assertSuDef($okMissing === false, 'missing CP id does not commit definitive');
assertSuDef($bankMissing->updates === [], 'missing CP id writes no bank status');
assertSuDef(
    ($snapMissing->rows[21]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::NOT_NEEDED,
    'missing CP id writes no CP target'
);
assertSuDef($cpMissing->patches === [], 'missing CP id never PATCHes');
assertSuDef(
    isset($lifeMissing->marks[21]) && $lifeMissing->marks[21]['retryable'] === true,
    'missing CP id marks retryable lifecycle only'
);

// Causal rollback: lifecycle + bank succeed, CP target fails → all roll back.
$lifeRoll = new SuDefLifecycle();
$bankRoll = new SuDefBank();
$snapRoll = new SuDefSnapshots();
$snapRoll->rows[22] = [
    'order_reference' => 'ROLLSMART0001',
    'control_panel_order_id' => 77,
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
];
$snapRoll->failNextPendingTarget = true;
$boundaryRoll = new SuDefTxnBoundary([$lifeRoll, $bankRoll, $snapRoll]);
$finalizerRoll = new DefinitiveSmartUcfFailureFinalizer($lifeRoll, $bankRoll, $snapRoll, $boundaryRoll);
$okRoll = $finalizerRoll->finalize(22, $snapRoll->rows[22], $remote, 1);
assertSuDef($okRoll === false, 'target failure returns not committed');
assertSuDef($boundaryRoll->committed === false, 'target failure does not commit boundary');
assertSuDef($lifeRoll->marks === [], 'lifecycle rolled back');
assertSuDef($bankRoll->updates === [], 'bank status rolled back');
assertSuDef(
    ($snapRoll->rows[22]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::NOT_NEEDED,
    'CP target rolled back'
);

// Causal rollback: bank-status null after lifecycle mutation.
$lifeBankFail = new SuDefLifecycle();
$bankFail = new SuDefBank();
$bankFail->returnNull = true;
$snapBankFail = new SuDefSnapshots();
$snapBankFail->rows[23] = [
    'order_reference' => 'BANKNULL00001',
    'control_panel_order_id' => 88,
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
];
$boundaryBankFail = new SuDefTxnBoundary([$lifeBankFail, $bankFail, $snapBankFail]);
$finalizerBankFail = new DefinitiveSmartUcfFailureFinalizer(
    $lifeBankFail,
    $bankFail,
    $snapBankFail,
    $boundaryBankFail
);
$okBankFail = $finalizerBankFail->finalize(23, $snapBankFail->rows[23], $remote, 1);
assertSuDef($okBankFail === false, 'null bank status aborts definitive commit');
assertSuDef($lifeBankFail->marks === [], 'null bank rolls back lifecycle');
assertSuDef($bankFail->updates === [], 'null bank leaves no bank row');
assertSuDef(
    ($snapBankFail->rows[23]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::NOT_NEEDED,
    'null bank leaves no CP target'
);

// Happy path: all three commit, PATCH only after.
$lifeOk = new SuDefLifecycle();
$bankOk = new SuDefBank();
$snapOk = new SuDefSnapshots();
$snapOk->rows[24] = [
    'order_reference' => 'SMARTOK000001',
    'control_panel_order_id' => 101,
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
];
$boundaryOk = new SuDefTxnBoundary([$lifeOk, $bankOk, $snapOk]);
$finalizerOk = new DefinitiveSmartUcfFailureFinalizer($lifeOk, $bankOk, $snapOk, $boundaryOk);
$cpOk = new SuDefCp();
assertSuDef($finalizerOk->finalize(24, $snapOk->rows[24], $remote, 1) === true, 'definitive commit succeeds');
assertSuDef($boundaryOk->committed === true, 'boundary committed');
assertSuDef($lifeOk->marks[24]['retryable'] === false, 'lifecycle definitive failed');
assertSuDef($bankOk->updates[0]['statusId'] === BankStatus::SEND_FAILED_SMARTUCF, 'local bank status written');
assertSuDef(
    $snapOk->rows[24]['cp_status_sync_state'] === ControlPanelStatusSyncStates::PENDING
        && $snapOk->rows[24]['cp_status_sync_status_id'] === BankStatus::SEND_FAILED_SMARTUCF,
    'durable pending CP target exists before PATCH'
);
assertSuDef($cpOk->patches === [], 'no PATCH inside finalizer');
$syncOk = new ControlPanelStatusSyncService($snapOk, $cpOk);
assertSuDef($syncOk->retryPending(24, 'SMARTOK000001') === ControlPanelStatusSyncStates::CONFIRMED, 'post-commit PATCH confirms');

// --- Coordinator-level causal paths (wired CP client, zero PATCH on failed finalize) ---
final class SuDefRejectGateway implements SmartUcfSessionGatewayInterface
{
    /** @var int */
    public $createCalls = 0;

    public function createSession(array $shop, array $snapshot, $certificateLease = null): array
    {
        ++$this->createCalls;
        throw new SmartUcfSessionException(
            'rejected',
            false,
            'rejected',
            400,
            SmartUcfSessionException::KIND_REMOTE
        );
    }
}

final class SuDefCoordLifecycle
{
    /** @var array<string, mixed> */
    public $row;
    /** @var array<string, mixed>|null */
    private $workingRow = null;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function beginWork(): void
    {
        $this->workingRow = $this->row;
    }

    public function commitWork(): void
    {
        if ($this->workingRow !== null) {
            $this->row = $this->workingRow;
            $this->workingRow = null;
        }
    }

    public function rollbackWork(): void
    {
        $this->workingRow = null;
    }

    /** @return array<string, mixed> */
    private function active(): array
    {
        return $this->workingRow ?? $this->row;
    }

    /** @param array<string, mixed> $row */
    private function write(array $row): void
    {
        if ($this->workingRow !== null) {
            $this->workingRow = $row;
        } else {
            $this->row = $row;
        }
    }

    public function readAndNormalize(int $attemptId): ?array
    {
        return $this->active();
    }

    public function claimForSubmitting(int $attemptId): ?array
    {
        $row = $this->active();
        $state = (string) ($row['smartucf_state'] ?? '');
        $retryable = !empty($row['smartucf_retryable']);
        if (
            $state === SmartUcfLifecycleStates::NOT_STARTED
            || ($state === SmartUcfLifecycleStates::FAILED && $retryable)
        ) {
            $row['smartucf_state'] = SmartUcfLifecycleStates::SUBMITTING;
            $row['smartucf_retryable'] = 0;
            $this->write($row);

            return $row;
        }

        return null;
    }

    public function markFailed(int $attemptId, string $errorClass, bool $retryable, int $httpCode = 0): void
    {
        $row = $this->active();
        $row['smartucf_state'] = SmartUcfLifecycleStates::FAILED;
        $row['smartucf_error_class'] = $errorClass;
        $row['smartucf_retryable'] = $retryable ? 1 : 0;
        $row['smartucf_http_code'] = $httpCode;
        $this->write($row);
    }
}

/**
 * @param object $lifecycle
 */
function suDefCoordinatorWith(
    $lifecycle,
    SuDefRejectGateway $gateway,
    DefinitiveSmartUcfFailureFinalizer $finalizer,
    ControlPanelStatusSyncService $statusSync,
    SuDefBank $bank,
    object $context
): SmartUcfSessionCoordinator {
    $ref = new ReflectionClass(SmartUcfSessionCoordinator::class);
    /** @var SmartUcfSessionCoordinator $coordinator */
    $coordinator = $ref->newInstanceWithoutConstructor();
    foreach (
        [
            'lifecycle' => $lifecycle,
            'client' => $gateway,
            'payloadBuilder' => new SmartUcfPayloadBuilder(),
            'classifier' => new SmartUcfFailureClassifier(),
            'snapshots' => null,
            'cpClient' => null,
            'controlPanelApi' => null,
            'certificateSynchronizer' => null,
            'module' => null,
            'context' => $context,
            'statusSync' => $statusSync,
            'definitiveFailureFinalizer' => $finalizer,
            'bankStatus' => $bank,
        ] as $name => $value
    ) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($coordinator, $value);
    }

    return $coordinator;
}

if (!class_exists('Context', false)) {
    class Context
    {
        /** @var object|null */
        public $shop;
    }
}

$coordShop = [
    '_currency_iso' => 'BGN',
    'uni_user' => 'demo-user',
    'uni_password' => 'demo-pass',
    'uni_sertificat' => 0,
    'uni_env' => 0,
];
$coordContext = new Context();
$coordContext->shop = (object) ['id' => 1];
$coordSnapshotBase = [
    'id_attempt' => 31,
    'id_order' => 31,
    'order_reference' => 'COORDFAIL0001',
    'control_panel_order_id' => 555,
    'currency_iso' => 'BGN',
    'kop_code' => 'KOP1',
    'order_total' => 100.0,
    'first_installment' => 0.0,
    'months' => 12,
    'monthly_installment' => 10.0,
    'customer_json' => [
        'first_name' => 'Ivan',
        'last_name' => 'Ivanov',
        'phone' => '+359888',
        'email' => 'ivan@example.com',
    ],
    'lines_json' => [['name' => 'Item', 'id_product' => 1, 'quantity' => 1, 'total' => 100]],
    'address_json' => ['delivery' => ['address1' => 'Sofia', 'city' => 'Sofia', 'postcode' => '1000']],
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
];

// Bank-status null through coordinator → no PATCH on wired client.
$lifeBank = new SuDefCoordLifecycle([
    'id_attempt' => 31,
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_retryable' => 0,
]);
$bankCoord = new SuDefBank();
$bankCoord->returnNull = true;
$snapCoord = new SuDefSnapshots();
$snapCoord->rows[31] = $coordSnapshotBase;
$cpCoord = new SuDefCp();
$boundaryCoord = new SuDefTxnBoundary([$lifeBank, $bankCoord, $snapCoord]);
$finalizerCoord = new DefinitiveSmartUcfFailureFinalizer($lifeBank, $bankCoord, $snapCoord, $boundaryCoord);
$statusCoord = new ControlPanelStatusSyncService($snapCoord, $cpCoord);
$gatewayCoord = new SuDefRejectGateway();
$coordBankFail = suDefCoordinatorWith($lifeBank, $gatewayCoord, $finalizerCoord, $statusCoord, $bankCoord, $coordContext);
$resultBankFail = $coordBankFail->run(31, $coordShop, false, $coordSnapshotBase);
assertSuDef($resultBankFail->isFailed(), 'coordinator bank-null path returns failed');
assertSuDef($boundaryCoord->committed === false, 'coordinator bank-null did not commit');
assertSuDef($bankCoord->updates === [], 'coordinator bank-null: no bank_send_failed_smartucf');
assertSuDef(
    ($snapCoord->rows[31]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::NOT_NEEDED,
    'coordinator bank-null: no durable CP target'
);
assertSuDef(count($cpCoord->patches) === 0, 'coordinator bank-null: wired CP PATCH count is 0');
assertSuDef(
    (string) ($lifeBank->row['smartucf_state'] ?? '') !== SmartUcfLifecycleStates::FAILED
        || !empty($lifeBank->row['smartucf_retryable']),
    'coordinator bank-null: no definitive lifecycle commit'
);

// CP-target failure through coordinator → no PATCH on wired client.
$lifeTarget = new SuDefCoordLifecycle([
    'id_attempt' => 32,
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_retryable' => 0,
]);
$bankTarget = new SuDefBank();
$snapTarget = new SuDefSnapshots();
$snapTarget->rows[32] = array_replace($coordSnapshotBase, [
    'id_attempt' => 32,
    'id_order' => 32,
    'order_reference' => 'COORDFAIL0002',
]);
$snapTarget->failNextPendingTarget = true;
$cpTarget = new SuDefCp();
$boundaryTarget = new SuDefTxnBoundary([$lifeTarget, $bankTarget, $snapTarget]);
$finalizerTarget = new DefinitiveSmartUcfFailureFinalizer($lifeTarget, $bankTarget, $snapTarget, $boundaryTarget);
$statusTarget = new ControlPanelStatusSyncService($snapTarget, $cpTarget);
$gatewayTarget = new SuDefRejectGateway();
$coordTargetFail = suDefCoordinatorWith($lifeTarget, $gatewayTarget, $finalizerTarget, $statusTarget, $bankTarget, $coordContext);
$resultTargetFail = $coordTargetFail->run(32, $coordShop, false, $snapTarget->rows[32]);
assertSuDef($resultTargetFail->isFailed(), 'coordinator target-fail path returns failed');
assertSuDef($boundaryTarget->committed === false, 'coordinator target-fail did not commit');
assertSuDef($bankTarget->updates === [], 'coordinator target-fail: bank rolled back');
assertSuDef(
    ($snapTarget->rows[32]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::NOT_NEEDED,
    'coordinator target-fail: no durable CP target'
);
assertSuDef(count($cpTarget->patches) === 0, 'coordinator target-fail: wired CP PATCH count is 0');

// Post-commit gate: successful finalize through coordinator → exactly one PATCH on wired client.
$lifeGate = new SuDefCoordLifecycle([
    'id_attempt' => 33,
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_retryable' => 0,
]);
$bankGate = new SuDefBank();
$snapGate = new SuDefSnapshots();
$snapGate->rows[33] = array_replace($coordSnapshotBase, [
    'id_attempt' => 33,
    'id_order' => 33,
    'order_reference' => 'COORDGATE0003',
]);
$cpGate = new SuDefCp();
$boundaryGate = new SuDefTxnBoundary([$lifeGate, $bankGate, $snapGate]);
$finalizerGate = new DefinitiveSmartUcfFailureFinalizer($lifeGate, $bankGate, $snapGate, $boundaryGate);
$statusGate = new ControlPanelStatusSyncService($snapGate, $cpGate);
$gatewayGate = new SuDefRejectGateway();
$coordGate = suDefCoordinatorWith($lifeGate, $gatewayGate, $finalizerGate, $statusGate, $bankGate, $coordContext);
$resultGate = $coordGate->run(33, $coordShop, false, $snapGate->rows[33]);
assertSuDef($resultGate->isFailed() && !$resultGate->isRetryable(), 'coordinator success gate: definitive failed');
assertSuDef($boundaryGate->committed === true, 'coordinator success gate: transaction committed');
assertSuDef(
    $snapGate->rows[33]['cp_status_sync_state'] === ControlPanelStatusSyncStates::CONFIRMED
        || $snapGate->rows[33]['cp_status_sync_state'] === ControlPanelStatusSyncStates::PENDING,
    'coordinator success gate: durable target exists after finalize'
);
assertSuDef(count($cpGate->patches) === 1, 'coordinator success gate: PATCH only after commit');
assertSuDef($cpGate->patches[0]['statusId'] === BankStatus::SEND_FAILED_SMARTUCF, 'coordinator success gate: PATCH target');

$coordinator = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
assertSuDef(strpos($coordinator, 'finalizeDefinitiveRemoteFailure') !== false, 'coordinator uses durable finalizer');
assertSuDef(strpos($coordinator, 'CLASS_REMOTE_REJECT') !== false, 'only remote reject becomes definitive');
assertSuDef(strpos($coordinator, 'updateOrderStatus(') === false, 'no direct best-effort PATCH in coordinator');
assertSuDef(strpos($coordinator, 'DefinitiveSmartUcfFailureFinalizer') !== false, 'coordinator owns DefinitiveSmartUcfFailureFinalizer');

fwrite(STDOUT, "OK (v2.0.3 SmartUCF definitive durable CP status sync)\n");
