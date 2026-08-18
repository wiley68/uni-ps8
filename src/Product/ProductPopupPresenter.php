<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupPresenter
{
    /** @param array<string, mixed> $shop @return array<string, mixed> */
    public function present(array $shop, string $buttonAction): array
    {
        $bannerLink = $this->url($shop['reklama_url'] ?? '');
        if ($bannerLink === '') {
            $bannerLink = $this->url($shop['uni_backurl'] ?? '');
        }

        return [
            'banner_url' => $this->url($shop['uni_picture'] ?? ''),
            'banner_url_mobile' => $this->url($shop['uni_picturem'] ?? ''),
            'banner_link' => $bannerLink,
            'currency_mode' => (int) ($shop['uni_eur'] ?? 0),
            'button_action' => $buttonAction === 'buy' ? 'buy' : 'add_to_cart',
            'secondary_label' => $buttonAction === 'buy' ? 'Купи' : 'Добави в количката',
        ];
    }

    /** @param mixed $value */
    private function url($value): string
    {
        $url = trim((string) $value);

        return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : '';
    }
}
