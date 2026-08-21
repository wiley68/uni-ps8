<?php

declare(strict_types=1);

/**
 * AUD-009 — bank status_id contract + rejection-only optional PS state sync.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatusOrderStateMapper;
use PrestaShop\Module\Unipayment\Order\BankStatusRejectionPolicy;
use PrestaShop\Module\Unipayment\Order\OrderStateInstaller;

function assertAud009(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- Production rejection whitelist is empty (codes unproven) ---
$prodPolicy = new BankStatusRejectionPolicy();
assertAud009($prodPolicy->rejectionStatusIds() === [], 'none proven: production whitelist empty');
assertAud009(!$prodPolicy->isRejection('05'), '05 is not a proven rejection code');
assertAud009(!$prodPolicy->isRejection('08'), '08 is signed-contract evidence, not rejection');
assertAud009(!$prodPolicy->isRejection('10'), '10 is activated evidence, not rejection');
assertAud009(!$prodPolicy->isRejection('Отказана'), '7: labels are never rejection identity');
assertAud009(!$prodPolicy->isRejection('Declined'), '7b: English labels never map');

$fixturePolicy = new BankStatusRejectionPolicy(['fixture-reject-01']);
assertAud009($fixturePolicy->isRejection('fixture-reject-01'), 'fixture rejection id recognized');
assertAud009(!$fixturePolicy->isRejection('fixture-other'), '5: unknown id not rejection');

// --- Mapper source contract ---
$mapperSrc = (string) file_get_contents($root . '/src/Order/BankStatusOrderStateMapper.php');
assertAud009(strpos($mapperSrc, 'Declined') === false, '10: Declined label mapping removed');
assertAud009(strpos($mapperSrc, 'Signed contract') === false, '8: Signed contract mapping removed');
assertAud009(strpos($mapperSrc, 'Activated contract') === false, '9: Activated mapping removed');
assertAud009(strpos($mapperSrc, 'PS_OS_PAYMENT') === false, '10b: no PS_OS_PAYMENT path');
assertAud009(strpos($mapperSrc, 'statusId') !== false, 'status_id is mapping identity');
assertAud009(strpos($mapperSrc, 'statusLabel') === false && strpos($mapperSrc, 'status_label') === false, '7: mapper does not inspect label');

$ctrlSrc = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');
assertAud009(strpos($ctrlSrc, 'apply(') !== false, 'controller calls mapper');
assertAud009(strpos($ctrlSrc, '$statusId') !== false, 'controller passes status_id');
assertAud009(strpos($ctrlSrc, 'trim($status)') !== false, '1: status label still persisted');
assertAud009(strpos($ctrlSrc, 'isSyncBankRejectionStateEnabled') !== false, '2: setting wired');
assertAud009(strpos($ctrlSrc, 'catch (\\Throwable') !== false, '15: PS sync failure isolated');

$policySrc = (string) file_get_contents($root . '/src/Order/BankStatusRejectionPolicy.php');
assertAud009(strpos($policySrc, 'REJECTION_STATUS_IDS = []') !== false, 'empty production whitelist declared');

$osSrc = (string) file_get_contents($root . '/src/Order/OrderStateInstaller.php');
assertAud009(strpos($osSrc, 'UNIPAYMENT_OS_REJECTED') !== false, '16: dedicated REJECTED state');
assertAud009(strpos($osSrc, 'Отказано финансиране от УниКредит') !== false, 'rejected display name');
assertAud009(strpos($osSrc, 'UNIPAYMENT_OS_FAILED') !== false, '16b: FAILED remains');
assertAud009(preg_match('/\$state->paid\s*=\s*false/', $osSrc) === 1, '17: paid=false');
assertAud009(preg_match('/\$state->invoice\s*=\s*false/', $osSrc) === 1, '18: invoice=false');
assertAud009(preg_match('/\$state->send_email\s*=\s*false/', $osSrc) === 1, '19: send_email=false');

$cfgSrc = (string) file_get_contents($root . '/src/Configuration/ConfigurationRepository.php');
assertAud009(strpos($cfgSrc, 'SYNC_BANK_REJECTION_STATE') !== false, '20: setting key');
assertAud009(strpos($cfgSrc, 'DEFAULT_SYNC_BANK_REJECTION_STATE = false') !== false, '20b: default false');
assertAud009(strpos($cfgSrc, 'self::SYNC_BANK_REJECTION_STATE') !== false, '22: uninstall includes key');

$tpl = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
assertAud009(strpos($tpl, 'UNIPAYMENT_SYNC_BANK_REJECTION_STATE') !== false, '21: BO setting');
assertAud009(strpos($tpl, 'При банков отказ промени статуса на поръчката') !== false, 'BO label');

$moduleSrc = (string) file_get_contents($root . '/unipayment.php');
assertAud009(strpos($moduleSrc, "version = '2.0.0'") !== false, 'version 2.0.0');
assertAud009(strpos($moduleSrc, 'unipayment_bank_status') !== false, '26: grid bank status display remains');

assertAud009(
    !is_file($root . '/upgrade/upgrade-2.0.1.php')
        && (glob($root . '/upgrade/upgrade-*.php') ?: []) === [],
    'no upgrade scripts'
);

// --- Behavioral mapper with stubs (no PS bootstrap) ---
final class Aud009OrderStub
{
    /** @var array<int, int> */
    public static $statesById = [];

    /** @var list<int> */
    public static $transitions = [];

    public int $id = 0;

    public function __construct(?int $id = null)
    {
        $this->id = (int) ($id ?? 0);
        if ($this->id > 0 && !isset(self::$statesById[$this->id])) {
            self::$statesById[$this->id] = 20;
        }
    }

    public function getCurrentState(): int
    {
        return (int) (self::$statesById[$this->id] ?? 0);
    }

    public function setCurrentState(int $idOrderState, int $idEmployee = 0): bool
    {
        unset($idEmployee);
        self::$transitions[] = $idOrderState;
        self::$statesById[$this->id] = $idOrderState;

        return true;
    }
}

