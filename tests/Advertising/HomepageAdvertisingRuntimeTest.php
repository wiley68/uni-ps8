<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

function assertAdvertisingRuntime(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

if (!isset($argv[1])) {
    $cases = [];
    foreach ([0, 1, null] as $local) {
        foreach ([0, 1] as $cp) {
            foreach ([false, true] as $mobile) {
                $cases[] = ['local' => $local, 'cp' => $cp, 'mobile' => $mobile];
            }
        }
    }
    foreach (['pull', 'push', 'refresh'] as $mode) {
        foreach ([0, 1] as $cp) {
            $cases[] = ['mode' => $mode, 'cp' => $cp];
        }
    }
    foreach ([
        ['active' => false], ['enabled' => false], ['unicid' => ''], ['unicid' => '   '],
        ['page' => 'category'], ['status' => 0], ['cp' => null], ['mode' => 'failure'],
    ] as $blocked) {
        $cases[] = $blocked + ['visible' => false];
    }
    foreach ($cases as $index => $case) {
        // The production context is cached per request; each case gets a fresh process.
        $process = proc_open([PHP_BINARY, __FILE__, json_encode($case, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        assertAdvertisingRuntime(is_resource($process), 'Cannot start advertising fixture');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        assertAdvertisingRuntime(proc_close($process) === 0, 'Case ' . $index . ': ' . $output);
    }
    fwrite(STDOUT, 'OK (Homepage advertising hooks: ' . count($cases) . " isolated cases; no live DB/network)\n");
    exit(0);
}

$case = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR) + [
    'local' => 0, 'cp' => 1, 'mobile' => false, 'mode' => 'cache',
    'active' => true, 'enabled' => true, 'unicid' => '123e4567-e89b-12d3-a456-426614174000',
    'page' => 'index', 'status' => 1,
];
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/tests/Support/ControlPanelRuntimeFixture.php';
require $root . '/tests/Support/ControlPanelDnsFixture.php';
require $root . '/tests/Support/ControlPanelTransportFixture.php';
require $root . '/tests/fixtures/shop_snapshot.php';
require $root . '/unipayment.php';
require $root . '/controllers/front/shopcache.php';

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;

Configuration::$values = [
    ConfigurationRepository::ENABLED => $case['enabled'],
    ConfigurationRepository::UNICID => $case['unicid'],
    ConfigurationRepository::SECRET => 'enc:v1:' . base64_encode('synthetic-test-secret'),
];
if ($case['local'] !== null) {
    Configuration::$values[ConfigurationRepository::ADVERTISING_ENABLED] = $case['local'];
}
$snapshot = unipayment_valid_shop_snapshot([
    'uni_status' => $case['status'], 'uni_container_status' => $case['cp'],
    'uni_backurl' => 'https://bank.example.test/info',
    'uni_picturem' => 'https://cdn.example.test/banner.jpg',
]);
if ($case['cp'] === null) { unset($snapshot['uni_container_status']); }
$GLOBALS['unipaymentCpCurlInit'] = 0;
$GLOBALS['unipaymentCpCurlRequests'] = [];
$GLOBALS['unipaymentCpResponse'] = static function (string $url) use ($case, $snapshot): string {
    if ($case['mode'] === 'failure') { throw new ConnectionException('Synthetic transport failure'); }
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === '/api/v1/auth/login') {
        $data = ['access_token' => 'synthetic-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            'shop' => ['unicid' => $snapshot['unicid']]];
    } else {
        assertAdvertisingRuntime($path === '/api/v1/shop', 'Unexpected external operation');
        $data = $snapshot;
    }
    return json_encode(['success' => true, 'error' => null, 'data' => $data], JSON_THROW_ON_ERROR);
};

$module = new class extends Unipayment {
    public function display($file, $template): string { return $template; }
};
$module->active = $case['active'];
$module->_path = '/modules/unipayment/';
$module->context = new class {
    public $controller;
    public $smarty;
    public $mobile;
    public function isMobile(): bool { return $this->mobile; }
};
$module->context->mobile = $case['mobile'];
$module->context->smarty = new class {
    public $values = [];
    public function assign(array $values): void { $this->values = $values; }
};
$module->context->controller = new class {
    public $php_self;
    public $styles = [];
    public $scripts = [];
    public function registerStylesheet($id, $path, $options): void { $this->styles[$id] = $path; }
    public function registerJavascript($id, $path, $options): void { $this->scripts[$id] = $path; }
};
$module->context->controller->php_self = $case['page'];

$cache = new ShopConfigurationCache();
if (!in_array($case['mode'], ['pull', 'failure'], true)) {
    $cached = $snapshot;
    if (in_array($case['mode'], ['push', 'refresh'], true)) {
        $cached['uni_container_status'] = 1 - $case['cp'];
    }
    $cache->replace($snapshot['unicid'], $cached);
}
if ($case['mode'] === 'push') {
    $callback = new UnipaymentShopcacheModuleFrontController();
    $handler = new ReflectionMethod($callback, 'handleAuthenticatedRequest');
    $response = $handler->invoke($callback, ['data' => $snapshot], $snapshot['unicid']);
    assertAdvertisingRuntime($response['success'], 'CP push failed');
}
if ($case['mode'] === 'refresh') {
    $handler = new ReflectionMethod(Unipayment::class, 'handleBankDataRefresh');
    assertAdvertisingRuntime(strpos($handler->invoke($module), 'success:') === 0, 'Admin refresh failed');
}

$module->hookActionFrontControllerSetMedia([]);
$markup = $module->hookDisplayFooter([]);
$visible = $case['visible'] ?? ($case['cp'] === 1);
$assetId = 'module-unipayment-homepage-advertising';
assertAdvertisingRuntime(($markup === 'views/templates/hook/homepage_advertising.tpl') === $visible, 'Footer visibility differs');
assertAdvertisingRuntime(isset($module->context->controller->styles[$assetId]) === $visible, 'CSS eligibility differs');
assertAdvertisingRuntime(isset($module->context->controller->scripts[$assetId]) === $visible, 'JS eligibility differs');
assertAdvertisingRuntime((Configuration::$values[ConfigurationRepository::ADVERTISING_ENABLED] ?? null) === $case['local'], 'Legacy option changed');
if (in_array($case['mode'], ['cache', 'push'], true)) {
    assertAdvertisingRuntime($GLOBALS['unipaymentCpCurlRequests'] === [], 'Cache/push made an outbound request');
}
if (in_array($case['mode'], ['pull', 'refresh'], true)) {
    assertAdvertisingRuntime(count($GLOBALS['unipaymentCpCurlRequests']) === 2, 'Expected one login and one shop fetch');
}
if ($visible) {
    $view = $module->context->smarty->values['unipayment_advertising'];
    assertAdvertisingRuntime($view['is_mobile'] === $case['mobile'], 'Mobile selection changed');
    assertAdvertisingRuntime($view['backurl'] === $snapshot['uni_backurl'], 'CP link changed');
    assertAdvertisingRuntime($view['picture_url'] === $snapshot['uni_picturem'], 'CP picture changed');
}
