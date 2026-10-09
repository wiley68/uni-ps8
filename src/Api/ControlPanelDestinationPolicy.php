<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;

/** Structural and address rules, never a list of permitted CP hostnames. */
final class ControlPanelDestinationPolicy
{
    public static function validateRoot(string $url): string
    {
        if (!preg_match('#\Ahttps://([a-z0-9.-]+)(?::443)?/?\z#iD', $url, $matches)) {
            throw new InvalidPayloadException('The Control Panel root must be an absolute HTTPS origin on port 443.');
        }
        $host = strtolower($matches[1]);
        self::validateHostname($host);

        return 'https://' . $host;
    }

    public static function validateHostname(string $host): void
    {
        $host = strtolower($host);
        if (strlen($host) > 253 || strpos($host, '.') === false
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)+\z/iD', $host)
        ) {
            throw new InvalidPayloadException('The Control Panel hostname is invalid.');
        }
        foreach (explode('.', $host) as $label) {
            if (strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') {
                throw new InvalidPayloadException('The Control Panel hostname is invalid.');
            }
        }
        $labels = explode('.', strtolower($host));
        $suffix = (string) end($labels);
        if (!preg_match('/\A[a-z][a-z0-9-]*\z/D', $suffix)) {
            throw new InvalidPayloadException('The Control Panel hostname is invalid.');
        }
        // Reserved namespaces, independent of any legitimate CP deployment host.
        foreach (['localhost', 'local', 'internal', 'test', 'invalid', 'example', 'onion', 'home.arpa'] as $reserved) {
            if ($host === $reserved || substr($host, -strlen('.' . $reserved)) === '.' . $reserved) {
                throw new InvalidPayloadException('The Control Panel hostname is reserved.');
            }
        }
    }

    /** Reject non-global addresses, including IPv4 embedded in IPv6 transition ranges. */
    public static function validateAddress(string $address): void
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new InvalidPayloadException('The Control Panel DNS address is invalid.');
        }
        if (strlen($packed) === 4) {
            $blocked = [
                '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
                '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
                '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            ];
        } else {
            if (!self::inSubnet($packed, '2000::/3')) {
                throw new InvalidPayloadException('The Control Panel DNS address is not public.');
            }
            $blocked = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        }
        foreach ($blocked as $subnet) {
            if (self::inSubnet($packed, $subnet)) {
                throw new InvalidPayloadException('The Control Panel DNS address is not public.');
            }
        }
    }

    private static function inSubnet(string $packed, string $subnet): bool
    {
        [$network, $prefix] = explode('/', $subnet);
        $networkBytes = inet_pton($network);
        $bits = (int) $prefix;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($networkBytes, 0, $bytes)) {
            return false;
        }
        $remaining = $bits % 8;

        return $remaining === 0
            || (ord($packed[$bytes]) & (255 << (8 - $remaining)))
                === (ord($networkBytes[$bytes]) & (255 << (8 - $remaining)));
    }
}
