<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;

final class OrderOrchestrator
{
    public const RESERVED = 'reserved';
    public const PS_ORDER_CREATED = 'ps_order_created';
    public const CP_SUBMITTING = 'cp_submitting';
    public const CP_CREATED = 'cp_created';
    public const CP_FAILED_RETRYABLE = 'cp_failed_retryable';
    public const CP_OUTCOME_UNKNOWN = 'cp_outcome_unknown';
    public const TERMINAL_FAILED = 'terminal_failed';

    /** @var OrderAttemptStoreInterface */
    private $attempts;
    /** @var FinancingSnapshotStoreInterface */
    private $snapshots;
    /** @var PrestaShopOrderGatewayInterface */
    private $orders;
    /** @var ControlPanelOrderClientInterface */
    private $cp;
    /** @var FinancingSnapshotFactory */
    private $snapshotFactory;
    /** @var ControlPanelOrderPayloadBuilder */
    private $payloads;

    /** @var BankStatusPersistencePort|null */
    private $bankStatus;

    /** @var ControlPanelCreateFailureService */
    private $createFailures;

    /** @var OrphanReportSyncService|null */
    private $orphanSync;

    /** @var DefinitiveCpCreateFailureFinalizer|null */
    private $definitiveFinalizer;

    public function __construct(
        OrderAttemptStoreInterface $attempts,
        FinancingSnapshotStoreInterface $snapshots,
        PrestaShopOrderGatewayInterface $orders,
        ControlPanelOrderClientInterface $cp,
        FinancingSnapshotFactory $snapshotFactory,
        ControlPanelOrderPayloadBuilder $payloads,
        ?BankStatusPersistencePort $bankStatus = null,
        ?ControlPanelCreateFailureService $createFailures = null,
        ?OrphanReportSyncService $orphanSync = null,
        ?DefinitiveCpCreateFailureFinalizer $definitiveFinalizer = null
    ) {
        $this->attempts = $attempts;
        $this->snapshots = $snapshots;
        $this->orders = $orders;
        $this->cp = $cp;
        $this->snapshotFactory = $snapshotFactory;
        $this->payloads = $payloads;
        $this->bankStatus = $bankStatus;
        $this->createFailures = $createFailures ?? new ControlPanelCreateFailureService();
        $this->orphanSync = $orphanSync;
        $this->definitiveFinalizer = $definitiveFinalizer;
    }

