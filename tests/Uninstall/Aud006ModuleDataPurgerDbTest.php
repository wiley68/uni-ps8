<?php

declare(strict_types=1);

/**
 * AUD-006 DB integration: purge drops module tables then recreates empty schema.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDOUT, "SKIP (AUD-006 DB purge; PS config missing)\n");
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

$db = Db::getInstance();
$tables = [
    ShopConfigurationCache::TABLE,
    OrderAttemptRepository::TABLE,
    FinancingSnapshotRepository::TABLE,
    PopupSubmissionRepository::TABLE,
    SmartUcfDebugLogRepository::TABLE,
    OrderBankStatusRepository::TABLE,
];

foreach ($tables as $table) {
    assertAud006Db(
        (new ShopConfigurationCache($db))->install()
            && (new OrderAttemptRepository($db))->install()
            && (new FinancingSnapshotRepository($db))->install()
            && (new PopupSubmissionRepository($db))->install()
            && (new SmartUcfDebugLogRepository($db))->install()
            && (new OrderBankStatusRepository($db))->install(),
        'precondition install'
    );
    break;
}

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

$purger = new ModuleDataPurger($db, null, $certStore, new OrderStateInstaller());
$result = $purger->purge();
assertAud006Db($result->isSuccess(), 'purge success: ' . implode(',', $result->errors()));

foreach ($tables as $table) {
    $exists = $db->executeS('SHOW TABLES LIKE "' . pSQL(_DB_PREFIX_ . $table) . '"');
    assertAud006Db(is_array($exists) && $exists !== [], '31: table recreated ' . $table);
    $count = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $table . '`');
    assertAud006Db($count === 0, '1: table empty after purge ' . $table);
}

assertAud006Db($repo->getUnicid() === '', '3: unicid cleared');
assertAud006Db(!$repo->hasSecret(), '4: secret cleared');
assertAud006Db(!$tokens->hasToken(), '5: tokens cleared');
assertAud006Db(!(bool) Configuration::get('UNIPAYMENT_CHECKOUT_LOCK_1_1'), 'checkout locks cleared');
assertAud006Db($repo->isEnabled() === false, 'module disabled after purge');
assertAud006Db(!is_file($keys . '/avalon_cert.pem'), '11: cert gone');
assertAud006Db(is_file($keys . '/.htaccess') || is_file($keys . '/index.php'), '14: protection present');

// Idempotent second purge
$result2 = $purger->purge();
assertAud006Db($result2->isSuccess(), '27: second purge ok');

// OrderState reuse: create pointer missing + historical state exists
$os = new OrderStateInstaller();
assertAud006Db($os->install(), 'OS install');
$idAwaiting = (int) Configuration::get(OrderStateInstaller::AWAITING);
assertAud006Db($idAwaiting > 0, 'OS awaiting id');
Configuration::deleteByName(OrderStateInstaller::AWAITING);
assertAud006Db($os->install(), '21: reinstall rebinds');
assertAud006Db((int) Configuration::get(OrderStateInstaller::AWAITING) === $idAwaiting, '21: no duplicate OS');

fwrite(STDOUT, "OK (AUD-006 DB purge integration)\n");
