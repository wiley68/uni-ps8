<?php

declare(strict_types=1);

/**
 * AUD-006 DB integration: uninstall cleanup drops module tables and does NOT recreate them.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDOUT, "SKIP (AUD-006 DB uninstall cleanup; PS config missing)\n");
    exit(0);
}

require $config;
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Order\OrderStateInstaller;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;
use PrestaShop\Module\Unipayment\Uninstall\ModuleDataPurger;

function assertAud006Db(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function tableExists(Db $db, string $table): bool
{
    $exists = $db->executeS('SHOW TABLES LIKE "' . pSQL(_DB_PREFIX_ . $table) . '"');

    return is_array($exists) && $exists !== [];
}

$db = Db::getInstance();
$tables = [
    ShopConfigurationCache::TABLE,
    OrderAttemptRepository::TABLE,
    FinancingSnapshotRepository::TABLE,
    PopupSubmissionRepository::TABLE,
    SmartUcfDebugLogRepository::TABLE,
    OrderBankStatusRepository::TABLE,
];

assertAud006Db(
    (new ShopConfigurationCache($db))->install()
        && (new OrderAttemptRepository($db))->install()
        && (new FinancingSnapshotRepository($db))->install()
        && (new PopupSubmissionRepository($db))->install()
        && (new SmartUcfDebugLogRepository($db))->install()
        && (new OrderBankStatusRepository($db))->install(),
    'precondition install'
);

$repo = new ConfigurationRepository();
$repo->save(true, 'aud006-test-unicid', 'aud006-test-secret-value-xxxx', false, false);
$tokens = new TokenRepository();
$tokens->save('aud006-access-token', 'Bearer', time() + 3600);
Configuration::updateValue('UNIPAYMENT_CHECKOUT_LOCK_1_1', (string) (time() + 60));

$cache = new ShopConfigurationCache($db);
assertAud006Db($cache->replace('aud006-test-unicid', ['unicid' => 'aud006-test-unicid', 'uni_status' => 1]), 'seed cache');

$keys = sys_get_temp_dir() . '/unipayment-aud006-db-keys-' . getmypid();
@mkdir($keys, 0700, true);
file_put_contents($keys . '/avalon_cert.pem', "CERT\n");
file_put_contents($keys . '/avalon_private_key.pem', "KEY\n");
$certStore = new CertificateLocalStore($keys);

$os = new OrderStateInstaller();
assertAud006Db($os->install(), 'OS install before cleanup');
$idAwaiting = (int) Configuration::get(OrderStateInstaller::AWAITING);
assertAud006Db($idAwaiting > 0, 'OS awaiting id');

$purger = new ModuleDataPurger($db, null, $certStore, $os);
$result = $purger->purge();
assertAud006Db($result->isSuccess(), 'purge success: ' . implode(',', $result->errors()));

foreach ($tables as $table) {
    assertAud006Db(!tableExists($db, $table), '1/2: table removed and not recreated ' . $table);
}

assertAud006Db($repo->getUnicid() === '', '3: unicid cleared');
assertAud006Db(!$repo->hasSecret(), '4/5: secret/tokens cleared');
assertAud006Db(!$tokens->hasToken(), '5: tokens cleared');
assertAud006Db(!(bool) Configuration::get('UNIPAYMENT_CHECKOUT_LOCK_1_1'), 'checkout locks cleared');
assertAud006Db((int) Configuration::get(OrderStateInstaller::AWAITING) === 0, 'OS config pointer cleared');
assertAud006Db(!is_file($keys . '/avalon_cert.pem'), '12: cert gone');

// Idempotent second cleanup
$result2 = $purger->purge();
assertAud006Db($result2->isSuccess(), '23: second cleanup ok');

// Reinstall recreates schema and reuses historical OrderState when still present
assertAud006Db(
    (new ShopConfigurationCache($db))->install()
        && (new OrderAttemptRepository($db))->install()
        && (new FinancingSnapshotRepository($db))->install()
        && (new PopupSubmissionRepository($db))->install()
        && (new SmartUcfDebugLogRepository($db))->install()
        && (new OrderBankStatusRepository($db))->install()
        && $os->install()
        && $repo->install(),
    '28: reinstall schema'
);
$reboundAwaiting = (int) Configuration::get(OrderStateInstaller::AWAITING);
assertAud006Db($reboundAwaiting > 0, '19: OS rebound after reinstall');
$originalStillExists = Validate::isLoadedObject(new OrderState($idAwaiting));
if ($originalStillExists) {
    assertAud006Db($reboundAwaiting === $idAwaiting, '19/20: reused preserved OS, no duplicate');
}

fwrite(STDOUT, "OK (AUD-006 DB uninstall cleanup)\n");