if (!class_exists('Configuration', false)) {
    final class Configuration
    {
        /** @var array<string, mixed> */
        public static $values = [];

        /**
         * @param mixed $default
         * @return mixed
         */
        public static function get(
            string $key,
            ?int $idLang = null,
            ?int $idShopGroup = null,
            ?int $idShop = null,
            $default = false
        ) {
            return self::$values[$key] ?? $default;
        }
    }
}
if (!class_exists('Validate', false)) {
    final class Validate
    {
        /**
         * @param object|null $object
         */
        public static function isLoadedObject($object): bool
        {
            return is_object($object) && isset($object->id) && (int) $object->id > 0;
        }
    }
}
if (!class_exists('Order', false)) {
    class_alias(Aud009OrderStub::class, 'Order');
}

Configuration::$values[OrderStateInstaller::REJECTED] = 55;
Configuration::$values[OrderStateInstaller::FAILED] = 22;
Aud009OrderStub::$transitions = [];
Aud009OrderStub::$statesById = [];

$mapper = new BankStatusOrderStateMapper(new BankStatusRejectionPolicy(['fixture-reject-01']));

assertAud009(
    $mapper->apply(100, 'fixture-reject-01', false) === false
        && Aud009OrderStub::$transitions === [],
    '3: OFF → no PS mutation'
);

assertAud009(
    $mapper->apply(100, 'unknown-id', true) === false
        && Aud009OrderStub::$transitions === [],
    '5: ON + unknown → no mutation'
);

assertAud009(
    $mapper->apply(100, 'fixture-reject-01', true) === true
        && Aud009OrderStub::$transitions === [55]
        && Aud009OrderStub::$statesById[100] === 55,
    '4: ON + fixture rejection → rejected state'
);

assertAud009(
    $mapper->apply(100, 'fixture-reject-01', true) === false
        && Aud009OrderStub::$transitions === [55],
    '11/12: already rejected → no duplicate transition'
);

Aud009OrderStub::$transitions = [];
assertAud009($mapper->apply(101, 'fixture-reject-01', true) === true, '6: status_id drives mapping');
assertAud009(Aud009OrderStub::$transitions === [55], '6b: identical for any label (label unused)');

assertAud009(
    $mapper->apply(102, '08', true) === false
        && $mapper->apply(102, '10', true) === false,
    '8/9: signed/activated status_ids do not map'
);

fwrite(STDOUT, "OK (AUD-009 bank status rejection sync contracts)\n");
