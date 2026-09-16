<?php

declare(strict_types=1);

/**
 * v2.0.3 Control Panel create-failure classifier + orphan durability contracts.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\ControlPanelCreateFailureService;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\DefinitiveCpCreateFailureFinalizer;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrphanReportSyncService;
use PrestaShop\Module\Unipayment\Order\OrphanSyncRepository;
use PrestaShop\Module\Unipayment\Order\OrphanSyncStates;
use PrestaShop\Module\Unipayment\Order\OrphanSyncStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderAttemptStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PrestaShopOrderGatewayInterface;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static $logs = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$logs[] = $message;
        }
    }
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        return addslashes($string);
    }
}

function assertV203(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$classifier = new ControlPanelCreateFailureService();

$busy = $classifier->classifyThrowable(new HttpException(503, ['error' => 'lifecycle_busy', 'data' => ['result' => 'busy']]));
assertV203($busy->isRetryable() && !$busy->shouldWriteBankSendFailedCp() && !$busy->shouldCreateOrphanIntent(), 'lifecycle_busy retryable, no bank/orphan');
assertV203($busy->attemptState() === OrderOrchestrator::CP_FAILED_RETRYABLE, 'lifecycle_busy state');

$timeout = $classifier->classifyThrowable(new TimeoutException('timeout'));
assertV203($timeout->isRetryable() && !$timeout->shouldWriteBankSendFailedCp() && $timeout->attemptState() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'timeout ambiguous');

$conn = $classifier->classifyThrowable(new ConnectionException('down'));
assertV203($conn->isRetryable() && !$conn->shouldWriteBankSendFailedCp(), 'connection ambiguous');

$http5 = $classifier->classifyThrowable(new HttpException(500, []));
assertV203($http5->isRetryable() && !$http5->shouldWriteBankSendFailedCp() && $http5->attemptState() === OrderOrchestrator::CP_FAILED_RETRYABLE, '5xx retryable no bank');

$missing = $classifier->classifyMissingControlPanelOrderId();
assertV203($missing->isRetryable() && !$missing->shouldWriteBankSendFailedCp() && $missing->attemptState() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'missing CP id ambiguous');

foreach (['invalid_payload', 'semantic_conflict'] as $code) {
    $reject = $classifier->classifyThrowable(new HttpException(422, ['error' => $code]));
    assertV203(
        $reject->isDefinitive() && $reject->shouldWriteBankSendFailedCp() && $reject->shouldCreateOrphanIntent(),
        $code . ' definitive + orphan'
    );
}

foreach (['unsupported_status', 'shop_not_found', 'order_not_found', 'totally_unknown'] as $code) {
    $notDef = $classifier->classifyThrowable(new HttpException(422, ['error' => $code]));
    assertV203(
        !$notDef->isDefinitive() && !$notDef->shouldWriteBankSendFailedCp() && !$notDef->shouldCreateOrphanIntent(),
        $code . ' NOT definitive / no bank / no orphan'
    );
}

$bare404 = $classifier->classifyThrowable(new HttpException(404, []));
assertV203(!$bare404->isDefinitive() && !$bare404->shouldWriteBankSendFailedCp(), 'bare 404 not definitive');

final class V203MemoryOrphanStore implements OrphanSyncStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var int */
    private $seq = 0;
    /** @var bool */
    public $failNextEnsure = false;
    /** @var array<int, array<string, mixed>>|null */
    private $working = null;

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

    public function ensurePendingIntent(
        int $idShop,
        int $idOrder,
        string $orderId,
        string $orderDate,
        string $statusId,
        string $eventType = OrphanSyncStates::EVENT_CP_CREATE_ORPHAN
    ): array {
        if ($this->failNextEnsure) {
            $this->failNextEnsure = false;
            throw new RuntimeException('orphan insert failed');
        }
        $map = $this->map();
        foreach ($map as $row) {
            if ((int) $row['id_shop'] === $idShop && $row['order_id'] === $orderId && $row['event_type'] === $eventType) {
                return $row;
            }
        }
        ++$this->seq;
        $row = [
            'id_orphan_sync' => $this->seq,
            'id_shop' => $idShop,
            'id_order' => $idOrder,
            'order_id' => $orderId,
            'event_type' => $eventType,
            'order_date' => $orderDate,
            'status_id' => $statusId,
            'state' => OrphanSyncStates::PENDING,
            'last_result' => null,
            'last_error_class' => null,
            'attempt_count' => 0,
            'next_attempt_at' => null,
        ];
        $map[$this->seq] = $row;
        $this->setMap($map);

        return $row;
    }

    public function claimDuePending(int $idOrphanSync, int $backoffSeconds, int $nowTimestamp): ?array
    {
        $map = $this->map();
        if (!isset($map[$idOrphanSync])) {
            return null;
        }
        $row = $map[$idOrphanSync];
        if ($row['state'] !== OrphanSyncStates::PENDING) {
            return null;
        }
        $next = $row['next_attempt_at'];
        if ($next !== null && strtotime((string) $next) > $nowTimestamp) {
            return null;
        }
        $row['attempt_count'] = (int) $row['attempt_count'] + 1;
        $row['next_attempt_at'] = gmdate('Y-m-d H:i:s', $nowTimestamp + $backoffSeconds);
        $map[$idOrphanSync] = $row;
        $this->setMap($map);

        return $row;
    }

    public function findDuePending(int $limit, int $nowTimestamp): array
    {
        $out = [];
        foreach ($this->map() as $row) {
            if ($row['state'] !== OrphanSyncStates::PENDING) {
                continue;
            }
            $next = $row['next_attempt_at'];
            if ($next !== null && strtotime((string) $next) > $nowTimestamp) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function markConfirmed(int $idOrphanSync, string $result): bool
    {
        $map = $this->map();
        if (!isset($map[$idOrphanSync]) || $map[$idOrphanSync]['state'] !== OrphanSyncStates::PENDING) {
            return false;
        }
        $map[$idOrphanSync]['state'] = OrphanSyncStates::CONFIRMED;
        $map[$idOrphanSync]['last_result'] = $result;
        $this->setMap($map);

        return true;
    }

    public function markRetryable(int $idOrphanSync, string $result, string $errorClass, int $nextAttemptAt): bool
    {
        $map = $this->map();
        if (!isset($map[$idOrphanSync])) {
            return false;
        }
        $map[$idOrphanSync]['state'] = OrphanSyncStates::PENDING;
        $map[$idOrphanSync]['last_result'] = $result;
        $map[$idOrphanSync]['last_error_class'] = $errorClass;
        $map[$idOrphanSync]['next_attempt_at'] = gmdate('Y-m-d H:i:s', $nextAttemptAt);
        $this->setMap($map);

        return true;
    }

    public function markTerminalFailed(int $idOrphanSync, string $result, string $errorClass): bool
    {
        $map = $this->map();
        if (!isset($map[$idOrphanSync])) {
            return false;
        }
        $map[$idOrphanSync]['state'] = OrphanSyncStates::TERMINAL_FAILED;
        $map[$idOrphanSync]['last_result'] = $result;
        $map[$idOrphanSync]['last_error_class'] = $errorClass;
        $this->setMap($map);

        return true;
    }

    public function findByIdentity(int $idShop, string $orderId, string $eventType = OrphanSyncStates::EVENT_CP_CREATE_ORPHAN): ?array
    {
        foreach ($this->map() as $row) {
            if ((int) $row['id_shop'] === $idShop && $row['order_id'] === $orderId && $row['event_type'] === $eventType) {
                return $row;
            }
        }

        return null;
    }
}

final class V203OrphanCp implements ControlPanelOrderClientInterface
{
    /** @var list<array<string, mixed>> */
    public $orphanCalls = [];
    /** @var list<mixed> */
    public $queue = [];
    /** @var int */
    public $createCalls = 0;

    public function createOrder(array $payload): array
    {
        ++$this->createCalls;
        throw new RuntimeException('create must not be called during orphan retry');
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        throw new RuntimeException('status patch must not be used for orphan');
    }

    public function reportOrderOrphan(string $orderId, string $orderDate): array
    {
        $this->orphanCalls[] = [
            'order_id' => $orderId,
            'order_date' => $orderDate,
            'status_id' => 'bank_send_failed_cp',
        ];
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return is_array($next) ? $next : [
            'success' => true,
            'error' => null,
            'data' => ['result' => 'notified', 'order_id' => $orderId],
        ];
    }
}

$now = 1_700_000_000;
$clock = static function () use (&$now): int {
    return $now;
};
$store = new V203MemoryOrphanStore();
$cp = new V203OrphanCp();
$service = new OrphanReportSyncService($store, $cp, $clock);

$intent = $service->ensurePendingIntent(1, 55, 'ABCD123456789', '2026-09-16');
$again = $service->ensurePendingIntent(1, 55, 'ABCD123456789', '2026-09-16');
assertV203(count($store->rows) === 1 && (int) $intent['id_orphan_sync'] === (int) $again['id_orphan_sync'], 'unique orphan intent');

foreach (['notified', 'already_reported', 'ignored_cp_order_exists', 'skipped_no_recipient'] as $terminal) {
    $tStore = new V203MemoryOrphanStore();
    $tCp = new V203OrphanCp();
    $tCp->queue[] = ['success' => true, 'error' => null, 'data' => ['result' => $terminal, 'order_id' => 'TERM' . substr($terminal, 0, 8)]];
    $tSvc = new OrphanReportSyncService($tStore, $tCp, $clock);
    $tIntent = $tSvc->ensurePendingIntent(9, 91, 'TERM' . substr($terminal, 0, 8), '2026-09-16');
    $tDelivery = $tSvc->attemptDelivery($tIntent);
    assertV203($tDelivery->isTerminal() && $tDelivery->result() === $terminal, $terminal . ' is terminal');
    assertV203($tStore->rows[1]['state'] === OrphanSyncStates::CONFIRMED, $terminal . ' → confirmed');
    $blocked = $tSvc->attemptDelivery($tStore->rows[1]);
    assertV203($blocked->errorClass() === 'orphan_claim_miss', $terminal . ' no later retry');
    assertV203(count($tCp->orphanCalls) === 1, $terminal . ' single POST');
}

$retryCases = [
    ['label' => 'busy', 'queue' => new HttpException(503, ['error' => 'internal_error', 'data' => ['result' => 'busy']])],
    ['label' => 'mail_failed', 'queue' => ['success' => true, 'error' => null, 'data' => ['result' => 'mail_failed']]],
    ['label' => 'lifecycle_busy', 'queue' => new HttpException(503, ['error' => 'lifecycle_busy', 'data' => ['result' => 'busy']])],
    ['label' => 'transport', 'queue' => new ConnectionException('down')],
    ['label' => 'timeout', 'queue' => new TimeoutException('slow')],
    ['label' => 'http5xx', 'queue' => new HttpException(502, [])],
    ['label' => 'malformed', 'queue' => ['success' => true, 'error' => null, 'data' => []]],
    ['label' => 'unknown_result', 'queue' => ['success' => true, 'error' => null, 'data' => ['result' => 'weird_new_code']]],
    ['label' => 'auth', 'queue' => new AuthenticationException('401')],
    ['label' => 'rate_limit', 'queue' => new HttpException(429, ['error' => 'rate_limited'])],
];
foreach ($retryCases as $case) {
    $rStore = new V203MemoryOrphanStore();
    $rCp = new V203OrphanCp();
    $rCp->queue[] = $case['queue'];
    $rSvc = new OrphanReportSyncService($rStore, $rCp, $clock);
    $pending = $rSvc->ensurePendingIntent(2, 70, 'R' . substr(md5($case['label']), 0, 12), '2026-09-16');
    $retryable = $rSvc->attemptDelivery($pending);
    assertV203(!$retryable->isTerminal(), $case['label'] . ' remains retryable');
    assertV203($rStore->rows[1]['state'] === OrphanSyncStates::PENDING, $case['label'] . ' stays pending');
    assertV203($rCp->createCalls === 0, $case['label'] . ' never retries CP create');
}

$store2 = new V203MemoryOrphanStore();
$cp2 = new V203OrphanCp();
$cp2->queue[] = new HttpException(503, ['error' => 'internal_error', 'data' => ['result' => 'busy', 'order_id' => 'ORPHANRETRY01']]);
$svc2 = new OrphanReportSyncService($store2, $cp2, $clock);
$pending = $svc2->ensurePendingIntent(2, 70, 'ORPHANRETRY01', '2026-09-16');
$retryable = $svc2->attemptDelivery($pending);
assertV203(!$retryable->isTerminal() && $retryable->result() === 'busy', 'busy remains retryable');
assertV203($store2->rows[1]['state'] === OrphanSyncStates::PENDING, 'pending after busy');
assertV203((int) $store2->rows[1]['attempt_count'] === 1, 'attempt_count increments');
$nextAt = strtotime((string) $store2->rows[1]['next_attempt_at']);
assertV203($nextAt > $now, 'next_attempt_at advances');

$blocked = $svc2->attemptDelivery($store2->rows[1]);
assertV203($blocked->errorClass() === 'orphan_claim_miss', 'immediate retry blocked');
assertV203(count($cp2->orphanCalls) === 1, 'no second POST while claimed');

$expectedBackoff = [30, 60, 120, 240, 480, 900, 900, 900, 900];
foreach ($expectedBackoff as $i => $seconds) {
    $attempt = $i + 1;
    assertV203($service->backoffSeconds($attempt) === $seconds, 'backoff attempt ' . $attempt . ' = ' . $seconds);
}
assertV203($service->backoffSeconds(100) === 900, 'backoff capped at 900');

$store3 = new V203MemoryOrphanStore();
$cp3 = new V203OrphanCp();
$svc3 = new OrphanReportSyncService($store3, $cp3, $clock);
$shared = $svc3->ensurePendingIntent(3, 80, 'CONCUR0000001', '2026-09-16');
$first = $store3->claimDuePending((int) $shared['id_orphan_sync'], 60, $now);
$second = $store3->claimDuePending((int) $shared['id_orphan_sync'], 60, $now);
assertV203($first !== null && $second === null, 'CAS claim: only one flusher wins');

// SQL-level CAS claim: Affected_Rows gate.
final class V203OrphanCasDb
{
    /** @var array<string, mixed>|null */
    public $row;
    /** @var int */
    public $affectedRows = 1;
    /** @var int */
    public $updateCount = 0;

    public function execute(string $sql): bool
    {
        if (stripos($sql, 'UPDATE') === 0) {
            ++$this->updateCount;
        }

        return true;
    }

    public function Affected_Rows(): int
    {
        return $this->affectedRows;
    }

    public function getRow(string $sql)
    {
        return $this->row;
    }

    public function executeS(string $sql)
    {
        return $this->row !== null ? [$this->row] : [];
    }
}

$casDb = new V203OrphanCasDb();
$casDb->row = [
    'id_orphan_sync' => 44,
    'id_shop' => 1,
    'id_order' => 9,
    'order_id' => 'CASCLAIM00001',
    'event_type' => OrphanSyncStates::EVENT_CP_CREATE_ORPHAN,
    'order_date' => '2026-09-16',
    'status_id' => BankStatus::SEND_FAILED_CP,
    'state' => OrphanSyncStates::PENDING,
    'attempt_count' => 1,
    'next_attempt_at' => gmdate('Y-m-d H:i:s', $now + 60),
];
$casRepo = new OrphanSyncRepository($casDb);
$casDb->affectedRows = 1;
$win = $casRepo->claimDuePending(44, 60, $now);
assertV203($win !== null && $casDb->updateCount === 1, 'SQL CAS first claim wins');
$casDb->affectedRows = 0;
$miss = $casRepo->claimDuePending(44, 60, $now);
assertV203($miss === null && $casDb->updateCount === 2, 'SQL CAS second claim misses when Affected_Rows=0');

// Orphan atomic rollback: bank written then orphan insert fails → full rollback.
final class V203TxnAttempts implements OrderAttemptStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var array<int, array<string, mixed>>|null */
    private $working = null;

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

    public function reserve(int $idShop, int $idCart, string $cartFingerprint): array
    {
        throw new RuntimeException('unused');
    }

    public function update(int $attemptId, array $changes): array
    {
        $map = $this->working ?? $this->rows;
        $map[$attemptId] = array_replace($map[$attemptId] ?? [], $changes);
        if ($this->working !== null) {
            $this->working = $map;
        } else {
            $this->rows = $map;
        }

        return $map[$attemptId];
    }
}

