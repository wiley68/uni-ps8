<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Persistence for durable orphan-report intents.
 */
interface OrphanSyncStoreInterface
{
    /**
     * Ensure a unique pending intent exists for (id_shop, order_id, event_type).
     *
     * @return array<string, mixed>
     */
    public function ensurePendingIntent(
        int $idShop,
        int $idOrder,
        string $orderId,
        string $orderDate,
        string $statusId,
        string $eventType = OrphanSyncStates::EVENT_CP_CREATE_ORPHAN
    ): array;

    /**
     * Claim one due pending row for outbound delivery (CAS).
     *
     * @return array<string, mixed>|null
     */
    public function claimDuePending(int $idOrphanSync, int $backoffSeconds, int $nowTimestamp): ?array;

    /** @return list<array<string, mixed>> */
    public function findDuePending(int $limit, int $nowTimestamp): array;

    public function markConfirmed(int $idOrphanSync, string $result): bool;

    public function markRetryable(int $idOrphanSync, string $result, string $errorClass, int $nextAttemptAt): bool;

    public function markTerminalFailed(int $idOrphanSync, string $result, string $errorClass): bool;

    /** @return array<string, mixed>|null */
    public function findByIdentity(int $idShop, string $orderId, string $eventType = OrphanSyncStates::EVENT_CP_CREATE_ORPHAN): ?array;
}
