<?php

declare(strict_types=1);

/**
 * SmartUCF debug shop-scoped authorization contracts.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace("'", "\\'", $string);
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogStoreInterface;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    /** @return mixed */
    public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return self::$values[$key] ?? $default;
    }
}

function assertDebugScope(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class DebugScopeStore implements SmartUcfDebugLogStoreInterface
{
    /** @var list<array<string, mixed>> */
    public $entries = [];

    public function insert(array $entry): bool
    {
        $this->entries[] = $entry;

        return true;
    }

    public function findLatestByOrderId(string $orderId): ?array
    {
        foreach (array_reverse($this->entries) as $entry) {
            if ($entry['order_id'] === $orderId) {
                return $entry;
            }
        }

        return null;
    }

    public function findLatestByOrderIdAndPsOrderId(string $orderId, int $psOrderId): ?array
    {
        foreach (array_reverse($this->entries) as $entry) {
            if ($entry['order_id'] === $orderId && (int) ($entry['ps_order_id'] ?? 0) === $psOrderId) {
                return $entry;
            }
        }

        return null;
    }

    public function findAll(): array
    {
        return $this->entries;
    }

    public function prune(?DateTimeImmutable $now = null): bool
    {
        return true;
    }
}

Configuration::$values[ConfigurationRepository::DEBUG_ENABLED] = true;
$store = new DebugScopeStore();
$journal = new SmartUcfDiagnosticJournal(new ConfigurationRepository(), $store);

$store->insert([
    'ps_order_id' => 101,
    'order_id' => 'SAME-REF-01',
    'http_code' => 200,
    'request' => ['ok' => true],
    'response' => ['ok' => true],
    'transport_error' => null,
    'created_at_gmt' => gmdate('Y-m-d H:i:s'),
]);
$store->insert([
    'ps_order_id' => 202,
    'order_id' => 'SAME-REF-01',
    'http_code' => 200,
    'request' => ['foreign' => true],
    'response' => ['foreign' => true],
    'transport_error' => null,
    'created_at_gmt' => gmdate('Y-m-d H:i:s'),
]);

$owned = $journal->findLatestForAuthorizedOrder('SAME-REF-01', 101);
assertDebugScope($owned !== null, 'authorized diagnostic found');
assertDebugScope((int) $owned['ps_order_id'] === 101, 'authorized ps_order_id');

$foreign = $journal->findLatestForAuthorizedOrder('SAME-REF-01', 999);
assertDebugScope($foreign === null, 'foreign/missing ps_order_id opaque miss');

$legacyGlobal = $journal->findLatestByOrderId('SAME-REF-01');
assertDebugScope($legacyGlobal !== null && (int) $legacyGlobal['ps_order_id'] === 202, 'unscoped latest still returns newest');

$ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/smartucfdebuglog.php');
assertDebugScope(strpos($ctrl, 'findLatestByOrderId(') === false || strpos($ctrl, 'findLatestForAuthorizedOrder') !== false, 'controller uses authorized lookup');
assertDebugScope(strpos($ctrl, 'resolveAuthorizedFinancingOrder') !== false, 'controller requires financing ownership');
assertDebugScope(strpos($ctrl, 'ORDER_ID_MAX') !== false, 'controller enforces order_id max 13');

fwrite(STDOUT, "OK (SmartUCF debug shop-scoped authorization)\n");
