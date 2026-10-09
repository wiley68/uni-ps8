<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

/** Intercept libcurl inside this namespace: no network or live PrestaShop bootstrap. */
function curl_init(string $url)
{
    ++$GLOBALS['unipaymentCpCurlInit'];

    return (object) ['url' => $url];
}

function curl_setopt_array($handle, array $options): bool
{
    $handle->options = $options;

    return $GLOBALS['unipaymentCpCurlOptionsOk'] ?? true;
}

function curl_exec($handle)
{
    $GLOBALS['unipaymentCpCurlRequests'][] = $handle->options + [CURLOPT_URL => $handle->url];

    return ($GLOBALS['unipaymentCpResponse'])($handle->url);
}

function curl_getinfo($handle, int $option): int
{
    return 200;
}

function curl_errno($handle): int { return 0; }
function curl_error($handle): string { return ''; }
function curl_close($handle): void {}