final class V203TxnSnapshots implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var array<int, array<string, mixed>>|null */
    private $working = null;

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

    public function save(int $attemptId, array $snapshot): void
    {
        $this->update($attemptId, $snapshot);
    }

    public function findByAttempt(int $attemptId): ?array
    {
        $map = $this->working ?? $this->rows;

        return $map[$attemptId] ?? null;
    }

    public function update(int $attemptId, array $changes): void
    {
        $map = $this->working ?? $this->rows;
        $map[$attemptId] = array_replace($map[$attemptId] ?? [], $changes);
        if ($this->working !== null) {
            $this->working = $map;
        } else {
            $this->rows = $map;
        }
    }
}

final class V203TxnBank implements BankStatusPersistencePort
{
    /** @var list<array<string, mixed>> */
    public $updates = [];
    /** @var list<array<string, mixed>>|null */
    private $working = null;

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
        $row = compact('idShop', 'orderReference', 'statusId', 'statusLabel');
        if ($this->working !== null) {
            $this->working[] = $row;
        } else {
            $this->updates[] = $row;
        }

        return $row;
    }
}

final class V203TxnBoundary implements MutationBoundaryInterface
{
    /** @var list<object> */
    private $stores;

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

            return $result;
        } catch (Throwable $exception) {
            foreach ($this->stores as $store) {
                $store->rollbackWork();
            }
            throw $exception;
        }
    }
}

