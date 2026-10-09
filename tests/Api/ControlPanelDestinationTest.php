<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/ControlPanelDnsFixture.php';
require dirname(__DIR__) . '/Support/ControlPanelTransportFixture.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelDestination;
use PrestaShop\Module\Unipayment\Api\ControlPanelDestinationPolicy;
use PrestaShop\Module\Unipayment\Api\ControlPanelDnsResolver;
use PrestaShop\Module\Unipayment\Api\CurlHttpTransport;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;

function assertDestination(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
function rejectDestination(callable $operation): void
{
    try { $operation(); } catch (InvalidPayloadException | ConnectionException $exception) { return; }
    throw new RuntimeException('Unsafe destination accepted.');
}

$valid = ['https://uni.avalonbg.com', 'https://cptest.ucfinonline.bg', 'https://cp.ucfinonline.bg',
    'https://future-cp.example.org'];
$invalid = ['', 'not-a-url', 'http://cp.example.org', 'https:///missing', 'https://user@cp.example.org',
    'https://user:pass@cp.example.org', 'https://cp.example.org?', 'https://cp.example.org?x=1',
    'https://cp.example.org#', 'https://127.0.0.1', 'https://[::1]', 'https://10.0.0.1',
    'https://localhost', 'https://cp.localhost', 'https://cp.local', 'https://cp.invalid',
    'https://cp.example.org:80', 'https://cp.example.org:8443', 'https://cp.example.org/api/v1',
    'https://cp.example.org/other', 'https://-cp.example.org', 'https://cp..example.org',
    'https://cp.example.org.', 'https://2130706433', 'https://0x7f000001', 'https://127.1',
    "https://cp.example.org\n", ' https://cp.example.org', 'https://cp.example.org\\evil',
    'https://cp%2eexample.org', 'https://' . str_repeat('a', 64) . '.org'];
foreach (array_merge($valid, $invalid, [ControlPanelDestination::CONTROL_PANEL_URL]) as $index => $root) {
    $mode = $index === count($valid) + count($invalid) ? 'dns-invalid'
        : (in_array($root, $invalid, true) ? 'invalid' : 'valid');
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/ControlPanelDestinationProbe.php', $root, $mode],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    assertDestination(is_resource($process), 'Probe could not start.');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    assertDestination(proc_close($process) === 0, 'Canonical-root probe failed: ' . $root . "\n" . $output);
}
assertDestination(ControlPanelDestinationPolicy::validateRoot('HTTPS://CP.Example.Org:443/') === 'https://cp.example.org', 'Root normalization');

foreach (['0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1',
    '192.0.0.1', '192.0.2.1', '192.88.99.1', '192.168.0.1', '198.18.0.1', '198.51.100.1',
    '203.0.113.1', '224.0.0.1', '240.0.0.1', '255.255.255.255', '::', '::1', 'fc00::1',
    'fe80::1', 'ff02::1', '::ffff:127.0.0.1', '64:ff9b::a00:1', '2001:db8::1', '2001::1',
    '2002:a00:1::', '3fff::1', 'bad-address'] as $address) {
    rejectDestination(static function () use ($address): void { ControlPanelDestinationPolicy::validateAddress($address); });
}
foreach (['1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $address) {
    ControlPanelDestinationPolicy::validateAddress($address);
}

$host = ControlPanelDestination::hostname();
$GLOBALS['unipaymentCpCurlInit'] = 0;
$GLOBALS['unipaymentCpDnsRecords'][$host] = [['type' => 'A', 'ip' => '93.184.216.34'], ['type' => 'AAAA', 'ipv6' => '::1']];
rejectDestination(static function () use ($host): void { ControlPanelDnsResolver::resolve($host); });
assertDestination($GLOBALS['unipaymentCpCurlInit'] === 0, 'Mixed public/private DNS reached cURL.');
$GLOBALS['unipaymentCpDnsRecords'][$host] = [];
rejectDestination(static function () use ($host): void { ControlPanelDnsResolver::resolve($host); });
$GLOBALS['unipaymentCpDnsRecords'][$host] = false;
rejectDestination(static function () use ($host): void { ControlPanelDnsResolver::resolve($host); });
$GLOBALS['unipaymentCpDnsRecords'][$host] = [['type' => 'CNAME', 'target' => 'alias.example.org.']];
$GLOBALS['unipaymentCpDnsRecords']['alias.example.org'] = [['type' => 'A', 'ip' => '10.0.0.1']];
rejectDestination(static function () use ($host): void { ControlPanelDnsResolver::resolve($host); });
$GLOBALS['unipaymentCpDnsRecords']['alias.example.org'] = [['type' => 'A', 'ip' => '1.1.1.1']];
assertDestination(ControlPanelDnsResolver::resolve($host) === ['1.1.1.1'], 'Public alias resolution');

// Rebinding after validation cannot replace the pinned address at connection time.
$GLOBALS['unipaymentCpDnsRecords'][$host] = [['type' => 'A', 'ip' => '93.184.216.34']];
$addresses = ControlPanelDnsResolver::resolve($host);
$GLOBALS['unipaymentCpDnsRecords'][$host] = [['type' => 'A', 'ip' => '127.0.0.1']];
$GLOBALS['unipaymentCpCurlRequests'] = [];
$GLOBALS['unipaymentCpResponse'] = static function (string $url): string { return '{}'; };
$transport = new CurlHttpTransport();
$transport->request('GET', ControlPanelDestination::apiBase() . '/shop', [], null, $addresses);
assertDestination($GLOBALS['unipaymentCpCurlRequests'][0][CURLOPT_RESOLVE] === [$host . ':443:93.184.216.34'], 'Rebinding defeated pinning.');
$transport->request('GET', ControlPanelDestination::apiBase() . '/shop', [], null, ['1.1.1.1', '2606:4700:4700::1111']);
if (curl_version()['version_number'] >= 0x073b00) {
    assertDestination($GLOBALS['unipaymentCpCurlRequests'][1][CURLOPT_RESOLVE]
        === [$host . ':443:1.1.1.1,[2606:4700:4700::1111]'], 'Validated multi-address failover lost.');
}
foreach (['https://other-cp.example.org/api/v1/shop', ControlPanelDestination::apiBase() . '/shop?x=1'] as $url) {
    $before = $GLOBALS['unipaymentCpCurlInit'];
    rejectDestination(static function () use ($transport, $url): void { $transport->request('GET', $url, [], null, ['1.1.1.1']); });
    assertDestination($before === $GLOBALS['unipaymentCpCurlInit'], 'Wrong request origin reached cURL.');
}
$GLOBALS['unipaymentCpCurlOptionsOk'] = false;
$before = count($GLOBALS['unipaymentCpCurlRequests']);
rejectDestination(static function () use ($transport): void { $transport->request('GET', ControlPanelDestination::apiBase() . '/shop', [], null, ['1.1.1.1']); });
assertDestination(count($GLOBALS['unipaymentCpCurlRequests']) === $before, 'Failed security options still dispatched.');

$constructor = new ReflectionMethod(PrestaShop\Module\Unipayment\Api\ControlPanelClient::class, '__construct');
assertDestination(array_map(static function ($p): string { return $p->getName(); }, $constructor->getParameters())
    === ['configuration', 'tokens', 'transport', 'shopName', 'clock'], 'Independent destination injection exists.');

// Guard the production wiring, including contexts whose UI needs a full shop.
$moduleRoot = dirname(__DIR__, 2);
$module = file_get_contents($moduleRoot . '/unipayment.php');
foreach (['hookDisplayProductAdditionalInfo', 'hookDisplayShoppingCart', 'hookPaymentOptions'] as $method) {
    preg_match('/function ' . $method . '\\b(.*?)(?=\\n    (?:public|private|protected) function|\\z)/s', $module, $body);
    assertDestination(strpos($body[1] ?? '', 'createShopConfigurationService()->get()') !== false,
        'Native context bypasses the canonical service: ' . $method);
}
foreach (['productcalculator', 'cartcalculator', 'checkoutcalculate', 'productpopup', 'cartpopup', 'validatecheckout'] as $controller) {
    $source = file_get_contents($moduleRoot . '/controllers/front/' . $controller . '.php');
    assertDestination(strpos($source, 'getShopConfigurationService()') !== false, 'Context bypasses configuration resolver: ' . $controller);
    if (in_array($controller, ['productpopup', 'cartpopup', 'validatecheckout'], true)) {
        assertDestination(strpos($source, 'getControlPanelClient()') !== false, 'Order context bypasses CP factory: ' . $controller);
    }
}
$hostOccurrences = [];
$clientFactories = [];
foreach (['src', 'controllers'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $source = file_get_contents($file->getPathname());
        if (preg_match('/uni\.avalonbg\.com|cptest\.ucfinonline\.bg|cp\.ucfinonline\.bg/', $source)) {
            $hostOccurrences[] = $file->getFilename();
        }
        if (preg_match('/new\s+ControlPanelClient\(/', $source)) { $clientFactories[] = $file->getFilename(); }
    }
}
assertDestination($hostOccurrences === ['ControlPanelDestination.php'], 'A second static CP hostname dependency exists.');
assertDestination($clientFactories === ['shopcache.php'], 'An unexpected independent client factory exists.');
$policy = file_get_contents($moduleRoot . '/src/Api/ControlPanelDestinationPolicy.php');
assertDestination(!preg_match('/TRUSTED_HOSTS|ALLOWED_HOSTS|ucfinonline|avalonbg/', $policy), 'CP hostname allowlist exists.');

echo "OK (CP canonical destination, context/persistence probes, DNS policy and pinning)\n";
