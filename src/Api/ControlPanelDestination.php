<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

/** The only deployment authority. API paths and persistence markers are derived. */
final class ControlPanelDestination
{
    public const CONTROL_PANEL_URL = 'https://uni.avalonbg.com';

    public static function root(): string
    {
        return ControlPanelDestinationPolicy::validateRoot(self::CONTROL_PANEL_URL);
    }

    public static function apiBase(): string
    {
        return self::root() . '/api/v1';
    }

    public static function hostname(): string
    {
        return (string) parse_url(self::root(), PHP_URL_HOST);
    }
}
