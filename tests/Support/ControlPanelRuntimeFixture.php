<?php

declare(strict_types=1);

// In-memory platform boundary for actual module factories and configuration services.
define('_PS_VERSION_', '8.2.0');
define('__PS_BASE_URI__', '/');
define('_DB_PREFIX_', 'fixture_');
define('_NEW_COOKIE_KEY_', 'test-key');

class Configuration
{
    public static array $values = [];
    public static function get($key, $lang = null, $group = null, $shop = null, $default = false)
    {
        return self::$values[$key] ?? $default;
    }
    public static function updateValue($key, $value, $html = false, $group = null, $shop = null): bool
    {
        self::$values[$key] = $value;
        return true;
    }
    public static function deleteByName($key): bool
    {
        unset(self::$values[$key]);
        return true;
    }
}

class PhpEncryption
{
    public function __construct(string $key) {}
    public function encrypt(string $text): string { return base64_encode($text); }
    public function decrypt(string $text) { return base64_decode($text, true); }
}

function pSQL(string $value, bool $html = false): string { return addslashes($value); }

class Db
{
    public ?array $row = null;
    public static function getInstance(): self
    {
        static $db;
        return $db ?? ($db = new self());
    }
    public function getRow(string $sql) { return $this->row; }
    public function getValue(string $sql) { return 1; }
    public function executeS(string $sql): array
    {
        $rows = [];
        foreach (['UNIPAYMENT_SMARTUCF_USER', 'UNIPAYMENT_SMARTUCF_PASSWORD'] as $name) {
            if (isset(Configuration::$values[$name])) {
                $rows[] = ['name' => $name, 'value' => Configuration::$values[$name]];
            }
        }
        return $rows;
    }
    public function execute(string $sql): bool
    {
        if (strpos($sql, 'INSERT INTO `fixture_unipayment_shop_cache`') === 0) {
            if (!preg_match("/VALUES \\('([^']*)', '((?:\\\\.|[^'])*)'/s", $sql, $matches)) {
                throw new RuntimeException('Fixture cannot read cache SQL.');
            }
            $this->row = [
                'shop_data' => stripslashes($matches[2]),
                'fetched_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            ];
        }
        if (strpos($sql, 'DELETE FROM `fixture_unipayment_shop_cache`') === 0) {
            $this->row = null;
        }
        return true;
    }
}

class Context
{
    public $shop;
    public $currency;
    public static function getContext(): self
    {
        static $context;
        if ($context === null) {
            $context = new self();
            $context->shop = (object) ['id' => 1];
            $context->currency = (object) ['iso_code' => 'EUR'];
        }
        return $context;
    }
}

class Tools
{
    public static function getShopDomainSsl(bool $protocol): string { return 'https://merchant.example.org'; }
}
class PrestaShopLogger
{
    public static function addLog(string $message, int $severity = 1): void {}
}

#[AllowDynamicProperties]
class PaymentModule
{
    public function __construct() { $this->context = Context::getContext(); }
    public function trans(string $message, array $params = [], string $domain = ''): string { return $message; }
    public function displayConfirmation(string $message): string { return 'success:' . $message; }
    public function displayError($message): string { return 'error'; }
}
#[AllowDynamicProperties]
class ModuleFrontController {}
