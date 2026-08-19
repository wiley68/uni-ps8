<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Maps bank status labels (pushed from CP) to PrestaShop order states.
 *
 * Status labels match the CP OrderStatus enum values (Bulgarian text).
 * The mapping intentionally uses the human-readable `status` label rather than
 * `status_id`, because the bank's reqStatusCode values are not standardized.
 */
final class BankStatusOrderStateMapper
{
    private const REJECTION_LABELS = [
        'Отказана',
        'Отказана от клиент',
        'Отказана от клиент при контакт',
    ];

    private const ACCEPTANCE_LABELS = [
        'Сключен договор',
        'Активиран договор',
    ];

    /**
     * Applies the PS order state change based on the bank status label.
     * Returns true if the order state was changed, false if no mapping matched.
     */
    public function apply(int $idOrder, string $statusLabel): bool
    {
        if ($idOrder <= 0 || $statusLabel === '') {
            return false;
        }

        $targetStateId = $this->resolve($statusLabel);
        if ($targetStateId === null) {
            return false;
        }

        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return false;
        }

        $currentState = (int) $order->getCurrentState();
        if ($currentState === $targetStateId) {
            return false;
        }

        $order->setCurrentState($targetStateId);

        return true;
    }

    private function resolve(string $statusLabel): ?int
    {
        $label = trim($statusLabel);

        if (in_array($label, self::ACCEPTANCE_LABELS, true)) {
            return (int) \Configuration::get('PS_OS_PAYMENT');
        }

        if (in_array($label, self::REJECTION_LABELS, true)) {
            $failedId = (int) \Configuration::get(OrderStateInstaller::FAILED);

            return $failedId > 0 ? $failedId : null;
        }

        return null;
    }
}
