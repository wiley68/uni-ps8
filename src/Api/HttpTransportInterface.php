<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @param list<string> $resolvedAddresses Validated public DNS addresses, pinned by the transport.
     */
    public function request(string $method, string $url, array $headers, ?array $payload, array $resolvedAddresses): HttpResponse;
}