$txnAttempts = new V203TxnAttempts();
$txnAttempts->rows[7] = ['state' => 'cp_pending', 'last_error_class' => null];
$txnSnapshots = new V203TxnSnapshots();
$txnSnapshots->rows[7] = ['lifecycle_status' => 'cp_pending'];
$txnBank = new V203TxnBank();
$txnOrphan = new V203MemoryOrphanStore();
$txnOrphan->failNextEnsure = true;
// SAME client instance used by orphan sync — assert zero POSTs on this object after rollback.
$txnCp = new V203OrphanCp();
$txnOrphanSvc = new OrphanReportSyncService($txnOrphan, $txnCp, $clock);
$txnBoundary = new V203TxnBoundary([$txnAttempts, $txnSnapshots, $txnBank, $txnOrphan]);
$txnFinalizer = new DefinitiveCpCreateFailureFinalizer(
    $txnAttempts,
    $txnSnapshots,
    $txnBank,
    $txnOrphanSvc,
    $txnBoundary
);
$txnOrder = new CreatedOrder(55, 'ROLLORPHAN001', 100.0, 'BGN', 1, [], [], []);
$txnShop = ['uni_proces' => 0];
$rolled = false;
try {
    $txnFinalizer->finalize(7, $txnOrder, 1, $txnShop, 'cp_create_invalid_payload', '2026-09-16');
} catch (Throwable $exception) {
    $rolled = true;
}
assertV203($rolled, 'orphan insert failure throws');
assertV203(($txnAttempts->rows[7]['state'] ?? '') === 'cp_pending', 'attempt rolled back');
assertV203(($txnSnapshots->rows[7]['lifecycle_status'] ?? '') === 'cp_pending', 'snapshot rolled back');
assertV203($txnBank->updates === [], 'bank status rolled back');
assertV203($txnOrphan->rows === [], 'orphan intent rolled back');
assertV203(count($txnCp->orphanCalls) === 0, 'wired orphan client POST count is 0 after rollback');

