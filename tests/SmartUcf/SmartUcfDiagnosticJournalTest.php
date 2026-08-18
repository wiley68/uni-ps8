<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    public static $values = [];
    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false) { return self::$values[$key] ?? $default; }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogStoreInterface;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;

function assertJournal(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

final class MemoryDebugStore implements SmartUcfDebugLogStoreInterface
{
    public $entries = [];
    public function insert(array $entry): bool { $entry['id'] = count($this->entries) + 1; $this->entries[] = $entry; return true; }
    public function findLatestByOrderId(string $orderId): ?array { foreach (array_reverse($this->entries) as $entry) if ($entry['order_id'] === $orderId) return $entry; return null; }
    public function findAll(): array { return $this->entries; }
    public function prune(?DateTimeImmutable $now = null): bool { return true; }
}

$configuration = new ConfigurationRepository();
$store = new MemoryDebugStore();
$journal = new SmartUcfDiagnosticJournal($configuration, $store);
assertJournal(!$journal->record(1, 'ORDER-1', 200, ['secret' => 'do-not-store'], []), 'disabled debug journal persisted an entry');
assertJournal($store->entries === [], 'disabled debug journal reached persistence');

Configuration::$values[ConfigurationRepository::DEBUG_ENABLED] = true;
assertJournal($journal->record(1, 'ORDER-1', 200, [
    'user' => 'merchant', 'pass' => 'password', 'clientEmail' => 'person@example.com', 'nested' => ['access_token' => 'token-value'],
], ['Authorization' => 'Bearer abc.def'], 'token=transport-secret'), 'enabled debug journal did not persist');
$json = json_encode($journal->buildExport(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
assertJournal(json_decode($json, true) !== null, 'debug export is not valid JSON');
assertJournal(count($store->entries) === 1, 'debug export deleted journal entries');
foreach (['merchant', 'password', 'person@example.com', 'token-value', 'abc.def', 'transport-secret'] as $secret) {
    assertJournal(strpos($json, $secret) === false, "debug export leaked {$secret}");
}
assertJournal(strpos($json, '[REDACTED]') !== false, 'debug export did not contain redaction markers');

fwrite(STDOUT, "OK (debug persistence gate, sanitization and JSON export)\n");
