<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

final class SmartUcfDebugLogRepository
{
    public const TABLE = 'unipayment_smartucf_log';

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
            `order_id` VARCHAR(64) NOT NULL,
            `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `request_json` LONGTEXT NOT NULL,
            `response_json` LONGTEXT NOT NULL,
            `transport_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_unipayment_smartucf_order_id` (`order_id`),
            KEY `idx_unipayment_smartucf_id_order` (`id_order`),
            KEY `idx_unipayment_smartucf_created_at` (`created_at`)
        ) ENGINE=' . constant('_MYSQL_ENGINE_') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    /** @return array<string, mixed>|null */
    public function findLatestByOrderId(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $row = $this->database->getRow(sprintf(
            "SELECT * FROM `%s` WHERE `order_id` = '%s' ORDER BY `id` DESC",
            $this->tableName(),
            pSQL($orderId)
        ));
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'order_id' => (string) $row['order_id'],
            'ps_order_id' => (int) $row['id_order'],
            'http_code' => (int) $row['http_status'],
            'created_at_gmt' => (string) $row['created_at'],
            'request' => $this->decodeBody((string) $row['request_json']),
            'response' => $this->decodeBody((string) $row['response_json']),
            'transport_error' => $row['transport_error'] !== null ? (string) $row['transport_error'] : null,
        ];
    }

    private function decodeBody(string $body)
    {
        $decoded = json_decode($body, true);

        return $decoded !== null || $body === 'null' ? $decoded : $body;
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
