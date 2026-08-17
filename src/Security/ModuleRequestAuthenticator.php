<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;

final class ModuleRequestAuthenticator
{
    /** @var ConfigurationRepository */
    private $configuration;

    public function __construct(ConfigurationRepository $configuration)
    {
        $this->configuration = $configuration;
    }

    /** @param array<string, mixed> $payload */
    public function authenticate(array $payload): string
    {
        if (!$this->configuration->isEnabled()) {
            throw new ModuleApiException('The module is disabled.', 403);
        }

        $storedUnicid = $this->configuration->getUnicid();
        $storedSecret = $this->configuration->getSecret();
        $unicid = $payload['unicid'] ?? null;
        $secret = $payload['secret'] ?? null;

        if ($storedUnicid === '' || $storedSecret === null) {
            throw new ModuleApiException('The module is not configured.', 401);
        }

        if (!is_string($unicid) || $unicid === '' || !is_string($secret) || $secret === '') {
            throw new ModuleApiException('Missing module credentials.', 401);
        }

        $unicidMatches = hash_equals($storedUnicid, $unicid);
        $secretMatches = hash_equals($storedSecret, $secret);
        if (!$unicidMatches || !$secretMatches) {
            throw new ModuleApiException('Invalid module credentials.', 401);
        }

        return $unicid;
    }
}
