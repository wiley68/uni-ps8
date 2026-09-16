<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\Infrastructure\DbMutationBoundary;
use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;

/**
 * Atomically finalizes a proven definitive CP create rejection:
 * attempt terminal + local bank_send_failed_cp + pending orphan intent.
 */
final class DefinitiveCpCreateFailureFinalizer
{
    /** @var OrderAttemptStoreInterface */
    private $attempts;
    /** @var FinancingSnapshotStoreInterface */
    private $snapshots;
    /** @var BankStatusPersistencePort|null */
    private $bankStatus;
    /** @var OrphanReportSyncService|null */
    private $orphanSync;
    /** @var MutationBoundaryInterface|null */
    private $boundary;

    public function __construct(
        OrderAttemptStoreInterface $attempts,
        FinancingSnapshotStoreInterface $snapshots,
        ?BankStatusPersistencePort $bankStatus = null,
        ?OrphanReportSyncService $orphanSync = null,
        ?MutationBoundaryInterface $boundary = null
    ) {
        $this->attempts = $attempts;
        $this->snapshots = $snapshots;
        $this->bankStatus = $bankStatus;
        $this->orphanSync = $orphanSync;
        $this->boundary = $boundary;
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>|null pending orphan intent when Process 1
     */
    public function finalize(
        int $attemptId,
        CreatedOrder $order,
        int $idShop,
        array $shop,
        string $errorClass,
        string $orderDate
    ): ?array {
        $process2 = ShopConfigurationFlags::isProcess2($shop);
        $status = BankStatus::controlPanelFailure($process2);
        $createOrphan = !$process2 && $status['status_id'] === BankStatus::SEND_FAILED_CP;

        $runner = function () use (
            $attemptId,
            $order,
            $idShop,
            $errorClass,
            $status,
            $createOrphan,
            $orderDate
        ): ?array {
            $this->attempts->update($attemptId, [
                'state' => OrderOrchestrator::TERMINAL_FAILED,
                'last_error_class' => $errorClass,
            ]);
            $this->snapshots->update($attemptId, [
                'lifecycle_status' => OrderOrchestrator::TERMINAL_FAILED,
            ]);
            DeferredOrderMailQueue::discard();

            if ($this->bankStatus !== null && $order->reference !== '') {
                $bankRow = $this->bankStatus->updateByOrderIdentifier(
                    $idShop,
                    $order->reference,
                    $status['status_id'],
                    $status['status_label']
                );
                if ($bankRow === null) {
                    throw new \RuntimeException(
                        'Local bank status persistence failed for definitive CP create failure.'
                    );
                }
            }

            if (!$createOrphan || $this->orphanSync === null || $order->reference === '') {
                return null;
            }

            $intent = $this->orphanSync->ensurePendingIntent(
                $idShop,
                $order->idOrder,
                $order->reference,
                $orderDate,
                BankStatus::SEND_FAILED_CP
            );
            if ($intent === [] || !isset($intent['id_orphan_sync'])) {
                throw new \RuntimeException(
                    'Orphan sync intent persistence failed for definitive CP create failure.'
                );
            }

            return $intent;
        };

        try {
            if ($this->boundary !== null) {
                $lockName = 'unipayment_cp_def_fail_' . $idShop . '_' . substr($order->reference, 0, 13);

                return $this->boundary->runExclusive($lockName, $runner);
            }

            return $runner();
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment definitive CP failure finalization failed: ' . get_class($exception)
                    . ' attempt_id=' . $attemptId
                    . ' order_ref=' . $order->reference,
                3
            );
            throw $exception;
        }
    }

    public static function withDefaultBoundary(
        OrderAttemptStoreInterface $attempts,
        FinancingSnapshotStoreInterface $snapshots,
        ?BankStatusPersistencePort $bankStatus,
        ?OrphanReportSyncService $orphanSync
    ): self {
        $boundary = null;
        try {
            if (class_exists('\\Db') && defined('_DB_PREFIX_')) {
                $boundary = new DbMutationBoundary();
            }
        } catch (\Throwable $exception) {
            $boundary = null;
        }

        return new self($attempts, $snapshots, $bankStatus, $orphanSync, $boundary);
    }
}
