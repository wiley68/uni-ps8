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
require_once dirname(__DIR__, 2) . '/src/Security/ModuleRequestAuthenticator.php';

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\ModuleRequestAuthenticator;

function assertPhase4(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function expectApiStatus(ModuleRequestAuthenticator $authenticator, array $payload, int $status): void
{
    try {
        $authenticator->authenticate($payload);
        assertPhase4(false, "expected HTTP {$status} authentication error");
    } catch (ModuleApiException $exception) {
        assertPhase4($exception->getStatusCode() === $status, "unexpected authentication error status");
    }
}

$configuration = new ConfigurationRepository();
$configuration->save(true, '123e4567-e89b-12d3-a456-426614174000', 'test-secret');
$authenticator = new ModuleRequestAuthenticator($configuration);

$unicid = $authenticator->authenticate([
    'unicid' => '123e4567-e89b-12d3-a456-426614174000',
    'secret' => 'test-secret',
]);
assertPhase4($unicid === '123e4567-e89b-12d3-a456-426614174000', 'valid credentials were rejected');

expectApiStatus($authenticator, [], 401);
expectApiStatus($authenticator, [
    'unicid' => '123e4567-e89b-12d3-a456-426614174000',
    'secret' => 'wrong-secret',
], 401);

$configuration->save(false, '123e4567-e89b-12d3-a456-426614174000', null);
expectApiStatus($authenticator, [
    'unicid' => '123e4567-e89b-12d3-a456-426614174000',
    'secret' => 'test-secret',
], 403);

fwrite(STDOUT, "OK (Phase 4 centralized module request authentication)\n");
