<?php

declare(strict_types=1);

/**
 * v2.0.3 upgrade + customer footer orphan-network guard (source + behavioral).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function assertV203Upgrade(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.0.0');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}
if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', $root . '/../');
}
if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        return addslashes($string);
    }
}

final class V203UpgradeFakeDb
{
    /** @var list<string> */
    public $executed = [];

    public function execute(string $sql): bool
    {
        $this->executed[] = $sql;

        return true;
    }
}

if (!class_exists('Db', false)) {
    class Db
    {
        /** @var V203UpgradeFakeDb|null */
        public static $instance;

        public static function getInstance()
        {
            return self::$instance;
        }
    }
}

final class V203UpgradeModuleStub
{
    /** @var list<string> */
    public $hooks = [];
    /** @var bool */
    public $failRegister = false;

    public function registerHook(string $hookName): bool
    {
        if ($this->failRegister) {
            return false;
        }
        $this->hooks[] = (string) $hookName;

        return true;
    }
}

Db::$instance = new V203UpgradeFakeDb();

require_once $root . '/upgrade/upgrade-2.0.3.php';

$module = new V203UpgradeModuleStub();
$ok = upgrade_module_2_0_3($module);
assertV203Upgrade($ok === true, 'upgrade_module_2_0_3 returns true');
assertV203Upgrade(in_array('actionCronJob', $module->hooks, true), 'upgrade registers actionCronJob');
assertV203Upgrade(
    (bool) preg_grep('/CREATE TABLE IF NOT EXISTS .*unipayment_orphan_sync/i', Db::$instance->executed),
    'upgrade creates orphan sync table'
);

$moduleFail = new V203UpgradeModuleStub();
$moduleFail->failRegister = true;
Db::$instance = new V203UpgradeFakeDb();
assertV203Upgrade(upgrade_module_2_0_3($moduleFail) === false, 'hook registration failure fails upgrade');

$moduleSrc = (string) file_get_contents($root . '/unipayment.php');
$footerPos = strpos($moduleSrc, 'function hookDisplayFooter');
$cronPos = strpos($moduleSrc, 'function hookActionCronJob');
assertV203Upgrade($footerPos !== false && $cronPos !== false, 'footer and cron hooks exist');

$footerChunk = substr($moduleSrc, $footerPos, $cronPos - $footerPos);
assertV203Upgrade(
    strpos($footerChunk, 'flushOrphanReportSync') === false
        && strpos($footerChunk, 'flushOperationalOutboundSync') === false
        && strpos($footerChunk, 'OrphanReportSyncService') === false
        && strpos($footerChunk, 'flushDuePending') === false
        && strpos($footerChunk, 'attemptDelivery') === false
        && strpos($footerChunk, 'reportOrderOrphan') === false,
    'hookDisplayFooter source must not invoke orphan network retry'
);

assertV203Upgrade(
    strpos($moduleSrc, 'function flushOrphanReportSync') !== false
        && strpos($moduleSrc, 'hookActionCronJob') !== false,
    'cron recovery path remains'
);

$upgradeSrc = (string) file_get_contents($root . '/upgrade/upgrade-2.0.3.php');
assertV203Upgrade(
    strpos($upgradeSrc, "registerHook('actionCronJob')") !== false,
    'upgrade script registers actionCronJob'
);

// --- Behavioral invocation of production hookDisplayFooter ---
if (!class_exists('Module', false)) {
    class Module
    {
        /** @var object|null */
        public $context;

        public function __construct() {}

        public function display(string $file, string $template): string
        {
            return 'rendered:' . $template;
        }

        public function trans(string $id, array $params = [], ?string $domain = null, ?string $locale = null): string
        {
            return $id;
        }
    }
}
if (!class_exists('PaymentModule', false)) {
    class PaymentModule extends Module {}
}

require_once $root . '/unipayment.php';

final class V203FooterProbeUnipayment extends Unipayment
{
    /** @var int */
    public $flushCalls = 0;
    /** @var int */
    public $deliveryCalls = 0;
    /** @var int */
    public $orphanPosts = 0;
    /** @var object|null */
    public $advertising = null;

    public function __construct()
    {
        // Skip PaymentModule bootstrap — reflection-built for hook only.
    }

    protected function flushOrphanReportSync(int $limit = 10): void
    {
        ++$this->flushCalls;
        ++$this->deliveryCalls;
        ++$this->orphanPosts;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function homepageAdvertisingContext(): ?array
    {
        return is_array($this->advertising) ? $this->advertising : null;
    }
}

$probe = new V203FooterProbeUnipayment();
$controller = (object) ['php_self' => 'index'];
$probe->context = (object) ['controller' => $controller, 'smarty' => new class {
    /** @var array<string, mixed> */
    public $assigned = [];

    /**
     * @param string|array<string, mixed> $key
     * @param mixed $value
     */
    public function assign(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            $this->assigned = array_replace($this->assigned, $key);
        } else {
            $this->assigned[$key] = $value;
        }
    }
}];

foreach (['index', 'product', 'cart', 'order'] as $phpSelf) {
    $probe->flushCalls = 0;
    $probe->deliveryCalls = 0;
    $probe->orphanPosts = 0;
    $probe->context->controller->php_self = $phpSelf;
    $probe->advertising = $phpSelf === 'index' ? ['title' => 'ad'] : null;
    $out = $probe->hookDisplayFooter([]);
    assertV203Upgrade($probe->flushCalls === 0, 'footer behavioral: flushCalls=0 on ' . $phpSelf);
    assertV203Upgrade($probe->deliveryCalls === 0, 'footer behavioral: deliveryCalls=0 on ' . $phpSelf);
    assertV203Upgrade($probe->orphanPosts === 0, 'footer behavioral: orphanPosts=0 on ' . $phpSelf);
    if ($phpSelf === 'index') {
        assertV203Upgrade(is_string($out), 'footer returns string on homepage');
    }
}

fwrite(STDOUT, "OK (v2.0.3 upgrade cron + footer orphan network guard)\n");
