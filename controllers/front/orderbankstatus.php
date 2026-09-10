<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Api\ModuleApiError;
use PrestaShop\Module\Unipayment\Api\ModuleApiOperation;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;

final class UnipaymentOrderbankstatusModuleFrontController extends ModuleApiController
{
    protected function expectedOperation(): string
    {
        return ModuleApiOperation::ORDER_BANK_STATUS;
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

        $orderId = $this->requiredString(
            $payload,
            'order_id',
            ModuleRequestSignatureProtocol::ORDER_ID_MAX
        );
        $statusId = $this->requiredString(
            $payload,
            'status_id',
            ModuleRequestSignatureProtocol::STATUS_ID_MAX
        );
        $status = $this->requiredString(
            $payload,
            'status',
            ModuleRequestSignatureProtocol::STATUS_MAX
        );

        try {
            $result = (new OrderBankStatusRepository())->updateByOrderIdentifier(
                $idShop,
                $orderId,
                $statusId,
                $status
            );
        } catch (\PrestaShop\Module\Unipayment\Order\OrderBankStatusAmbiguousException $exception) {
            throw new ModuleApiException(
                'Multiple financing orders match this reference in the shop.',
                409,
                ModuleApiError::ORDER_AMBIGUOUS
            );
        }

        if ($result === null) {
            throw new ModuleApiException(
                'The order was not found in the shop.',
                404,
                ModuleApiError::ORDER_NOT_FOUND
            );
        }

        $result['ps_order_state_changed'] = false;

        return [
            'success' => true,
            'message' => 'The bank status was updated successfully.',
            'data' => $result,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new ModuleApiException(
                sprintf('The %s field is required.', $key),
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new ModuleApiException(
                sprintf('The %s field is invalid.', $key),
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        return $value;
    }
}
