<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\Order\BankStatusOrderStateMapper;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;

final class UnipaymentOrderbankstatusModuleFrontController extends ModuleApiController
{
    protected function handleAuthenticatedRequest(array $payload, string $unicid): array
    {
        unset($unicid);
        $idShop = (int) ($this->context->shop->id ?? 0);
        if ($idShop <= 0) {
            throw new ModuleApiException('The shop context is invalid.', 400);
        }

        $orderId = $this->requiredString($payload, 'order_id', 64);
        $statusId = $this->requiredString($payload, 'status_id', 255);
        $status = $payload['status'] ?? '';
        if (!is_string($status) || strlen($status) > 255) {
            throw new ModuleApiException('The status field is invalid.', 400);
        }

        $result = (new OrderBankStatusRepository())->updateByOrderIdentifier(
            $idShop,
            $orderId,
            $statusId,
            trim($status)
        );
        if ($result === null) {
            throw new ModuleApiException('The order was not found in the shop.', 404);
        }

        $stateChanged = false;
        try {
            $syncEnabled = (new ConfigurationRepository())->isSyncBankRejectionStateEnabled();
            $stateChanged = (new BankStatusOrderStateMapper())->apply(
                (int) $result['ps_order_id'],
                $statusId,
                $syncEnabled
            );
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment bank-status PS order-state sync failed (' . get_class($exception) . ')',
                3
            );
            $stateChanged = false;
        }

        $result['ps_order_state_changed'] = $stateChanged;

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
            throw new ModuleApiException(sprintf('The %s field is required.', $key), 400);
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new ModuleApiException(sprintf('The %s field is invalid.', $key), 400);
        }

        return $value;
    }
}
