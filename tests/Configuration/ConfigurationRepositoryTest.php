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

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;

$repository = new ConfigurationRepository();

if (!$repository->install() || !$repository->isEnabled()) {
    fwrite(STDERR, "FAIL: configuration defaults were not installed\n");
    exit(1);
}

$plainSecret = 'local-test-value';
if (!$repository->save(false, '123e4567-e89b-12d3-a456-426614174000', $plainSecret)) {
    fwrite(STDERR, "FAIL: configuration was not saved\n");
    exit(1);
}

if (Configuration::$values[ConfigurationRepository::SECRET] === $plainSecret) {
    fwrite(STDERR, "FAIL: secret was stored in plain text\n");
    exit(1);
}

if ($repository->getSecret() !== $plainSecret || !$repository->hasSecret()) {
    fwrite(STDERR, "FAIL: encrypted secret could not be read\n");
    exit(1);
}

$storedSecret = Configuration::$values[ConfigurationRepository::SECRET];
$repository->save(true, '123e4567-e89b-12d3-a456-426614174000', null);
if (Configuration::$values[ConfigurationRepository::SECRET] !== $storedSecret) {
    fwrite(STDERR, "FAIL: empty secret input did not preserve the stored value\n");
    exit(1);
}

if (!$repository->uninstall() || Configuration::$values !== []) {
    fwrite(STDERR, "FAIL: module configuration was not removed\n");
    exit(1);
}

fwrite(STDOUT, "OK (configuration repository lifecycle and secret storage)\n");
