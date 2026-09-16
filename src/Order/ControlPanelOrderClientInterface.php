<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

interface ControlPanelOrderClientInterface
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createOrder(array $payload): array;

    /** @return array<string, mixed> */
    public function updateOrderStatus(string $orderId, string $status, string $statusId): array;

    /**
     * Report a Shop-local bank_send_failed_cp orphan (no CP financing order).
     *
     * @return array<string, mixed>
     */
    public function reportOrderOrphan(string $orderId, string $orderDate): array;
}
