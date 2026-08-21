<?php

declare(strict_types=1);

/**
 * Certificate rotation synchronizer regression tests (PS Phase 2).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\HttpResponse;
use PrestaShop\Module\Unipayment\Api\HttpTransportInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificatePairValidator;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSyncException;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSynchronizer;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionGatewayInterface;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateConsumerLease;

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static $messages = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$messages[] = $message;
        }
    }
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        /** @var array<string, mixed> */
        public static $values = [];

        public static function updateValue(string $key, mixed $value): bool
        {
            self::$values[$key] = $value;

            return true;
        }

        /** @return mixed */
        public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return self::$values[$key] ?? $default;
        }

        public static function deleteByName(string $key): bool
        {
            unset(self::$values[$key]);

            return true;
        }
    }
}

if (!class_exists('PhpEncryption', false)) {
    class PhpEncryption
    {
        public function __construct(string $key) {}

        public function encrypt(string $plaintext): string
        {
            return base64_encode($plaintext);
        }

        public function decrypt(string $ciphertext)
        {
            $decoded = base64_decode($ciphertext, true);

            return is_string($decoded) ? $decoded : false;
        }
    }
}

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'test-key');
}

function assertCert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixtures = $root . '/tests/fixtures/ssl';
$v1Cert = (string) file_get_contents($fixtures . '/v1_cert.pem');
$v1Key = (string) file_get_contents($fixtures . '/v1_key.pem');
$v2Cert = (string) file_get_contents($fixtures . '/v2_cert.pem');
$v2Key = (string) file_get_contents($fixtures . '/v2_key.pem');
$expiredCert = (string) file_get_contents($fixtures . '/expired_cert.pem');
$expiredKey = (string) file_get_contents($fixtures . '/expired_key.pem');

$validator = new CertificatePairValidator();
assertCert($validator->isValidPair($v1Cert, $v1Key), 'v1 pair validates');
assertCert(!$validator->isValidPair($v1Cert, $v2Key), 'mismatched pair rejected');
assertCert(!$validator->isValidPair($expiredCert, $expiredKey), 'expired pair rejected');
assertCert(!$validator->isValidPair('not-a-cert', $v1Key), 'malformed cert rejected');
assertCert(!$validator->isValidPair($v1Cert, 'not-a-key'), 'malformed key rejected');

$v1CertHash = $validator->sha256($v1Cert);
$v1KeyHash = $validator->sha256($v1Key);
$v2CertHash = $validator->sha256($v2Cert);
$v2KeyHash = $validator->sha256($v2Key);

final class CertSyncFakeTransport implements HttpTransportInterface
{
    /** @var list<array{method:string,url:string}> */
    public $requests = [];
    /** @var callable|null */
    public $handler;

    public function request(string $method, string $url, array $headers, ?array $payload): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url];
        if ($this->handler !== null) {
            return ($this->handler)($method, $url, $headers, $payload);
        }

        return new HttpResponse(500, '{}');
    }
}

/**
 * @return array{0: ControlPanelClient, 1: CertSyncFakeTransport, 2: TokenRepository}
 */
function makeCpClient(CertSyncFakeTransport $transport): array
{
    if (!defined('_NEW_COOKIE_KEY_')) {
        define('_NEW_COOKIE_KEY_', 'test-key');
    }
    Configuration::$values = [
        'UNIPAYMENT_UNICID' => 'test-unicid',
        'UNIPAYMENT_SECRET' => 'test-secret-key-12345678901234567890',
    ];
    $tokens = new TokenRepository();
    $tokens->save('tok', 'Bearer', time() + 3600);
    $client = new ControlPanelClient(
        new ConfigurationRepository(),
        $tokens,
        $transport,
        'https://shop.example'
    );

    return [$client, $transport, $tokens];
}

function metaPayload(string $certHash, string $keyHash, string $rev = 'rev-1'): string
{
    return json_encode([
        'success' => true,
        'message' => 'ok',
        'data' => [
            'available' => true,
            'ssl_revision' => $rev,
            'certificate_sha256' => $certHash,
            'private_key_sha256' => $keyHash,
            'not_before' => '2026-01-01T00:00:00+00:00',
            'not_after' => '2027-01-01T00:00:00+00:00',
        ],
    ], JSON_THROW_ON_ERROR);
}

function bundlePayload(string $cert, string $key, string $certHash, string $keyHash, string $rev = 'rev-1'): string
{
    return json_encode([
        'success' => true,
        'message' => 'ok',
        'data' => [
            'ssl_revision' => $rev,
            'certificate_sha256' => $certHash,
            'private_key_sha256' => $keyHash,
            'not_before' => '2026-01-01T00:00:00+00:00',
            'not_after' => '2027-01-01T00:00:00+00:00',
            'certificate_pem' => $cert,
            'private_key_pem' => $key,
        ],
    ], JSON_THROW_ON_ERROR);
}

