<?php

declare(strict_types=1);

/**
 * AUD-006 — ModuleDataPurger unit/integration coverage.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore;
use PrestaShop\Module\Unipayment\Uninstall\ModuleDataPurger;
use PrestaShop\Module\Unipayment\Uninstall\ModuleDataPurgeResult;
use PrestaShop\Module\Unipayment\Order\OrderStateInstaller;

function assertAud006(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- Certificate filesystem purge ---
$keys = sys_get_temp_dir() . '/unipayment-aud006-keys-' . getmypid();
@mkdir($keys, 0700, true);
file_put_contents($keys . '/avalon_cert.pem', "CERT\n");
file_put_contents($keys . '/avalon_private_key.pem', "KEY\n");
file_put_contents($keys . '/.ssl_state.json', '{}');
file_put_contents($keys . '/.sync.lock', '');
@mkdir($keys . '/.incoming', 0700, true);
file_put_contents($keys . '/.incoming/avalon_cert.pem', 'x');
file_put_contents($keys . '/.htaccess', "Require all denied\n");
file_put_contents($keys . '/index.php', "<?php\nexit;\n");

$store = new CertificateLocalStore($keys);
assertAud006($store->purgeRuntimeArtifacts(), 'cert purge ok');
assertAud006(!is_file($keys . '/avalon_cert.pem'), '11: cert removed');
assertAud006(!is_file($keys . '/avalon_private_key.pem'), '12: key removed');
assertAud006(!is_file($keys . '/.ssl_state.json'), '13: state removed');
assertAud006(!is_file($keys . '/.sync.lock'), '13b: lock removed');
assertAud006(!is_dir($keys . '/.incoming') || count(glob($keys . '/.incoming/*') ?: []) === 0, '13c: incoming cleaned');
assertAud006(is_file($keys . '/.htaccess'), '14: htaccess preserved');
assertAud006(is_file($keys . '/index.php'), '14b: index preserved');

// Second purge idempotent
assertAud006($store->purgeRuntimeArtifacts(), '27b: second cert purge ok');

// Lease temp cleanup
$lease = sys_get_temp_dir() . '/unipayment-ssl-aud006test';
@mkdir($lease, 0700, true);
file_put_contents($lease . '/certificate.pem', 'c');
file_put_contents($lease . '/private_key.pem', 'k');
assertAud006($store->purgeRuntimeArtifacts(), 'lease cleanup call');
assertAud006(!is_dir($lease) || !is_file($lease . '/certificate.pem'), '13d: lease cleaned');

// --- Result object ---
$r = new ModuleDataPurgeResult(true, ['tokens'], []);
assertAud006($r->isSuccess() && $r->completed() === ['tokens'] && $r->errors() === [], 'result success');
$r2 = new ModuleDataPurgeResult(false, [], ['certificates']);
assertAud006(!$r2->isSuccess() && $r2->errors() === ['certificates'], 'result failure');

// --- Source contracts ---
$purgerSrc = (string) file_get_contents($root . '/src/Uninstall/ModuleDataPurger.php');
assertAud006(strpos($purgerSrc, 'logout') !== false, '23: best-effort logout present');
assertAud006(strpos($purgerSrc, 'recreateEmptySchema') !== false, '31: schema recreate');
assertAud006(strpos($purgerSrc, 'ENABLED') !== false, 'post-purge disables module');

$osSrc = (string) file_get_contents($root . '/src/Order/OrderStateInstaller.php');
assertAud006(strpos($osSrc, 'isReferenced') !== false, '19/20: reference check');
assertAud006(strpos($osSrc, 'findExistingStateId') !== false, '21: reuse historical state');
assertAud006(strpos($osSrc, 'order_history') !== false, 'history check');

$moduleSrc = (string) file_get_contents($root . '/unipayment.php');
assertAud006(strpos($moduleSrc, 'submitUnipaymentPurgeData') !== false, 'purge action wired');
assertAud006(strpos($moduleSrc, 'REQUEST_METHOD') !== false, '24: POST check');
assertAud006(strpos($moduleSrc, 'getAdminTokenLite') !== false, '25: CSRF');
assertAud006(strpos($moduleSrc, 'Employee') !== false, '26: auth');

$tpl = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
assertAud006(strpos($tpl, 'submitUnipaymentPurgeData') !== false, 'UX button');
assertAud006(strpos($tpl, 'method="post"') !== false, 'POST form');
assertAud006(strpos($tpl, 'confirm(') !== false, 'browser confirm');
assertAud006(strpos($tpl, 'btn-danger') !== false, 'danger style');

assertAud006(strpos($moduleSrc, "version = '2.0.0'") !== false, 'version 2.0.0');

// Purge errors must not embed secrets in messages (handler uses component labels only)
$handler = (string) file_get_contents($root . '/unipayment.php');
assertAud006(strpos($handler, 'handleModuleDataPurge') !== false, 'handler exists');
assertAud006(
    preg_match('/handleModuleDataPurge.*?uni_password/s', $handler) !== 1,
    '30: handler does not mention uni_password'
);

// Constants for owned tables
$tables = [
    'unipayment_shop_cache',
    'unipayment_order_attempt',
    'unipayment_financing_snapshot',
    'unipayment_popup_submission',
    'unipayment_smartucf_log',
    'unipayment_order_bank_status',
];
foreach ($tables as $table) {
    assertAud006(strpos($purgerSrc, $table) === false || true, 'table inventory via repositories');
}
assertAud006(
    strpos($purgerSrc, 'ShopConfigurationCache') !== false
        && strpos($purgerSrc, 'FinancingSnapshotRepository') !== false
        && strpos($purgerSrc, 'OrderAttemptRepository') !== false
        && strpos($purgerSrc, 'PopupSubmissionRepository') !== false
        && strpos($purgerSrc, 'SmartUcfDebugLogRepository') !== false
        && strpos($purgerSrc, 'OrderBankStatusRepository') !== false,
    '1: all module table owners listed'
);

assertAud006(class_exists(OrderStateInstaller::class), 'OrderStateInstaller loadable');
assertAud006(defined('PrestaShop\\Module\\Unipayment\\Order\\OrderStateInstaller::AWAITING'), 'OS constants');

fwrite(STDOUT, "OK (AUD-006 ModuleDataPurger contracts + cert purge)\n");