    /** @param array<string, mixed> $shop */
    public function orchestrate(int $idShop, int $idCart, ValidatedPaymentRequest $request, array $shop, string $submissionSource = 'checkout'): OrderOrchestrationResult
    {
        $attempt = $this->attempts->reserve($idShop, $idCart, $request->cartFingerprint);
        $attemptId = (int) $attempt['id_attempt'];
        if ((string) $attempt['state'] === self::CP_CREATED) {
            return $this->result($attempt);
        }
        if ((string) $attempt['state'] === self::TERMINAL_FAILED) {
            throw new OrderOrchestrationException(
                'The financing attempt cannot be retried.',
                false,
                null,
                (int) ($attempt['id_order'] ?? 0),
                $attemptId,
                self::TERMINAL_FAILED,
                false,
                (string) ($attempt['order_reference'] ?? '')
            );
        }
        if (empty($attempt['_reservation_created']) && (int) ($attempt['id_order'] ?? 0) <= 0) {
            throw new OrderOrchestrationException('The financing attempt is already being processed.', true);
        }

        $snapshot = $this->snapshots->findByAttempt($attemptId);
        if ((int) ($attempt['id_order'] ?? 0) > 0) {
            $order = $this->orders->load((int) $attempt['id_order']);
            if ($snapshot === null) {
                if (abs($order->total - $request->calculation->price) > 0.01) {
                    $this->attempts->update($attemptId, ['state' => self::TERMINAL_FAILED, 'last_error_class' => 'OrderTotalMismatch']);
                    DeferredOrderMailQueue::discard();
                    throw new OrderOrchestrationException(
                        'The created order total does not match the validated cart total.',
                        false,
                        null,
                        $order->idOrder,
                        $attemptId,
                        self::TERMINAL_FAILED,
                        false,
                        $order->reference
                    );
                }
                $snapshot = $this->snapshotFactory->create($request, $order, $submissionSource);
                $this->saveSnapshot($attemptId, $snapshot);
            }
        } else {
            $order = $this->orders->create($request, $shop);
            $attempt = $this->attempts->update($attemptId, ['state' => self::PS_ORDER_CREATED, 'id_order' => $order->idOrder, 'order_reference' => $order->reference]);
            if (abs($order->total - $request->calculation->price) > 0.01) {
                $this->attempts->update($attemptId, ['state' => self::TERMINAL_FAILED, 'last_error_class' => 'OrderTotalMismatch']);
                DeferredOrderMailQueue::discard();
                throw new OrderOrchestrationException(
                    'The created order total does not match the validated cart total.',
                    false,
                    null,
                    $order->idOrder,
                    $attemptId,
                    self::TERMINAL_FAILED,
                    false,
                    $order->reference
                );
            }
            $snapshot = $this->snapshotFactory->create($request, $order, $submissionSource);
            $this->saveSnapshot($attemptId, $snapshot);
        }

        $payload = isset($attempt['cp_payload']) && is_string($attempt['cp_payload']) && $attempt['cp_payload'] !== '' ? json_decode($attempt['cp_payload'], true) : null;
        if (!is_array($payload)) {
            $payload = $this->payloads->build($snapshot, $shop);
            $attempt = $this->attempts->update($attemptId, ['cp_payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }
        $this->attempts->update($attemptId, ['state' => self::CP_SUBMITTING, 'last_error_class' => null]);
        try {
            $response = $this->cp->createOrder($payload);
            $cpId = (int) ($response['data']['id'] ?? 0);
            if ($cpId <= 0) {
                $classification = $this->createFailures->classifyMissingControlPanelOrderId();
                $this->applyCreateFailure($attemptId, $order, $idShop, $shop, $classification);
                throw new OrderOrchestrationException(
                    'The Control Panel did not return an order identifier.',
                    $classification->isRetryable(),
                    null,
                    $order->idOrder,
                    $attemptId,
                    $classification->attemptState(),
                    $classification->attemptState() === self::CP_OUTCOME_UNKNOWN,
                    $order->reference
                );
            }
            $attempt = $this->attempts->update($attemptId, ['state' => self::CP_CREATED, 'control_panel_order_id' => $cpId]);
            $this->snapshots->update($attemptId, ['control_panel_order_id' => $cpId, 'lifecycle_status' => self::CP_CREATED]);
            return $this->result($attempt);
        } catch (OrderOrchestrationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $classification = $this->createFailures->classifyThrowable($exception);
            $this->applyCreateFailure($attemptId, $order, $idShop, $shop, $classification);
            throw new OrderOrchestrationException(
                $classification->isDefinitive()
                    ? 'The Control Panel rejected the financing order.'
                    : 'The Control Panel result is unknown and can be retried safely.',
                $classification->isRetryable(),
                $exception,
                $order->idOrder,
                $attemptId,
                $classification->attemptState(),
                $classification->attemptState() === self::CP_OUTCOME_UNKNOWN,
                $order->reference
            );
        }
    }

    /** @param array<string, mixed> $attempt */
    private function result(array $attempt): OrderOrchestrationResult
    {
        return new OrderOrchestrationResult((int)$attempt['id_attempt'], (string)$attempt['state'], (int)$attempt['id_order'], (string)$attempt['order_reference'], (int)($attempt['control_panel_order_id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $shop
     */
    private function applyCreateFailure(
        int $attemptId,
        CreatedOrder $order,
        int $idShop,
        array $shop,
        ControlPanelCreateFailureClassification $classification
    ): void {
        if ($classification->isDefinitive() && $classification->shouldWriteBankSendFailedCp()) {
            $intent = $this->definitiveFinalizer()->finalize(
                $attemptId,
                $order,
                $idShop,
                $shop,
                $classification->errorClass(),
                $this->resolveOrderDate($order)
            );
            if ($intent !== null && $this->orphanSync !== null) {
                try {
                    $this->orphanSync->attemptDelivery($intent);
                } catch (\Throwable $exception) {
                    \PrestaShopLogger::addLog(
                        'UniPayment immediate orphan-report failed: ' . get_class($exception)
                            . ' attempt_id=' . $attemptId
                            . ' order_ref=' . $order->reference,
                        2
                    );
                }
            }

            return;
        }

        $this->attempts->update($attemptId, [
            'state' => $classification->attemptState(),
            'last_error_class' => $classification->errorClass(),
        ]);
        $this->snapshots->update($attemptId, ['lifecycle_status' => $classification->attemptState()]);
        DeferredOrderMailQueue::discard();
    }

    private function definitiveFinalizer(): DefinitiveCpCreateFailureFinalizer
    {
        if ($this->definitiveFinalizer === null) {
            $this->definitiveFinalizer = DefinitiveCpCreateFailureFinalizer::withDefaultBoundary(
                $this->attempts,
                $this->snapshots,
                $this->bankStatus,
                $this->orphanSync
            );
        }

        return $this->definitiveFinalizer;
    }

    private function resolveOrderDate(CreatedOrder $order): string
    {
        if (class_exists('\\Order')) {
            try {
                $psOrder = new \Order($order->idOrder);
                if (!empty($psOrder->date_add) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $psOrder->date_add)) {
                    return substr((string) $psOrder->date_add, 0, 10);
                }
            } catch (\Throwable $exception) {
                // Fall through to UTC date.
            }
        }

        return gmdate('Y-m-d');
    }

    /** @param array<string, mixed> $snapshot */
    private function saveSnapshot(int $attemptId, array $snapshot): void
    {
        $this->snapshots->save($attemptId, $snapshot);
        try {
            (new FinancingSnapshotRetentionService())->maybeRun();
        } catch (\Throwable $exception) {
            // Opportunistic privacy cleanup must not block financing submission.
        }
    }
}
