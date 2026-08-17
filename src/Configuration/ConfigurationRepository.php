<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

final class ConfigurationRepository
{
    public const ENABLED = 'UNIPAYMENT_ENABLED';
    public const UNICID = 'UNIPAYMENT_UNICID';
    public const SECRET = 'UNIPAYMENT_SECRET';

    private const ENCRYPTED_PREFIX = 'enc:v1:';

    public function install(): bool
    {
        return \Configuration::updateValue(self::ENABLED, true)
            && \Configuration::updateValue(self::UNICID, '');
    }

    public function uninstall(): bool
    {
        $result = true;

        foreach ([
            self::ENABLED,
            self::UNICID,
            self::SECRET,
            'UNIPAYMENT_CP_ACCESS_TOKEN',
            'UNIPAYMENT_CP_TOKEN_TYPE',
            'UNIPAYMENT_CP_TOKEN_EXPIRES_AT',
        ] as $key) {
            $result = \Configuration::deleteByName($key) && $result;
        }

        return $result;
    }

    public function save(bool $enabled, string $unicid, ?string $secret): bool
    {
        $result = \Configuration::updateValue(self::ENABLED, $enabled)
            && \Configuration::updateValue(self::UNICID, $unicid);

        if ($secret === null) {
            return $result;
        }

        return \Configuration::updateValue(self::SECRET, $this->encrypt($secret)) && $result;
    }

    public function isEnabled(): bool
    {
        return (bool) \Configuration::get(self::ENABLED, null, null, null, true);
    }

    public function getUnicid(): string
    {
        return trim((string) \Configuration::get(self::UNICID));
    }

    public function getSecret(): ?string
    {
        $storedSecret = (string) \Configuration::get(self::SECRET);
        if ($storedSecret === '') {
            return null;
        }

        if (strpos($storedSecret, self::ENCRYPTED_PREFIX) !== 0) {
            return null;
        }

        try {
            $decrypted = $this->getCipher()->decrypt(substr($storedSecret, strlen(self::ENCRYPTED_PREFIX)));
        } catch (\Throwable $exception) {
            return null;
        }

        return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
    }

    public function hasSecret(): bool
    {
        return $this->getSecret() !== null;
    }

    public function isSecretReadable(): bool
    {
        $storedSecret = (string) \Configuration::get(self::SECRET);

        return $storedSecret === '' || $this->getSecret() !== null;
    }

    private function encrypt(string $secret): string
    {
        return self::ENCRYPTED_PREFIX . $this->getCipher()->encrypt($secret);
    }

    private function getCipher(): \PhpEncryption
    {
        return new \PhpEncryption(_NEW_COOKIE_KEY_);
    }
}
