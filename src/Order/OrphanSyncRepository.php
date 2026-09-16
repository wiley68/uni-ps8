<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Module-owned durable orphan-report sync table.
 */
final class OrphanSyncRepository implements OrphanSyncStoreInterface
{
    public const TABLE = 'unipayment_orphan_sync';

    /** @var \Db|object */
    private $database;

    /**
     * @param \Db|object|null $database
     */
    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->tableName() . '` (
            `id_orphan_sync` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_order` INT UNSIGNED NOT NULL,
            `order_id` VARCHAR(13) NOT NULL,
            `event_type` VARCHAR(64) NOT NULL,
            `order_date` DATE NOT NULL,
            `status_id` VARCHAR(64) NOT NULL,
            `state` VARCHAR(32) NOT NULL,
            `last_result` VARCHAR(64) NULL,
            `last_error_class` VARCHAR(128) NULL,
            `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `next_attempt_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_orphan_sync`),
            UNIQUE KEY `uniq_unipayment_orphan_identity` (`id_shop`, `order_id`, `event_type`),
            KEY `idx_unipayment_orphan_due` (`state`, `next_attempt_at`),
            KEY `idx_unipayment_orphan_id_order` (`id_order`)
        ) ENGINE=' . constant('_MYSQL_ENGINE_') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    public function ensurePendingIntent(
        int $idShop,
        int $idOrder,
        string $orderId,
        string $orderDate,
        string $statusId,
        string $eventType = OrphanSyncStates::EVENT_CP_CREATE_ORPHAN
    ): array {
        $orderId = substr(trim($orderId), 0, 13);
        $existing = $this->findByIdentity($idShop, $orderId, $eventType);
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->database->execute(sprintf(
            'INSERT IGNORE INTO `%s` (
                `id_shop`, `id_order`, `order_id`, `event_type`, `order_date`, `status_id`,
                `state`, `last_result`, `last_error_class`, `attempt_count`, `next_attempt_at`,
                `created_at`, `updated_at`
            ) VALUES (%d, %d, \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', NULL, NULL, 0, NULL, \'%s\', \'%s\')',
            $this->tableName(),
            $idShop,
            $idOrder,
            pSQL($orderId),
            pSQL($eventType),
            pSQL($orderDate),
            pSQL($statusId),
            pSQL(OrphanSyncStates::PENDING),
            pSQL($now),
            pSQL($now)
        ));

        $row = $this->findByIdentity($idShop, $orderId, $eventType);
        if ($row === null) {
            throw new \RuntimeException('The orphan sync intent could not be persisted.');
        }

        return $row;
    }

    public function claimDuePending(int $idOrphanSync, int $backoffSeconds, int $nowTimestamp): ?array
    {
        $now = gmdate('Y-m-d H:i:s', $nowTimestamp);
        $next = gmdate('Y-m-d H:i:s', $nowTimestamp + max(1, $backoffSeconds));
        $updated = $this->database->execute(sprintf(
            'UPDATE `%s` SET
                `attempt_count` = `attempt_count` + 1,
                `next_attempt_at` = \'%s\',
                `updated_at` = \'%s\'
             WHERE `id_orphan_sync` = %d
               AND `state` = \'%s\'
               AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= \'%s\')',
            $this->tableName(),
            pSQL($next),
            pSQL($now),
            $idOrphanSync,
            pSQL(OrphanSyncStates::PENDING),
            pSQL($now)
        ));
        if (!$updated || (int) $this->database->Affected_Rows() !== 1) {
            return null;
        }

        return $this->findById($idOrphanSync);
    }

    public function findDuePending(int $limit, int $nowTimestamp): array
    {
        $now = gmdate('Y-m-d H:i:s', $nowTimestamp);
        $rows = $this->database->executeS(sprintf(
            'SELECT * FROM `%s`
             WHERE `state` = \'%s\'
               AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= \'%s\')
             ORDER BY `id_orphan_sync` ASC
             LIMIT %d',
            $this->tableName(),
            pSQL(OrphanSyncStates::PENDING),
            pSQL($now),
            max(1, $limit)
        ));

        return is_array($rows) ? $rows : [];
    }

    public function markConfirmed(int $idOrphanSync, string $result): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        return (bool) $this->database->execute(sprintf(
            'UPDATE `%s` SET
                `state` = \'%s\',
                `last_result` = \'%s\',
                `last_error_class` = NULL,
                `updated_at` = \'%s\'
             WHERE `id_orphan_sync` = %d
               AND `state` = \'%s\'',
            $this->tableName(),
            pSQL(OrphanSyncStates::CONFIRMED),
            pSQL($result),
            pSQL($now),
            $idOrphanSync,
            pSQL(OrphanSyncStates::PENDING)
        ));
    }

    public function markRetryable(int $idOrphanSync, string $result, string $errorClass, int $nextAttemptAt): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        return (bool) $this->database->execute(sprintf(
            'UPDATE `%s` SET
                `state` = \'%s\',
                `last_result` = \'%s\',
                `last_error_class` = \'%s\',
                `next_attempt_at` = \'%s\',
                `updated_at` = \'%s\'
             WHERE `id_orphan_sync` = %d
               AND `state` = \'%s\'',
            $this->tableName(),
            pSQL(OrphanSyncStates::PENDING),
            pSQL($result),
            pSQL($errorClass),
            pSQL(gmdate('Y-m-d H:i:s', $nextAttemptAt)),
            pSQL($now),
            $idOrphanSync,
            pSQL(OrphanSyncStates::PENDING)
        ));
    }

    public function markTerminalFailed(int $idOrphanSync, string $result, string $errorClass): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        return (bool) $this->database->execute(sprintf(
            'UPDATE `%s` SET
                `state` = \'%s\',
                `last_result` = \'%s\',
                `last_error_class` = \'%s\',
                `updated_at` = \'%s\'
             WHERE `id_orphan_sync` = %d',
            $this->tableName(),
            pSQL(OrphanSyncStates::TERMINAL_FAILED),
            pSQL($result),
            pSQL($errorClass),
            pSQL($now),
            $idOrphanSync
        ));
    }

    public function findByIdentity(int $idShop, string $orderId, string $eventType = OrphanSyncStates::EVENT_CP_CREATE_ORPHAN): ?array
    {
        $row = $this->database->getRow(sprintf(
            'SELECT * FROM `%s` WHERE `id_shop` = %d AND `order_id` = \'%s\' AND `event_type` = \'%s\'',
            $this->tableName(),
            $idShop,
            pSQL(substr(trim($orderId), 0, 13)),
            pSQL($eventType)
        ));

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findById(int $idOrphanSync): ?array
    {
        $row = $this->database->getRow(sprintf(
            'SELECT * FROM `%s` WHERE `id_orphan_sync` = %d',
            $this->tableName(),
            $idOrphanSync
        ));

        return is_array($row) ? $row : null;
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