// OrderOrchestrator execution graph: definitive CP reject → finalizer rollback → never attemptDelivery/POST.
require_once dirname(__DIR__) . '/Calculator/fixtures.php';
if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'v203-orphan-rollback-key');
}
if (!class_exists('PhpEncryption', false)) {
    final class PhpEncryption
    {
        public function __construct(string $key) {}
        public function encrypt(string $value): string
        {
            return base64_encode(strrev($value));
        }
        public function decrypt(string $value)
        {
            $decoded = base64_decode($value, true);

            return is_string($decoded) ? strrev($decoded) : false;
        }
    }
}

final class V203OrchAttempts implements OrderAttemptStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];
    /** @var array<string, array<string, mixed>>|null */
    private $working = null;

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

    public function reserve(int $idShop, int $idCart, string $cartFingerprint): array
    {
        $map = $this->working ?? $this->rows;
        $key = $idShop . ':' . $idCart . ':' . $cartFingerprint;
        $created = !isset($map[$key]);
        if ($created) {
            $map[$key] = [
                'id_attempt' => count($map) + 1,
                'id_shop' => $idShop,
                'id_cart' => $idCart,
                'cart_fingerprint' => $cartFingerprint,
                'state' => 'reserved',
                'id_order' => null,
                'order_reference' => null,
                'control_panel_order_id' => null,
                'cp_payload' => null,
            ];
            if ($this->working !== null) {
                $this->working = $map;
            } else {
                $this->rows = $map;
            }
        }

        return ($this->working ?? $this->rows)[$key] + ['_reservation_created' => $created];
    }

    public function update(int $attemptId, array $changes): array
    {
        $map = $this->working ?? $this->rows;
        foreach ($map as $key => $row) {
            if ((int) $row['id_attempt'] === $attemptId) {
                $map[$key] = array_replace($row, $changes);
                if ($this->working !== null) {
                    $this->working = $map;
                } else {
                    $this->rows = $map;
                }

                return $map[$key];
            }
        }
        throw new RuntimeException('attempt missing');
    }
}

