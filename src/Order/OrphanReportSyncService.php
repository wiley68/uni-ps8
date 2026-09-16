<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;

/**
 * Durable orphan-report delivery after definitive CP create rejection.
 *
 * Operational only — must never alter customer-facing ThankYou / Step 3 / PS order state.
 */
final class OrphanReportSyncService
{
    private const TERMINAL_RESULTS = [
        'notified',
        'already_reported',
        'ignored_cp_order_exists',
        'skipped_no_recipient',
    ];

    private const BATCH_LIMIT = 5;
    private const BASE_BACKOFF_SECONDS = 30;
    private const MAX_BACKOFF_SECONDS = 900;
    private const CLAIM_HOLD_SECONDS = 60;

    /** @var OrphanSyncStoreInterface */
    private $store;

    /** @var ControlPanelOrderClientInterface|null */
    private $cpClient;

    /** @var callable */
    private $clock;

    public function __construct(
        ?OrphanSyncStoreInterface $store = null,
        ?ControlPanelOrderClientInterface $cpClient = null,
        ?callable $clock = null
    ) {
        $this->store = $store ?? new OrphanSyncRepository();
        $this->cpClient = $cpClient;
        $this->clock = $clock ?? 'time';
    }

    /**
     * Persist a unique pending orphan intent (idempotent).
     *
     * @return array<string, mixed>
     */
    public function ensurePendingIntent(
        int $idShop,
        int $idOrder,
        string $orderId,
        string $orderDate,
        string $statusId = OrphanSyncStates::STATUS_ID
    ): array {
        return $this->store->ensurePendingIntent(
            $idShop,
            $idOrder,
            substr(trim($orderId), 0, 13),
            $orderDate,
            $statusId,
            OrphanSyncStates::EVENT_CP_CREATE_ORPHAN
        );
    }

    /**
     * Attempt immediate delivery for one intent after durable persistence.
     *
     * @param array<string, mixed> $intent
     */
    public function attemptDelivery(array $intent): OrphanReportDeliveryResult
    {
        $id = (int) ($intent['id_orphan_sync'] ?? 0);
        if ($id <= 0 || $this->cpClient === null) {
            return new OrphanReportDeliveryResult(
                OrphanReportDeliveryResult::RETRYABLE,
                'busy',
                'orphan_client_unavailable'
            );
        }

        $now = (int) call_user_func($this->clock);
        $claimed = $this->store->claimDuePending($id, self::CLAIM_HOLD_SECONDS, $now);
        if ($claimed === null) {
            return new OrphanReportDeliveryResult(
                OrphanReportDeliveryResult::RETRYABLE,
                'busy',
                'orphan_claim_miss'
            );
        }

        return $this->deliverClaimed($claimed, $now);
    }

