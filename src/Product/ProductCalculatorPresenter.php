<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;

final class ProductCalculatorPresenter
{
    /** @var Calculator */
    private $calculator;

    public function __construct(Calculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /** @param array<string, mixed> $shop @return array<string, mixed>|null */
    public function present(array $shop, ProductContext $product, string $currencyIso): ?array
    {
        if (!$this->supportsCurrency($shop, $currencyIso)) {
            return null;
        }

        $preferred = $this->calculator->resolvePreferredOffers($shop, $product);
        $offers = [];
        foreach (['standard', 'promo'] as $type) {
            if ($preferred[$type] === null) {
                continue;
            }
            $schemes = [];
            foreach ($this->calculator->availableSchemes($shop, $product, $type) as $scheme) {
                try {
                    $result = $this->calculator->calculate($shop, $product, $scheme->months, $type, 0.0, $scheme->filterId);
                } catch (UnavailableSchemeException $exception) {
                    continue;
                }
                $schemes[] = [
                    'months' => $scheme->months,
                    'filter_id' => $scheme->filterId,
                    'kop_code' => $scheme->kopCode,
                    'first_installment' => $result->firstInstallment->amount,
                    'first_installment_locked' => $result->firstInstallment->locked,
                    'financed_amount' => $result->financedAmount,
                    'monthly_installment' => $result->monthlyInstallment,
                    'total_due' => $result->totalPayable,
                    'glp' => $result->glp,
                    'gpr' => $result->gpr,
                ];
            }
            if ($schemes === []) {
                continue;
            }
            $offers[$type] = [
                'type' => $type,
                'months' => $preferred[$type]->months,
                'monthly_installment' => $preferred[$type]->monthlyInstallment,
                'schemes' => $schemes,
            ];
        }

        if ($offers === []) {
            return null;
        }

        return [
            'product_id' => $product->productId,
            'price' => $product->price,
            'currency_iso' => strtoupper($currencyIso),
            'show_installment' => $this->flag($shop['uni_vnoska'] ?? 0),
            'show_first_installment' => $this->flag($shop['uni_first_vnoska'] ?? 0),
            'dark_button' => $this->flag($shop['uni_type_button'] ?? 0),
            'buttons_in_row' => (int) ($shop['uni_button_row'] ?? 1) === 1,
            'button_width' => $this->dimension($shop['uni_button_width'] ?? 290, 290),
            'button_height' => $this->dimension($shop['uni_button_height'] ?? 56, 56),
            'offers' => $offers,
        ];
    }

    /** @param array<string, mixed> $shop */
    private function supportsCurrency(array $shop, string $currencyIso): bool
    {
        $iso = strtoupper(trim($currencyIso));
        $expected = in_array((int) ($shop['uni_eur'] ?? 0), [2, 3], true) ? 'EUR' : 'BGN';

        return in_array($iso, ['BGN', 'EUR'], true) && $iso === $expected;
    }

    /** @param mixed $value */
    private function flag($value): bool
    {
        return in_array($value, [1, '1', true, 'yes', 'on'], true);
    }

    /** @param mixed $value */
    private function dimension($value, int $fallback): int
    {
        $dimension = (int) $value;

        return $dimension >= 40 && $dimension <= 800 ? $dimension : $fallback;
    }
}