final class V203OrchSnapshots implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var array<int, array<string, mixed>>|null */
    private $working = null;

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

    public function save(int $attemptId, array $snapshot): void
    {
        $map = $this->working ?? $this->rows;
        $map[$attemptId] = $snapshot;
        if ($this->working !== null) {
            $this->working = $map;
        } else {
            $this->rows = $map;
        }
    }

    public function findByAttempt(int $attemptId): ?array
    {
        $map = $this->working ?? $this->rows;

        return $map[$attemptId] ?? null;
    }

    public function update(int $attemptId, array $changes): void
    {
        $map = $this->working ?? $this->rows;
        $map[$attemptId] = array_replace($map[$attemptId] ?? [], $changes);
        if ($this->working !== null) {
            $this->working = $map;
        } else {
            $this->rows = $map;
        }
    }
}

final class V203OrchOrders implements PrestaShopOrderGatewayInterface
{
    /** @var CreatedOrder */
    private $order;
    /** @var int */
    public $created = 0;

    public function __construct(CreatedOrder $order)
    {
        $this->order = $order;
    }

    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        ++$this->created;

        return $this->order;
    }

    public function load(int $id): CreatedOrder
    {
        return $this->order;
    }

    public function markFailed(int $id): void {}

    public function markAwaiting(int $id): void {}
}

