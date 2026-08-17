<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

final class ConfigurationValidator
{
    public const ERROR_UNICID_REQUIRED = 'unicid_required';
    public const ERROR_UNICID_INVALID = 'unicid_invalid';
    public const ERROR_SECRET_REQUIRED = 'secret_required';
    public const ERROR_SECRET_TOO_LONG = 'secret_too_long';

    /**
     * @return string[]
     */
    public function validate(string $unicid, string $secret, bool $hasStoredSecret): array
    {
        $errors = [];

        if ($unicid === '') {
            $errors[] = self::ERROR_UNICID_REQUIRED;
        } elseif (strlen($unicid) > 36 || !$this->isUuid($unicid)) {
            $errors[] = self::ERROR_UNICID_INVALID;
        }

        if ($secret === '' && !$hasStoredSecret) {
            $errors[] = self::ERROR_SECRET_REQUIRED;
        } elseif (strlen($secret) > 64) {
            $errors[] = self::ERROR_SECRET_TOO_LONG;
        }

        return $errors;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
