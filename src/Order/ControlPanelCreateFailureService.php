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
 * Classifies CP create-order outcomes for retry / ambiguous / definitive rejection.
 *
 * bank_send_failed_cp + orphan intent are reserved for proven definitive rejections only.
 */
final class ControlPanelCreateFailureService
{
    /**
     * Positive allowlist of proven permanent CP create-endpoint machine codes.
     * Only these may establish bank_send_failed_cp + orphan intent.
     */
    private const TERMINAL_CREATE_ERROR_CODES = [
        'invalid_payload',
        'semantic_conflict',
    ];

    public function classifyThrowable(\Throwable $exception): ControlPanelCreateFailureClassification
    {
        if ($exception instanceof TimeoutException || $exception instanceof ConnectionException) {
            return new ControlPanelCreateFailureClassification(
                ControlPanelCreateFailureClassification::KIND_AMBIGUOUS,
                OrderOrchestrator::CP_OUTCOME_UNKNOWN,
                true,
                get_class($exception),
                false,
                false
            );
        }

        if ($exception instanceof AuthenticationException) {
            return new ControlPanelCreateFailureClassification(
                ControlPanelCreateFailureClassification::KIND_RETRYABLE,
                OrderOrchestrator::CP_FAILED_RETRYABLE,
                true,
                'cp_create_auth_retryable',
                false,
                false
            );
        }

        if ($exception instanceof InvalidPayloadException || $exception instanceof MalformedJsonException) {
            return new ControlPanelCreateFailureClassification(
                ControlPanelCreateFailureClassification::KIND_AMBIGUOUS,
                OrderOrchestrator::CP_OUTCOME_UNKNOWN,
                true,
                'cp_create_invalid_response',
                false,
                false
            );
        }

        if ($exception instanceof HttpException) {
            return $this->classifyHttp($exception);
        }

        return new ControlPanelCreateFailureClassification(
            ControlPanelCreateFailureClassification::KIND_AMBIGUOUS,
            OrderOrchestrator::CP_OUTCOME_UNKNOWN,
            true,
            'cp_create_' . get_class($exception),
            false,
            false
        );
    }

    /**
     * Apparent HTTP 2xx create without a usable Control Panel order identity.
     */
    public function classifyMissingControlPanelOrderId(): ControlPanelCreateFailureClassification
    {
        return new ControlPanelCreateFailureClassification(
            ControlPanelCreateFailureClassification::KIND_AMBIGUOUS,
            OrderOrchestrator::CP_OUTCOME_UNKNOWN,
            true,
            'MissingControlPanelOrderId',
            false,
            false
        );
    }

    private function classifyHttp(HttpException $exception): ControlPanelCreateFailureClassification
    {
        $status = $exception->getStatusCode();
        $response = $exception->getResponse();
        $error = isset($response['error']) && is_string($response['error']) ? trim($response['error']) : '';

        if ($error === 'lifecycle_busy' || ($status === 503 && $error === 'lifecycle_busy')) {
            return new ControlPanelCreateFailureClassification(
                ControlPanelCreateFailureClassification::KIND_RETRYABLE,
                OrderOrchestrator::CP_FAILED_RETRYABLE,
                true,
                'cp_create_lifecycle_busy',
                false,
                false
            );
        }

        if ($error !== '' && in_array($error, self::TERMINAL_CREATE_ERROR_CODES, true)) {
            return new ControlPanelCreateFailureClassification(
                ControlPanelCreateFailureClassification::KIND_DEFINITIVE,
                OrderOrchestrator::TERMINAL_FAILED,
                false,
                'cp_create_' . $error,
                true,
                true
            );
        }

        if ($status === 503 || $status === 429 || $error === 'rate_limited' || $error === 'internal_error') {
            return new ControlPanelCreateFailureClassification(
                ControlPanelCreateFailureClassification::KIND_RETRYABLE,
                OrderOrchestrator::CP_FAILED_RETRYABLE,
                true,
                $error !== '' ? 'cp_create_' . $error : 'cp_create_http_' . $status,
                false,
                false
            );
        }

        if ($status >= 500) {
            return new ControlPanelCreateFailureClassification(
                ControlPanelCreateFailureClassification::KIND_RETRYABLE,
                OrderOrchestrator::CP_FAILED_RETRYABLE,
                true,
                'cp_create_http_' . $status,
                false,
                false
            );
        }

        // Bare/malformed/non-allowlisted 4xx remain ambiguous — never bank_send_failed_cp.
        return new ControlPanelCreateFailureClassification(
            ControlPanelCreateFailureClassification::KIND_AMBIGUOUS,
            OrderOrchestrator::CP_OUTCOME_UNKNOWN,
            true,
            $error !== '' ? 'cp_create_' . $error : 'cp_create_http_' . $status,
            false,
            false
        );
    }
}