final class V203CommitGateBoundary implements MutationBoundaryInterface
{
    /** @var list<object> */
    private $stores;
    /** @var bool */
    public $committedLastRun = false;

    /** @param list<object> $stores */
    public function __construct(array $stores)
    {
        $this->stores = $stores;
    }

    public function runExclusive(string $lockName, callable $callback)
    {
        $this->committedLastRun = false;
        foreach ($this->stores as $store) {
            $store->beginWork();
        }
        try {
            $result = $callback();
            foreach ($this->stores as $store) {
                $store->commitWork();
            }
            $this->committedLastRun = true;

            return $result;
        } catch (Throwable $exception) {
            foreach ($this->stores as $store) {
                $store->rollbackWork();
            }
            $this->committedLastRun = false;
            throw $exception;
        }
    }
}

final class V203GateAwareOrphanCp implements ControlPanelOrderClientInterface
{
    /** @var V203CommitGateBoundary */
    private $boundary;
    /** @var list<array<string, mixed>> */
    public $orphanCalls = [];
    /** @var list<mixed> */
    public $createQueue = [];
    /** @var int */
    public $prematurePosts = 0;

    public function __construct(V203CommitGateBoundary $boundary)
    {
        $this->boundary = $boundary;
    }

    public function createOrder(array $payload): array
    {
        $next = array_shift($this->createQueue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return is_array($next) ? $next : ['data' => ['id' => 1]];
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        throw new RuntimeException('unused');
    }

    public function reportOrderOrphan(string $orderId, string $orderDate): array
    {
        if (!$this->boundary->committedLastRun) {
            ++$this->prematurePosts;
        }
        $this->orphanCalls[] = compact('orderId', 'orderDate');

        return [
            'success' => true,
            'error' => null,
            'data' => ['result' => 'notified', 'order_id' => $orderId],
        ];
    }
}

$orchCalc = new Calculator('2026-08-17');
$orchShop = calculatorFixture(['uni_eur' => 0, 'uni_proces' => 0]);
$orchCart = new CartContext([new CartLine(new ProductContext(42, [7], 1050), 3, 2, 1000)], 1050, ['carrier_id' => 2, 'shipping_total' => '50.00']);
$orchScheme = (new CartSchemeResolver($orchCalc))->resolve($orchShop, $orchCart)->standardSchemes[0];
$orchCalculation = $orchCalc->calculateScheme($orchShop, 1050, $orchScheme, 100);
$orchRequest = new ValidatedPaymentRequest(
    $orchCalculation,
    ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '+359888123', 'email' => 'ivan@example.com', 'egn' => '1990010199', 'phone2' => '+3592123'],
    [7],
    hash('sha256', 'v203-orphan-roll'),
    [['id' => 7, 'name' => 'Terms', 'url' => 'https://example.com/terms', 'mandatory' => true]]
);
$orchCreated = new CreatedOrder(
    77,
    'ORCHROLL00001',
    1050,
    'BGN',
    1,
    ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '+359888123', 'email' => 'ivan@example.com'],
    ['invoice' => ['address1' => 'Sofia 1'], 'delivery' => ['address1' => 'Sofia 2']],
    [['id_product' => 42, 'id_product_attribute' => 3, 'name' => 'Product_Name', 'quantity' => 2, 'total' => 1000]]
);

