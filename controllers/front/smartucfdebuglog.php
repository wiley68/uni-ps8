<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Api\ModuleApiError;
use PrestaShop\Module\Unipayment\Api\ModuleApiOperation;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusAmbiguousException;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;

final class UnipaymentSmartucfdebuglogModuleFrontController extends ModuleApiController
{
    protected function expectedOperation(): string
    {
        return ModuleApiOperation::SMARTUCF_DEBUG_LOG;
    }

    protected function handleAuthenticatedRequest(array $payload, string $unicid): array
    {
        unset($unicid);
        $idShop = (int) ($this->context->shop->id ?? 0);
        if ($idShop <= 0) {
            throw new ModuleApiException(
                'The shop context is invalid.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        $orderId = $payload['order_id'] ?? null;
        if (!is_string($orderId) && !is_int($orderId)) {
            throw new ModuleApiException(
                'The order_id field is required.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        $orderId = trim((string) $orderId);
        if ($orderId === '' || strlen($orderId) > ModuleRequestSignatureProtocol::ORDER_ID_MAX) {
            throw new ModuleApiException(
                'The order_id field is invalid.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        try {
            $authorized = (new OrderBankStatusRepository())->resolveAuthorizedFinancingOrder(
                $idShop,
                $orderId
            );
        } catch (OrderBankStatusAmbiguousException $exception) {
            throw new ModuleApiException(
                'The order was not found in the shop.',
                404,
                ModuleApiError::ORDER_NOT_FOUND
            );
        }

        if ($authorized === null) {
            throw new ModuleApiException(
                'The order was not found in the shop.',
                404,
                ModuleApiError::ORDER_NOT_FOUND
            );
        }

        $log = (new SmartUcfDiagnosticJournal(
            new ConfigurationRepository(),
            new SmartUcfDebugLogRepository()
        ))->findLatestForAuthorizedOrder(
            (string) $authorized['order_reference'],
            (int) $authorized['id_order']
        );
        if ($log === null) {
            throw new ModuleApiException(
                'The order was not found in the shop.',
                404,
                ModuleApiError::ORDER_NOT_FOUND
            );
        }

        return [
            'success' => true,
            'message' => 'The SmartUCF diagnostic log was retrieved successfully.',
            'data' => [
                'order_id' => (string) $authorized['order_reference'],
                'ps_order_id' => (int) $authorized['id_order'],
                'log' => $log,
            ],
        ];
    }
}
