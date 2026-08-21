<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Narrow gateway so tests can substitute createSession without subclassing the HTTP client.
 */
interface SmartUcfSessionGatewayInterface
{
    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $snapshot
     * @return array{session_id: string, redirect_url: string, http_code: int, raw_request?: string, raw_response?: string}
     */
    public function createSession(array $shop, array $snapshot): array;
}
