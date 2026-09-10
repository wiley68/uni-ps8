<?php

declare(strict_types=1);

/**
 * AUD-012 — signed Control Panel → module request verification (canonical protocol).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
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

final class PhpEncryption
{
    public function __construct(string $key)
    {
    }

    public function encrypt(string $plaintext): string
    {
        return base64_encode(strrev($plaintext));
    }

    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? strrev($decoded) : false;
    }
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace("'", "\\'", $string);
    }
}

final class Aud012FakeDb
{
    /** @var list<array{unicid:string,nonce_hash:string}> */
    public array $rows = [];

    /** @var list<string> */
    public array $queries = [];

    public function execute(string $sql): bool
    {
        $this->queries[] = $sql;

        return true;
    }

    public function insert(string $table, array $data, $nullValues = false, $useCache = true, $type = 1): bool
    {
        unset($table, $nullValues, $useCache, $type);

        foreach ($this->rows as $row) {
            if ($row['unicid'] === $data['unicid'] && $row['nonce_hash'] === $data['nonce_hash']) {
                return false;
            }
        }

        $this->rows[] = [
            'unicid' => (string) $data['unicid'],
            'nonce_hash' => (string) $data['nonce_hash'],
        ];

        return true;
    }

    public function getMsgError(): string
    {
        return 'Duplicate entry';
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\ApiNonceRepository;
use PrestaShop\Module\Unipayment\Security\FixedClock;
use PrestaShop\Module\Unipayment\Security\ModuleRequestAuthenticator;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureVerifier;

function assertAud012(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function signedHeaders(
    string $secret,
    string $rawBody,
    string $timestamp = ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
    string $nonce = ModuleRequestSignatureProtocol::CONTRACT_NONCE
): array {
    return [
        ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => $timestamp,
        ModuleRequestSignatureProtocol::HEADER_NONCE => $nonce,
        ModuleRequestSignatureProtocol::HEADER_SIGNATURE => ModuleRequestSignatureProtocol::computeSignature(
            $secret,
            $timestamp,
            $nonce,
            $rawBody
        ),
    ];
}

function makeAuthenticator(FixedClock $clock, Aud012FakeDb $db): ModuleRequestAuthenticator
{
    $configuration = new ConfigurationRepository();
    $configuration->save(true, 'TEST-UNICID', 'test_shared_secret_123');

    $nonceRepository = new ApiNonceRepository($db);
    $verifier = new ModuleRequestSignatureVerifier($clock);

    return new ModuleRequestAuthenticator($configuration, $verifier, $nonceRepository, $clock);
}

assertAud012(
    ModuleRequestSignatureProtocol::computeSignature(
        ModuleRequestSignatureProtocol::CONTRACT_SECRET,
        ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
        ModuleRequestSignatureProtocol::CONTRACT_NONCE,
        ModuleRequestSignatureProtocol::CONTRACT_RAW_BODY
    ) === ModuleRequestSignatureProtocol::CONTRACT_SIGNATURE,
    'shared contract vector mismatch'
);
assertAud012(
    strpos(ModuleRequestSignatureProtocol::CONTRACT_RAW_BODY, '"operation":"order-bank-status"') !== false,
    'contract vector must include canonical operation in raw body'
);

$clock = new FixedClock((int) ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP);
$db = new Aud012FakeDb();
$authenticator = makeAuthenticator($clock, $db);

$rawBody = ModuleRequestSignatureProtocol::CONTRACT_RAW_BODY;
[$payload, $unicid] = $authenticator->authenticate($rawBody, signedHeaders('test_shared_secret_123', $rawBody));
assertAud012($unicid === 'TEST-UNICID', 'valid signed request rejected');
assertAud012(($payload['operation'] ?? null) === 'order-bank-status', 'operation missing after authenticate');

try {
    $authenticator->authenticate($rawBody, signedHeaders('test_shared_secret_123', $rawBody));
    assertAud012(false, 'exact replay was accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'replay status code mismatch');
    assertAud012(
        $exception->getMessage() === ModuleRequestSignatureProtocol::AUTH_FAILURE_MESSAGE,
        'replay message mismatch'
    );
}

$newNonce = str_repeat('b', 64);
$newHeaders = signedHeaders(
    'test_shared_secret_123',
    $rawBody,
    ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
    $newNonce
);
[$payloadAgain, $unicidAgain] = $authenticator->authenticate($rawBody, $newHeaders);
assertAud012($unicidAgain === 'TEST-UNICID', 'same body with new nonce rejected');
assertAud012(($payloadAgain['operation'] ?? null) === 'order-bank-status', 'replay-safe body lost operation');

$tamperedBody = '{"operation":"order-bank-status","unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"11"}';
$nonceBeforeTamper = count($db->rows);
try {
    $authenticator->authenticate($tamperedBody, signedHeaders('test_shared_secret_123', $rawBody));
    assertAud012(false, 'tampered body accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'tampered body status mismatch');
    assertAud012(count($db->rows) === $nonceBeforeTamper, 'invalid signature must not consume nonce');
}

$wrongSecretHeaders = signedHeaders('wrong-secret', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, str_repeat('c', 64));
$nonceBeforeWrong = count($db->rows);
try {
    $authenticator->authenticate($rawBody, $wrongSecretHeaders);
    assertAud012(false, 'wrong signature accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'wrong signature status mismatch');
    assertAud012(count($db->rows) === $nonceBeforeWrong, 'wrong signature must not consume nonce');
}

$staleClock = new FixedClock((int) ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP + 400);
$staleAuthenticator = makeAuthenticator($staleClock, new Aud012FakeDb());
try {
    $staleAuthenticator->authenticate(
        $rawBody,
        signedHeaders('test_shared_secret_123', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, str_repeat('d', 64))
    );
    assertAud012(false, 'expired timestamp accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'expired timestamp status mismatch');
}

$freshWithin = new FixedClock((int) ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP + 300);
$freshAuthenticator = makeAuthenticator($freshWithin, new Aud012FakeDb());
[$okPayload, $okUnicid] = $freshAuthenticator->authenticate(
    $rawBody,
    signedHeaders('test_shared_secret_123', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, str_repeat('e', 64))
);
assertAud012($okUnicid === 'TEST-UNICID', '±300 boundary accepted');
assertAud012(is_array($okPayload), '±300 boundary payload');

$upperNonce = strtoupper(str_repeat('f', 64));
try {
    $authenticator->authenticate(
        $rawBody,
        signedHeaders('test_shared_secret_123', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, $upperNonce)
    );
    assertAud012(false, 'uppercase nonce accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'uppercase nonce status mismatch');
}

try {
    $authenticator->authenticate($rawBody, [
        ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
        ModuleRequestSignatureProtocol::HEADER_NONCE => ModuleRequestSignatureProtocol::CONTRACT_NONCE,
    ]);
    assertAud012(false, 'missing signature header accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'missing signature status mismatch');
}

try {
    $authenticator->authenticate('{"unicid":"TEST-UNICID","secret":"test_shared_secret_123"}', []);
    assertAud012(false, 'legacy unsigned request accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'legacy unsigned status mismatch');
}

fwrite(STDOUT, "OK (AUD-012 module request signature and replay protection)\n");
