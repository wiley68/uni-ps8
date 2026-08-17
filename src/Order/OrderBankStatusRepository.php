<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderBankStatusRepository
{
    public const TABLE = 'unipayment_order_bank_status';

    /** @var \Db */
    private $database;

    public function __construct(?\Db $database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->tableName() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `order_id` VARCHAR(64) NOT NULL,
            `status_id` VARCHAR(255) NOT NULL,
            `status_label` VARCHAR(255) NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_unipayment_bank_id_order` (`id_order`),
            KEY `idx_unipayment_bank_order_id` (`order_id`),
            KEY `idx_unipayment_bank_id_shop` (`id_shop`)
        ) ENGINE=' . constant('_MYSQL_ENGINE_') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    /** @return array<string, mixed>|null */
    public function updateByOrderIdentifier(string $orderId, string $statusId, string $statusLabel): ?array
    {
        $order = $this->findOrder($orderId);
        if ($order === null) {
            return null;
        }

        $values = [
            'id_order' => (string) $order['id_order'],
            'id_shop' => (string) $order['id_shop'],
            'order_id' => $orderId,
            'status_id' => $statusId,
            'status_label' => $statusLabel,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $columns = [];
        $sqlValues = [];
        $updates = [];
        foreach ($values as $column => $value) {
            $columns[] = '`' . $column . '`';
            $sqlValues[] = "'" . pSQL($value, true) . "'";
            if ($column !== 'id_order') {
                $updates[] = '`' . $column . '` = VALUES(`' . $column . '`)';
            }
        }

        $saved = $this->database->execute(sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $sqlValues),
            implode(', ', $updates)
        ));
        if (!$saved) {
            throw new \RuntimeException('The bank status could not be stored.');
        }

        return [
            'order_id' => $orderId,
            'ps_order_id' => (int) $order['id_order'],
            'status' => $statusLabel,
            'status_id' => $statusId,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByOrderId(int $idOrder): ?array
    {
        if ($idOrder <= 0) {
            return null;
        }

        $row = $this->database->getRow(sprintf(
            'SELECT `order_id`, `status_id`, `status_label`, `updated_at` FROM `%s` WHERE `id_order` = %d',
            $this->tableName(),
            $idOrder
        ));

        return is_array($row) ? $row : null;
    }

    /** @return array{id_order: int, id_shop: int}|null */
    private function findOrder(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $where = ctype_digit($orderId)
            ? '`id_order` = ' . (int) $orderId
            : "`reference` = '" . pSQL($orderId) . "'";
        $row = $this->database->getRow(sprintf(
            'SELECT `id_order`, `id_shop` FROM `%sorders` WHERE %s ORDER BY `id_order` DESC',
            _DB_PREFIX_,
            $where
        ));
        if (!is_array($row)) {
            return null;
        }

        return [
            'id_order' => (int) $row['id_order'],
            'id_shop' => (int) $row['id_shop'],
        ];
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
