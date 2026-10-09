<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

/** Test-only DNS seam; no resolver or deployment override is shipped. */
function dns_get_record(string $host, int $type)
{
    return $GLOBALS['unipaymentCpDnsRecords'][$host] ?? [['type' => 'A', 'ip' => '93.184.216.34']];
}
