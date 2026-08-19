<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class FinancingSnapshotRepository implements FinancingSnapshotStoreInterface
{
    public const TABLE = 'unipayment_financing_snapshot';
    private \Db $database;
    public function __construct(?\Db $database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        return (bool) $this->database->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_snapshot` INT UNSIGNED NOT NULL AUTO_INCREMENT, `id_attempt` INT UNSIGNED NOT NULL, `id_order` INT UNSIGNED NOT NULL,
            `order_reference` VARCHAR(13) NOT NULL, `cart_fingerprint` CHAR(64) NOT NULL, `scheme_type` VARCHAR(16) NOT NULL,
            `scheme_key` VARCHAR(64) NOT NULL, `kop_code` VARCHAR(64) NOT NULL, `months` SMALLINT UNSIGNED NOT NULL, `filter_id` INT UNSIGNED NOT NULL,
            `first_installment` DECIMAL(20,6) NOT NULL, `financed_amount` DECIMAL(20,6) NOT NULL, `monthly_installment` DECIMAL(20,6) NOT NULL,
            `total_payable` DECIMAL(20,6) NOT NULL, `glp` DECIMAL(20,6) NOT NULL, `gpr` DECIMAL(20,6) NOT NULL, `coefficient` DECIMAL(20,10) NOT NULL,
            `order_total` DECIMAL(20,6) NOT NULL, `currency_iso` CHAR(3) NOT NULL, `id_currency` INT UNSIGNED NOT NULL,
            `module_version` VARCHAR(11) NOT NULL, `submission_source` VARCHAR(32) NOT NULL,
            `customer_json` LONGTEXT NOT NULL, `address_json` LONGTEXT NOT NULL, `lines_json` LONGTEXT NOT NULL, `consents_json` LONGTEXT NOT NULL,
            `sensitive_payload` LONGTEXT NULL, `control_panel_order_id` BIGINT UNSIGNED NULL, `lifecycle_status` VARCHAR(32) NOT NULL, `leasing_email_sent` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`id_snapshot`),
            UNIQUE KEY `uniq_unipayment_snapshot_attempt` (`id_attempt`), UNIQUE KEY `uniq_unipayment_snapshot_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    public function save(int $attemptId, array $snapshot): void
    {
        $values = $snapshot;
        $values['id_attempt'] = $attemptId;
        foreach (['customer_json', 'address_json', 'lines_json', 'consents_json'] as $key) $values[$key] = json_encode($values[$key] ?? [], JSON_THROW_ON_ERROR);
        $values['created_at'] = $values['created_at'] ?? gmdate('Y-m-d H:i:s');
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        if (!$this->database->insert(self::TABLE, $values, false, true, \Db::INSERT_IGNORE) && $this->findByAttempt($attemptId) === null) throw new \RuntimeException('The financing snapshot could not be stored.');
    }

    public function findByAttempt(int $attemptId): ?array
    {
        $row = $this->database->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_attempt`=' . $attemptId);
        if (!is_array($row)) return null;
        foreach (['customer_json', 'address_json', 'lines_json', 'consents_json'] as $key) {
            $decoded = json_decode((string) $row[$key], true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    public function findByOrderId(int $idOrder): ?array
    {
        if ($idOrder <= 0) {
            return null;
        }
        $row = $this->database->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order`=' . $idOrder);
        if (!is_array($row)) return null;
        foreach (['customer_json', 'address_json', 'lines_json', 'consents_json'] as $key) {
            $decoded = json_decode((string) $row[$key], true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    public function update(int $attemptId, array $changes): void
    {
        $allowed = ['control_panel_order_id', 'lifecycle_status', 'leasing_email_sent'];
        $data = [];
        foreach ($changes as $key => $value) if (in_array($key, $allowed, true)) $data[$key] = $value;
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if (!$this->database->update(self::TABLE, $data, '`id_attempt`=' . $attemptId)) throw new \RuntimeException('The financing snapshot could not be updated.');
    }
}