    /**
     * Bounded opportunistic / cron flush of due pending intents.
     *
     * @return int number of outbound attempts
     */
    public function flushDuePending(int $limit = self::BATCH_LIMIT): int
    {
        if ($this->cpClient === null) {
            return 0;
        }

        $now = (int) call_user_func($this->clock);
        $due = $this->store->findDuePending(max(1, $limit), $now);
        $attempts = 0;
        foreach ($due as $row) {
            $id = (int) ($row['id_orphan_sync'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $claimed = $this->store->claimDuePending($id, self::CLAIM_HOLD_SECONDS, $now);
            if ($claimed === null) {
                continue;
            }
            $this->deliverClaimed($claimed, $now);
            ++$attempts;
        }

        return $attempts;
    }

    /**
     * @param array<string, mixed> $claimed
     */
    private function deliverClaimed(array $claimed, int $now): OrphanReportDeliveryResult
    {
        $id = (int) $claimed['id_orphan_sync'];
        $orderId = substr(trim((string) ($claimed['order_id'] ?? '')), 0, 13);
        $orderDate = (string) ($claimed['order_date'] ?? '');
        $attemptCount = (int) ($claimed['attempt_count'] ?? 1);

        try {
            if ($this->cpClient === null) {
                throw new ConnectionException('Orphan Control Panel client is unavailable.');
            }
            $response = $this->cpClient->reportOrderOrphan($orderId, $orderDate);
            $classification = $this->classifySuccessResponse($response, $orderId);
        } catch (\Throwable $exception) {
            $classification = $this->classifyFailure($exception);
        }

        if ($classification->isTerminal()) {
            $this->store->markConfirmed($id, $classification->result());
            \PrestaShopLogger::addLog(
                'UniPayment orphan-report confirmed: result=' . $classification->result()
                    . ' order_ref=' . $orderId
                    . ' id_orphan_sync=' . $id,
                1
            );

            return $classification;
        }

        $nextAt = $now + $this->backoffSeconds($attemptCount);
        $this->store->markRetryable($id, $classification->result(), $classification->errorClass(), $nextAt);
        \PrestaShopLogger::addLog(
            'UniPayment orphan-report remains pending: result=' . $classification->result()
                . ' error_class=' . $classification->errorClass()
                . ' order_ref=' . $orderId
                . ' id_orphan_sync=' . $id,
            2
        );

        return $classification;
    }

    /** @param array<string, mixed> $response */
    private function classifySuccessResponse(array $response, string $expectedOrderId): OrphanReportDeliveryResult
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $result = isset($data['result']) && is_string($data['result']) ? trim($data['result']) : '';
        if ($result === '' || !in_array($result, self::TERMINAL_RESULTS, true)) {
            return new OrphanReportDeliveryResult(
                OrphanReportDeliveryResult::RETRYABLE,
                $result !== '' ? $result : 'malformed',
                'orphan_malformed_response'
            );
        }

        if (isset($data['order_id']) && is_string($data['order_id']) && $data['order_id'] !== '') {
            if (!hash_equals($expectedOrderId, substr(trim($data['order_id']), 0, 13))) {
                return new OrphanReportDeliveryResult(
                    OrphanReportDeliveryResult::RETRYABLE,
                    $result,
                    'orphan_order_id_mismatch'
                );
            }
        }

        return new OrphanReportDeliveryResult(OrphanReportDeliveryResult::TERMINAL, $result);
    }

    private function classifyFailure(\Throwable $exception): OrphanReportDeliveryResult
    {
        if ($exception instanceof TimeoutException || $exception instanceof ConnectionException) {
            return new OrphanReportDeliveryResult(
                OrphanReportDeliveryResult::RETRYABLE,
                'transport',
                'orphan_transport'
            );
        }
        if ($exception instanceof AuthenticationException) {
            return new OrphanReportDeliveryResult(
                OrphanReportDeliveryResult::RETRYABLE,
                'auth',
                'orphan_auth_retryable'
            );
        }
        if ($exception instanceof MalformedJsonException || $exception instanceof InvalidPayloadException) {
            return new OrphanReportDeliveryResult(
                OrphanReportDeliveryResult::RETRYABLE,
                'malformed',
                'orphan_malformed_response'
            );
        }
        if ($exception instanceof HttpException) {
            $status = $exception->getStatusCode();
            $response = $exception->getResponse();
            $error = isset($response['error']) && is_string($response['error']) ? trim($response['error']) : '';
            $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
            $result = isset($data['result']) && is_string($data['result']) ? trim($data['result']) : '';

            if ($result === 'busy' || $error === 'lifecycle_busy' || ($status === 503 && $result === 'busy')) {
                return new OrphanReportDeliveryResult(
                    OrphanReportDeliveryResult::RETRYABLE,
                    $result !== '' ? $result : 'busy',
                    $error !== '' ? 'orphan_' . $error : 'orphan_busy'
                );
            }
            if ($result === 'mail_failed') {
                return new OrphanReportDeliveryResult(
                    OrphanReportDeliveryResult::RETRYABLE,
                    'mail_failed',
                    'orphan_mail_failed'
                );
            }
            if ($result !== '' && in_array($result, self::TERMINAL_RESULTS, true)) {
                // Some terminals may be returned under failure envelope — still terminal success for intent.
                return new OrphanReportDeliveryResult(OrphanReportDeliveryResult::TERMINAL, $result);
            }

            return new OrphanReportDeliveryResult(
                OrphanReportDeliveryResult::RETRYABLE,
                $result !== '' ? $result : 'http_' . $status,
                $error !== '' ? 'orphan_' . $error : 'orphan_http_' . $status
            );
        }

        return new OrphanReportDeliveryResult(
            OrphanReportDeliveryResult::RETRYABLE,
            'unknown',
            'orphan_' . get_class($exception)
        );
    }

    public function backoffSeconds(int $attemptCount): int
    {
        $exponent = max(0, min(5, $attemptCount - 1));

        return min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * (2 ** $exponent));
    }
}
