<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    public static function updateValue($key, $value): bool
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return self::$values[$key] ?? $default;
    }

    public static function deleteByName($key): bool
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

require_once dirname(__DIR__, 2) . '/src/Configuration/ConfigurationRepository.php';
require_once dirname(__DIR__, 2) . '/src/Api/Exception/ModuleApiException.php';
require_once dirname(__DIR__, 2) . '/src/Security/ClockInterface.php';
require_once dirname(__DIR__, 2) . '/src/Security/SystemClock.php';
require_once dirname(__DIR__, 2) . '/src/Security/FixedClock.php';
require_once dirname(__DIR__, 2) . '/src/Security/ModuleRequestSignatureProtocol.php';
require_once dirname(__DIR__, 2) . '/src/Security/ModuleRequestSignatureVerifier.php';
require_once dirname(__DIR__, 2) . '/src/Security/ApiNonceRepository.php';
require_once dirname(__DIR__, 2) . '/src/Security/ModuleRequestAuthenticator.php';

final class ModuleAuthTestFakeDb
{
    public function execute(string $sql): bool
    {
        unset($sql);

        return true;
    }

    public function insert(string $table, array $data, $nullValues = false, $useCache = true, $type = 1): bool
    {
        unset($table, $data, $nullValues, $useCache, $type);

        return true;
    }

    public function getMsgError(): string
    {
        return '';
    }
}

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\ApiNonceRepository;
use PrestaShop\Module\Unipayment\Security\FixedClock;
use PrestaShop\Module\Unipayment\Security\ModuleRequestAuthenticator;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureVerifier;

function assertPhase4(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function expectApiStatus(ModuleRequestAuthenticator $authenticator, array $payload, string $rawBody, array $headers, int $status): void
{
    try {
        $authenticator->authenticate($payload, $rawBody, $headers);
        assertPhase4(false, "expected HTTP {$status} authentication error");
    } catch (ModuleApiException $exception) {
        assertPhase4($exception->getStatusCode() === $status, 'unexpected authentication error status');
    }
}

$configuration = new ConfigurationRepository();
$configuration->save(true, '123e4567-e89b-12d3-a456-426614174000', 'test-secret');
$clock = new FixedClock(time());
$authenticator = new ModuleRequestAuthenticator(
    $configuration,
    new ModuleRequestSignatureVerifier($clock),
    new ApiNonceRepository(new ModuleAuthTestFakeDb()),
    $clock
);

$rawBody = '{"unicid":"123e4567-e89b-12d3-a456-426614174000"}';
$payload = json_decode($rawBody, true);
assertPhase4(is_array($payload), 'payload decode failed');

expectApiStatus($authenticator, $payload, $rawBody, [], 401);
expectApiStatus($authenticator, $payload, $rawBody, [
    ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => (string) time(),
    ModuleRequestSignatureProtocol::HEADER_NONCE => str_repeat('a', 64),
    ModuleRequestSignatureProtocol::HEADER_SIGNATURE => str_repeat('0', 64),
], 401);

expectApiStatus($authenticator, [
    'unicid' => '123e4567-e89b-12d3-a456-426614174000',
    'secret' => 'test-secret',
], '{"unicid":"123e4567-e89b-12d3-a456-426614174000","secret":"test-secret"}', [], 401);

$configuration->save(false, '123e4567-e89b-12d3-a456-426614174000', null);
expectApiStatus($authenticator, $payload, $rawBody, [], 403);

fwrite(STDOUT, "OK (Phase 4 centralized module request authentication)\n");
