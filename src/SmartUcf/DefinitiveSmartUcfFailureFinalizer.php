<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Infrastructure\DbMutationBoundary;
use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncService;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStates;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;

/**
 * Atomically finalizes a proven definitive SmartUCF remote rejection:
 * lifecycle failed + local bank_send_failed_smartucf + pending CP status target.
 *
 * Remote PATCH remains strictly after COMMIT.
 */
final class DefinitiveSmartUcfFailureFinalizer
{
    /** @var SmartUcfLifecycleRepository|object */
    private $lifecycle;
    /** @var BankStatusPersistencePort */
    private $bankStatus;
    /** @var ControlPanelStatusSyncStoreInterface */
    private $syncStore;
    /** @var MutationBoundaryInterface|null */
    private $boundary;

    /**
     * @param SmartUcfLifecycleRepository|object $lifecycle object with markFailed(...)
     */
    public function __construct(
        $lifecycle,
        BankStatusPersistencePort $bankStatus,
        ControlPanelStatusSyncStoreInterface $syncStore,
        ?MutationBoundaryInterface $boundary = null
    ) {
        $this->lifecycle = $lifecycle;
        $this->bankStatus = $bankStatus;
        $this->syncStore = $syncStore;
        $this->boundary = $boundary;
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return bool true when definitive local state committed (caller may PATCH)
     */
    public function finalize(
        int $attemptId,
        array $snapshot,
        SmartUcfFailureClassification $classification,
        int $idShop
    ): bool {
        $orderReference = trim((string) ($snapshot['order_reference'] ?? ''));
        $cpOrderId = (int) ($snapshot['control_panel_order_id'] ?? 0);

        if ($cpOrderId <= 0 || $orderReference === '' || $idShop <= 0) {
            // Invalid post-CP invariant: never write bank_send_failed_smartucf / CP target.
            try {
                $this->lifecycle->markFailed(
                    $attemptId,
                    'smartucf_missing_cp_order_id',
                    true,
                    $classification->httpCode()
                );
            } catch (\Throwable $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment SmartUCF missing CP id lifecycle mark failed: ' . get_class($exception),
                    3
                );
            }

            return false;
        }

        $failedStatus = BankStatus::smartUcfFailure();
        $runner = function () use (
            $attemptId,
            $classification,
            $orderReference,
            $idShop,
            $failedStatus
        ): void {
            $this->lifecycle->markFailed(
                $attemptId,
                $classification->errorClass(),
                false,
                $classification->httpCode()
            );

            $bankRow = $this->bankStatus->updateByOrderIdentifier(
                $idShop,
                $orderReference,
                $failedStatus['status_id'],
                $failedStatus['status_label']
            );
            if ($bankRow === null) {
                throw new \RuntimeException(
                    'Local bank status persistence failed for definitive SmartUCF failure.'
                );
            }

            $queueOnly = new ControlPanelStatusSyncService($this->syncStore, null);
            $state = $queueOnly->synchronizeAfterHandoff($attemptId, $orderReference, $failedStatus);
            if (
                $state !== ControlPanelStatusSyncStates::PENDING
                && $state !== ControlPanelStatusSyncStates::CONFIRMED
            ) {
                throw new \RuntimeException(
                    'Durable CP status-sync target was not established for definitive SmartUCF failure.'
                );
            }
        };

        try {
            if ($this->boundary !== null) {
                $this->boundary->runExclusive(
                    'unipayment_smartucf_def_fail_' . $attemptId,
                    $runner
                );
            } else {
                $runner();
            }
        } catch (SmartUcfLifecyclePersistenceException $persistException) {
            \PrestaShopLogger::addLog(
                'UniPayment SmartUCF definitive failure persistence failed: ' . $persistException->getMessage(),
                3
            );

            return false;
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment SmartUCF definitive failure transaction failed: ' . get_class($exception),
                3
            );

            return false;
        }

        return true;
    }

    public static function withDefaults(
        SmartUcfLifecycleRepository $lifecycle,
        ControlPanelStatusSyncStoreInterface $syncStore,
        ?BankStatusPersistencePort $bankStatus = null
    ): self {
        $boundary = null;
        try {
            if (class_exists('\\Db') && defined('_DB_PREFIX_')) {
                $boundary = new DbMutationBoundary();
            }
        } catch (\Throwable $exception) {
            $boundary = null;
        }

        return new self(
            $lifecycle,
            $bankStatus ?? new OrderBankStatusRepository(),
            $syncStore,
            $boundary
        );
    }
}