$orchAttempts = new V203OrchAttempts();
$orchSnapshots = new V203OrchSnapshots();
$orchBank = new V203TxnBank();
$orchOrphanStore = new V203MemoryOrphanStore();
$orchOrphanStore->failNextEnsure = true;
$orchBoundary = new V203CommitGateBoundary([$orchAttempts, $orchSnapshots, $orchBank, $orchOrphanStore]);
$orchCp = new V203GateAwareOrphanCp($orchBoundary);
$orchCp->createQueue[] = new HttpException(422, ['error' => 'invalid_payload']);
$orchOrphanSvc = new OrphanReportSyncService($orchOrphanStore, $orchCp, $clock);
$orchFinalizer = new DefinitiveCpCreateFailureFinalizer(
    $orchAttempts,
    $orchSnapshots,
    $orchBank,
    $orchOrphanSvc,
    $orchBoundary
);
$orchFlow = new OrderOrchestrator(
    $orchAttempts,
    $orchSnapshots,
    new V203OrchOrders($orchCreated),
    $orchCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder(),
    $orchBank,
    null,
    $orchOrphanSvc,
    $orchFinalizer
);
$orchThrew = false;
try {
    $orchFlow->orchestrate(1, 501, $orchRequest, $orchShop);
    assertV203(false, 'orchestrator must surface definitive CP failure');
} catch (Throwable $exception) {
    $orchThrew = true;
}
assertV203($orchThrew, 'orchestrator path throws when orphan persistence fails');
assertV203($orchBoundary->committedLastRun === false, 'orchestrator path did not commit finalizer transaction');
assertV203($orchBank->updates === [], 'orchestrator path: no bank_send_failed_cp after rollback');
assertV203($orchOrphanStore->rows === [], 'orchestrator path: no orphan intent after rollback');
assertV203(count($orchCp->orphanCalls) === 0, 'orchestrator wired client: orphan POST count is 0');
assertV203($orchCp->prematurePosts === 0, 'orchestrator wired client: no premature POST before commit');
foreach ($orchAttempts->rows as $row) {
    assertV203(($row['state'] ?? '') !== OrderOrchestrator::TERMINAL_FAILED, 'orchestrator path: no terminal attempt commit');
}

fwrite(STDOUT, "OK (v2.0.3 CP create classifier + orphan durability)\n");
