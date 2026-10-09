<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

$moduleRoot = dirname(__DIR__, 2);
$configured = $argv[1];
// Change exactly the one deployment declaration in memory in an isolated process.
// No production constructor, setter, environment variable or config override exists.
$source = file_get_contents($moduleRoot . '/src/Api/ControlPanelDestination.php');
$source = preg_replace('/public const CONTROL_PANEL_URL = [^;]+;/',
    'public const CONTROL_PANEL_URL = ' . var_export($configured, true) . ';', $source, 1, $count);
if ($count !== 1) { throw new RuntimeException('Canonical declaration missing.'); }
eval(substr($source, 5));
require $moduleRoot . '/vendor/autoload.php';
require __DIR__ . '/ControlPanelDnsFixture.php';
require __DIR__ . '/ControlPanelTransportFixture.php';
require __DIR__ . '/ControlPanelRuntimeFixture.php';
require $moduleRoot . '/tests/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\ControlPanelDestination;
use PrestaShop\Module\Unipayment\Api\CurlHttpTransport;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

function checkCp(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
$GLOBALS['unipaymentCpCurlInit'] = 0;
$GLOBALS['unipaymentCpCurlRequests'] = [];
$configuration = new ConfigurationRepository();
$configuration->save(true, '123e4567-e89b-12d3-a456-426614174000', 'synthetic-test-secret');
$tokens = new TokenRepository();

if (($argv[2] ?? '') === 'invalid' || ($argv[2] ?? '') === 'dns-invalid') {
    $transport = new class implements PrestaShop\Module\Unipayment\Api\HttpTransportInterface {
        public int $calls = 0;
        public function request(string $method, string $url, array $headers, ?array $payload, array $resolvedAddresses): PrestaShop\Module\Unipayment\Api\HttpResponse
        {
            ++$this->calls;
            throw new RuntimeException('Rejected destination reached transport.');
        }
    };
    if (($argv[2] ?? '') === 'dns-invalid') {
        $GLOBALS['unipaymentCpDnsRecords'][ControlPanelDestination::hostname()] = [
            ['type' => 'A', 'ip' => '93.184.216.34'], ['type' => 'A', 'ip' => '10.0.0.1'],
        ];
    }
    try {
        $api = new ControlPanelClient($configuration, $tokens, $transport, 'https://merchant.example.org');
        $api->login();
        throw new RuntimeException('Invalid canonical root accepted.');
    } catch (InvalidPayloadException | ConnectionException $exception) {
        checkCp($transport->calls === 0 && $GLOBALS['unipaymentCpCurlInit'] === 0, 'Invalid destination reached transport.');
    }
    echo "OK (invalid root blocked before transport)\n";
    exit;
}

$origin = ControlPanelDestination::root();
$base = $origin . '/api/v1';
checkCp(ControlPanelDestination::apiBase() === $base, 'API base derivation');
$GLOBALS['unipaymentCpResponse'] = static function (string $url) use ($base, $moduleRoot): string {
    $path = substr($url, strlen($base));
    $data = [];
    if ($path === '/auth/login' || $path === '/auth/refresh') {
        $data = ['access_token' => 'new-test-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            'shop' => ['unicid' => '123e4567-e89b-12d3-a456-426614174000']];
    } elseif ($path === '/shop') {
        $data = unipayment_valid_shop_snapshot();
    } elseif ($path === '/orders') {
        $data = ['id' => 7, 'order_id' => 'ORDERREF', 'unicid' => '123e4567-e89b-12d3-a456-426614174000'];
    } elseif ($path === '/orders/status') {
        $data = ['order_id' => 'ORDERREF', 'status_id' => 'bank_sent_process2', 'status' => 'sent'];
    } elseif (strpos($path, '/ssl/certificate') === 0) {
        $cert = file_get_contents($moduleRoot . '/tests/fixtures/ssl/v1_cert.pem');
        $key = file_get_contents($moduleRoot . '/tests/fixtures/ssl/v1_key.pem');
        $data = ['available' => true, 'ssl_revision' => 'revision', 'certificate_sha256' => hash('sha256', $cert),
            'private_key_sha256' => hash('sha256', $key), 'certificate_pem' => $cert, 'private_key_pem' => $key];
    }
    return json_encode(['success' => true, 'error' => null, 'data' => (object) $data], JSON_THROW_ON_ERROR);
};

require $moduleRoot . '/unipayment.php';
require $moduleRoot . '/controllers/front/shopcache.php';
$module = new Unipayment();
$api = $module->getControlPanelClient();

// Legacy or another-CP token must trigger login; never send its Bearer value.
foreach ([null, 'https://previous-cp.example.org'] as $oldOrigin) {
    Configuration::$values[TokenRepository::ACCESS_TOKEN] = 'enc:v1:' . base64_encode('old-test-token');
    Configuration::$values[TokenRepository::EXPIRES_AT] = time() + 3600;
    unset(Configuration::$values[TokenRepository::ORIGIN]);
    if ($oldOrigin !== null) { Configuration::$values[TokenRepository::ORIGIN] = $oldOrigin; }
    checkCp($tokens->getAccessToken() === null, 'Cross-origin or legacy token reused.');
    $before = count($GLOBALS['unipaymentCpCurlRequests']);
    $api->getShop();
    $request = $GLOBALS['unipaymentCpCurlRequests'][$before];
    checkCp($request[CURLOPT_URL] === $base . '/auth/login', 'Switch did not re-authenticate.');
    checkCp(!in_array('Authorization: Bearer old-test-token', $request[CURLOPT_HTTPHEADER], true), 'Old token sent.');
}
checkCp(Configuration::$values[TokenRepository::ORIGIN] === $origin, 'New token origin binding');

// Actual persistent cache serialization/read paths; no database connection.
$db = Db::getInstance();
$cache = new ShopConfigurationCache($db);
$snapshot = unipayment_valid_shop_snapshot();
$unicid = $snapshot['unicid'];
foreach ([$snapshot, ['cp_origin' => 'https://previous-cp.example.org', 'snapshot' => $snapshot]] as $stored) {
    $db->row = ['shop_data' => json_encode($stored), 'fetched_at' => '2026-01-01 00:00:00',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)];
    checkCp($cache->getFresh($unicid) === null, 'Cross-origin/legacy fresh cache reused.');
    checkCp($cache->getRetained($unicid) === null, 'Cross-origin/legacy LKG reused.');
    checkCp($cache->getMetadata($unicid) === null, 'Cross-origin metadata reused.');
    checkCp($db->row !== null, 'Origin mismatch destructively removed cache.');
}
$cache->replace($unicid, $snapshot);
checkCp($cache->getFresh($unicid) === $snapshot, 'Current origin cache not returned intact.');

// Representative real module factories and native configuration service entry points.
$admin = new ReflectionMethod(Unipayment::class, 'handleBankDataRefresh');
checkCp(strpos($admin->invoke($module), 'success:') === 0, 'Admin refresh failed.');
foreach (['product', 'cart', 'checkout'] as $context) {
    $db->row = null;
    $service = $module->getShopConfigurationService();
    $shop = $context === 'checkout' ? $service->getForSubmission() : $service->get();
    checkCp($shop['unicid'] === $unicid, $context . ' refresh failed.');
}
$callback = new UnipaymentShopcacheModuleFrontController();
$callbackFactory = new ReflectionMethod($callback, 'createShopConfigurationService');
$db->row = null;
checkCp($callbackFactory->invoke($callback)->get()['unicid'] === $unicid, 'Callback factory diverged.');
$push = new ReflectionMethod($callback, 'handleAuthenticatedRequest');
$before = count($GLOBALS['unipaymentCpCurlRequests']);
$push->invoke($callback, ['data' => $snapshot], $unicid);
checkCp(count($GLOBALS['unipaymentCpCurlRequests']) === $before, 'Push unexpectedly fetched CP.');
$module->getShopConfigurationService()->get();
checkCp(count($GLOBALS['unipaymentCpCurlRequests']) === $before, 'Same-origin cache hit fetched CP.');

$adapter = new ControlPanelOrderClientAdapter($api);
$adapter->createOrder(['order_id' => 'ORDERREF']);
$syncStore = new class implements PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStoreInterface {
    public array $row = ['cp_status_sync_state' => 'pending', 'cp_status_sync_status_id' => 'bank_sent_process2',
        'cp_status_sync_status' => 'sent'];
    public function findByAttempt(int $attemptId): ?array { return $this->row; }
    public function compareAndSetPendingTarget(int $attemptId, string $expectedState, ?string $expectedStatusId,
        ?string $expectedStatus, string $newStatusId, string $newStatus): bool
    {
        throw new RuntimeException('Replay must use the already persisted pending target.');
    }
    public function compareAndSetConfirmed(int $attemptId, string $expectedStatusId, string $expectedStatus): bool
    {
        checkCp($this->row['cp_status_sync_status_id'] === $expectedStatusId
            && $this->row['cp_status_sync_status'] === $expectedStatus, 'PATCH confirmed another target.');
        $this->row['cp_status_sync_state'] = 'confirmed';
        return true;
    }
    public function compareAndSetFailure(int $attemptId, string $expectedStatusId, string $expectedStatus,
        string $newState, string $errorClass): bool { throw new RuntimeException('Status sync failed.'); }
};
$sync = new PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncService($syncStore, $adapter);
checkCp($sync->retryPending(1, 'ORDERREF') === 'confirmed', 'Pending status replay did not confirm.');
$before = count($GLOBALS['unipaymentCpCurlRequests']);
$sync->retryPending(1, 'ORDERREF');
checkCp(count($GLOBALS['unipaymentCpCurlRequests']) === $before, 'Confirmed status replay sent again.');
$api->getSslCertificateMetadata();
$api->downloadSslCertificateBundle();
$keysDir = sys_get_temp_dir() . '/unipayment-cp-origin-cert-' . bin2hex(random_bytes(8));
try {
    $store = new PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore($keysDir);
    $certificateSync = new PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSynchronizer($api, $store);
    $lease = $certificateSync->ensureCurrent();
    checkCp(is_file($lease->certificatePath()), 'Actual certificate synchronization did not produce a lease.');
    $lease->release();
} finally {
    if (is_dir($keysDir)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($keysDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($keysDir);
    }
}
$api->refreshToken();
$api->logout();
$paths = [];
foreach ($GLOBALS['unipaymentCpCurlRequests'] as $request) {
    $url = $request[CURLOPT_URL];
    checkCp(strpos($url, $base . '/') === 0, 'Runtime origin divergence.');
    $paths[] = substr($url, strlen($base));
    checkCp($request[CURLOPT_RESOLVE] === [ControlPanelDestination::hostname() . ':443:93.184.216.34'], 'DNS not pinned.');
    checkCp($request[CURLOPT_SSL_VERIFYPEER] && $request[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS weakened.');
    checkCp($request[CURLOPT_FOLLOWLOCATION] === false && $request[CURLOPT_PROXY] === '', 'Redirect/proxy bypass.');
    checkCp($request[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Protocol policy weakened.');
}
foreach (['/auth/login', '/auth/refresh', '/auth/logout', '/shop', '/orders', '/orders/status',
    '/ssl/certificate', '/ssl/certificate/bundle'] as $path) {
    checkCp(in_array($path, $paths, true), 'Missing endpoint exercise: ' . $path);
}
echo "OK (single deployment root, all endpoints, contexts and origin-bound persistence)\n";