function prepareKeysDir(string $dir, ?string $cert = null, ?string $key = null): CertificateLocalStore
{
    if (is_dir($dir)) {
        foreach (glob($dir . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach (['.incoming'] as $sub) {
            $path = $dir . '/' . $sub;
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($path);
            }
        }
    } else {
        mkdir($dir, 0750, true);
    }
    $store = new CertificateLocalStore($dir);
    $store->ensureProtectionFiles();
    if ($cert !== null && $key !== null) {
        file_put_contents($store->certificatePath(), $cert);
        file_put_contents($store->privateKeyPath(), $key);
        @chmod($store->privateKeyPath(), 0600);
        @chmod($store->certificatePath(), 0640);
    }

    return $store;
}

$work = sys_get_temp_dir() . '/unipayment-cert-sync-' . getmypid();
@mkdir($work, 0700, true);

// --- 1 metadata match → no bundle ---
$store = prepareKeysDir($work . '/t1', $v1Cert, $v1Key);
$transport = new CertSyncFakeTransport();
$bundleCalls = 0;
$transport->handler = function ($method, $url) use ($v1CertHash, $v1KeyHash, &$bundleCalls) {
    if (strpos($url, '/ssl/certificate/bundle') !== false) {
        ++$bundleCalls;

        return new HttpResponse(500, '{}');
    }
    if (strpos($url, '/ssl/certificate') !== false) {
        return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
    }

    return new HttpResponse(500, '{}');
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$lease = $sync->ensureCurrent();
assertCert($bundleCalls === 0, '1: bundle not called on match');
assertCert(is_readable($lease->certificatePath()), '1: lease cert readable');
$lease->release();

// --- 2 local cert one-byte change → refresh ---
$store = prepareKeysDir($work . '/t2', $v1Cert, $v1Key);
$corrupt = $v1Cert;
$corrupt[50] = $corrupt[50] === 'A' ? 'B' : 'A';
file_put_contents($store->certificatePath(), $corrupt);
$transport = new CertSyncFakeTransport();
$bundleCalls = 0;
$transport->handler = function ($method, $url) use ($v1Cert, $v1Key, $v1CertHash, $v1KeyHash, &$bundleCalls) {
    if (strpos($url, '/ssl/certificate/bundle') !== false) {
        ++$bundleCalls;

        return new HttpResponse(200, bundlePayload($v1Cert, $v1Key, $v1CertHash, $v1KeyHash));
    }
    if (strpos($url, '/ssl/certificate') !== false) {
        return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
    }

    return new HttpResponse(500, '{}');
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$lease = $sync->ensureCurrent();
assertCert($bundleCalls === 1, '2: bundle called after cert corruption');
assertCert(hash('sha256', (string) file_get_contents($store->certificatePath())) === $v1CertHash, '2: cert restored');
$lease->release();

// --- 3 local key one-byte change ---
$store = prepareKeysDir($work . '/t3', $v1Cert, $v1Key);
$corruptKey = $v1Key;
$corruptKey[40] = $corruptKey[40] === 'A' ? 'B' : 'A';
file_put_contents($store->privateKeyPath(), $corruptKey);
$transport = new CertSyncFakeTransport();
$bundleCalls = 0;
$transport->handler = function ($method, $url) use ($v1Cert, $v1Key, $v1CertHash, $v1KeyHash, &$bundleCalls) {
    if (strpos($url, '/bundle') !== false) {
        ++$bundleCalls;

        return new HttpResponse(200, bundlePayload($v1Cert, $v1Key, $v1CertHash, $v1KeyHash));
    }

    return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, new CertificateLocalStore($work . '/t3'));
$lease = $sync->ensureCurrent();
assertCert($bundleCalls === 1, '3: key corruption triggers bundle');
assertCert(hash('sha256', (string) file_get_contents($work . '/t3/' . CertificateLocalStore::KEY_FILENAME)) === $v1KeyHash, '3: key restored');
$lease->release();

// --- 4 missing local files ---
$store = prepareKeysDir($work . '/t4');
$transport = new CertSyncFakeTransport();
$transport->handler = function ($method, $url) use ($v1Cert, $v1Key, $v1CertHash, $v1KeyHash) {
    if (strpos($url, '/bundle') !== false) {
        return new HttpResponse(200, bundlePayload($v1Cert, $v1Key, $v1CertHash, $v1KeyHash));
    }

    return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$lease = $sync->ensureCurrent();
assertCert(is_file($store->certificatePath()) && is_file($store->privateKeyPath()), '4: files installed');
$lease->release();

// --- 7 CP metadata unavailable + valid local → fail-open ---
$store = prepareKeysDir($work . '/t7', $v1Cert, $v1Key);
$transport = new CertSyncFakeTransport();
$transport->handler = function () {
    throw new ConnectionException('down');
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$lease = $sync->ensureCurrent();
assertCert(is_readable($lease->privateKeyPath()), '7: fail-open lease');
$lease->release();

// --- 8 CP unavailable + missing local → PRE-SEND ---
$store = prepareKeysDir($work . '/t8');
$transport = new CertSyncFakeTransport();
$transport->handler = function () {
    throw new ConnectionException('down');
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$threw = false;
try {
    $sync->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = $e->reason() === CertificateSyncException::REASON_CP_TRANSPORT;
}
assertCert($threw, '8: missing local + CP down → sync exception');

// --- 9 expired local + CP down ---
$store = prepareKeysDir($work . '/t9', $expiredCert, $expiredKey);
$transport = new CertSyncFakeTransport();
$transport->handler = function () {
    throw new ConnectionException('down');
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$threw = false;
try {
    $sync->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = true;
}
assertCert($threw, '9: expired local + CP down fails');

// --- 10 CP explicit unavailable ---
$store = prepareKeysDir($work . '/t10', $v1Cert, $v1Key);
$transport = new CertSyncFakeTransport();
$transport->handler = function () {
    return new HttpResponse(404, json_encode([
        'error' => 'ssl_certificate_unavailable',
        'message' => 'none',
        'data' => ['available' => false],
    ], JSON_THROW_ON_ERROR));
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$threw = false;
try {
    $sync->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = $e->reason() === CertificateSyncException::REASON_CP_UNAVAILABLE;
}
assertCert($threw, '10: explicit CP unavailable is fail-closed');

// --- 11 metadata mismatch + bundle network failure → old preserved ---
$store = prepareKeysDir($work . '/t11', $v1Cert, $v1Key);
file_put_contents($store->certificatePath(), $corrupt = (static function ($c) {
    $c[60] = $c[60] === 'A' ? 'B' : 'A';

    return $c;
})($v1Cert));
$before = (string) file_get_contents($store->certificatePath());
$transport = new CertSyncFakeTransport();
$transport->handler = function ($method, $url) use ($v1CertHash, $v1KeyHash) {
    if (strpos($url, '/bundle') !== false) {
        throw new ConnectionException('bundle down');
    }

    return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$threw = false;
try {
    $sync->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = true;
}
assertCert($threw, '11: bundle failure throws');
assertCert((string) file_get_contents($store->certificatePath()) === $before, '11: corrupt local preserved (no partial write)');

// --- 12/13 hash mismatch in bundle ---
$store = prepareKeysDir($work . '/t12');
$transport = new CertSyncFakeTransport();
$transport->handler = function ($method, $url) use ($v1Cert, $v1Key, $v1CertHash, $v1KeyHash) {
    if (strpos($url, '/bundle') !== false) {
        return new HttpResponse(200, bundlePayload($v1Cert, $v1Key, str_repeat('a', 64), $v1KeyHash));
    }

    return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
};
[$cp] = makeCpClient($transport);
$sync = new CertificateSynchronizer($cp, $store);
$threw = false;
try {
    $sync->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = $e->reason() === CertificateSyncException::REASON_INVALID_BUNDLE;
}
assertCert($threw, '12: declared cert hash mismatch rejected');

// --- 14/15/16 malformed / mismatch downloads ---
$store = prepareKeysDir($work . '/t14');
$transport = new CertSyncFakeTransport();
$badCert = "-----BEGIN CERTIFICATE-----\nQQ==\n-----END CERTIFICATE-----\n";
$badHash = hash('sha256', $badCert);
$transport->handler = function ($method, $url) use ($badCert, $v1Key, $badHash, $v1KeyHash, $v1CertHash) {
    if (strpos($url, '/bundle') !== false) {
        return new HttpResponse(200, bundlePayload($badCert, $v1Key, $badHash, $v1KeyHash));
    }

    return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
};
[$cp] = makeCpClient($transport);
$threw = false;
try {
    (new CertificateSynchronizer($cp, $store))->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = true;
}
assertCert($threw, '14: malformed downloaded cert rejected');

$store = prepareKeysDir($work . '/t16');
$transport = new CertSyncFakeTransport();
$mismatchHashCert = hash('sha256', $v1Cert);
$mismatchHashKey = hash('sha256', $v2Key);
$transport->handler = function ($method, $url) use ($v1Cert, $v2Key, $mismatchHashCert, $mismatchHashKey, $v1CertHash, $v1KeyHash) {
    if (strpos($url, '/bundle') !== false) {
        return new HttpResponse(200, bundlePayload($v1Cert, $v2Key, $mismatchHashCert, $mismatchHashKey, 'rev-x'));
    }

    return new HttpResponse(200, metaPayload($mismatchHashCert, $mismatchHashKey, 'rev-x'));
};
[$cp] = makeCpClient($transport);
$threw = false;
try {
    (new CertificateSynchronizer($cp, $store))->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = true;
}
assertCert($threw, '16: cert/key mismatch bundle rejected');

// --- 17 expired download ---
$store = prepareKeysDir($work . '/t17');
$expCertHash = hash('sha256', $expiredCert);
$expKeyHash = hash('sha256', $expiredKey);
$transport = new CertSyncFakeTransport();
$transport->handler = function ($method, $url) use ($expiredCert, $expiredKey, $expCertHash, $expKeyHash) {
    if (strpos($url, '/bundle') !== false) {
        return new HttpResponse(200, bundlePayload($expiredCert, $expiredKey, $expCertHash, $expKeyHash, 'rev-e'));
    }

    return new HttpResponse(200, metaPayload($expCertHash, $expKeyHash, 'rev-e'));
};
[$cp] = makeCpClient($transport);
$threw = false;
try {
    (new CertificateSynchronizer($cp, $store))->ensureCurrent();
} catch (CertificateSyncException $e) {
    $threw = true;
}
assertCert($threw, '17: expired downloaded cert rejected');

// --- 22 lease immutability ---
$store = prepareKeysDir($work . '/t22', $v1Cert, $v1Key);
$lease = $store->createConsumerPairLease();
$leaseCertBefore = (string) file_get_contents($lease->certificatePath());
file_put_contents($store->certificatePath(), $v2Cert);
file_put_contents($store->privateKeyPath(), $v2Key);
$leaseCertAfter = (string) file_get_contents($lease->certificatePath());
assertCert($leaseCertBefore === $leaseCertAfter && $leaseCertBefore === $v1Cert, '22: lease stays V1 after authoritative rotates');
$lease->release();

// --- 23 permissions after replace ---
$store = prepareKeysDir($work . '/t23');
$transport = new CertSyncFakeTransport();
$transport->handler = function ($method, $url) use ($v1Cert, $v1Key, $v1CertHash, $v1KeyHash) {
    if (strpos($url, '/bundle') !== false) {
        return new HttpResponse(200, bundlePayload($v1Cert, $v1Key, $v1CertHash, $v1KeyHash));
    }

    return new HttpResponse(200, metaPayload($v1CertHash, $v1KeyHash));
};
[$cp] = makeCpClient($transport);
$lease = (new CertificateSynchronizer($cp, $store))->ensureCurrent();
$mode = substr(sprintf('%o', fileperms($store->privateKeyPath())), -3);
assertCert($mode === '600', '23: private key mode 0600, got ' . $mode);
$lease->release();

// --- 24 HTTP protection files ---
assertCert(is_file($store->keysDirectory() . '/.htaccess'), '24: .htaccess present');
assertCert(is_file($store->keysDirectory() . '/index.php'), '24: index.php present');

// --- 25 no PEM in logs from sync messages ---
foreach (PrestaShopLogger::$messages as $msg) {
    assertCert(strpos($msg, 'BEGIN CERTIFICATE') === false, '25: no cert PEM in logs');
    assertCert(strpos($msg, 'BEGIN PRIVATE') === false, '25: no key PEM in logs');
    assertCert(strpos($msg, $v1Key) === false, '25: raw key absent from logs');
}

// --- 26/27/28/29/30 coordinator source contracts ---
$coordSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
$syncPos = strpos($coordSrc, 'ensureCurrent()');
$claimPos = strpos($coordSrc, 'claimForSubmitting');
assertCert($syncPos !== false && $claimPos !== false && $syncPos < $claimPos, '26: sync before claim in source');
assertCert(strpos($coordSrc, 'CLASS_PRE_SEND') !== false, '27: sync failures map to PRE_SEND');
assertCert(
    strpos($coordSrc, 'resultFromState') < strpos($coordSrc, 'ensureCurrent()'),
    '28: replay before sync'
);
assertCert(strpos($coordSrc, 'if ($process2)') !== false, '29: process2 short-circuit exists');
assertCert(strpos($coordSrc, 'usesSmartUcfCertificate') !== false, '30: uni_sertificat gate present');

// .gitignore
$gitignore = (string) file_get_contents($root . '/.gitignore');
assertCert(strpos($gitignore, '/keys/') !== false, 'keys ignored by git');

// Journal sensitive keys
$journal = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfDiagnosticJournal.php');
assertCert(strpos($journal, 'certificate_pem') !== false && strpos($journal, 'private_key_pem') !== false, '25b: journal redacts PEM fields');

fwrite(STDOUT, "OK (CertificateSynchronizer Phase 2)\n");
