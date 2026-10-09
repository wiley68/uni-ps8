<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;

final class ControlPanelDnsResolver
{
    /** @return list<string> Public addresses for one request; no persistent DNS cache. */
    public static function resolve(string $host): array
    {
        $pending = [$host];
        $visited = [];
        $addresses = [];
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($visited[$name])) {
                continue;
            }
            if (count($visited) >= 8) {
                throw new ConnectionException('The Control Panel DNS alias chain is too long.');
            }
            ControlPanelDestinationPolicy::validateHostname($name);
            $visited[$name] = true;
            $records = @dns_get_record($name, DNS_A | DNS_AAAA | DNS_CNAME);
            if ($records === false) {
                throw new ConnectionException('The Control Panel DNS lookup failed.');
            }
            foreach ($records as $record) {
                if (($record['type'] ?? '') === 'CNAME') {
                    $pending[] = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
                    continue;
                }
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (!is_string($address)) {
                    continue;
                }
                ControlPanelDestinationPolicy::validateAddress($address);
                $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            throw new ConnectionException('The Control Panel hostname has no usable DNS addresses.');
        }

        return array_values(array_unique($addresses));
    }
}
