<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/**
 * Woo-compatible installment button label (e.g. "12 x 97.49 EUR").
 */
final class InstallmentLabelFormatter
{
    public function format(int $months, float $monthlyInstallment, int $currencyMode): string
    {
        if ($currencyMode === 1 || $currencyMode === 2) {
            $secondary = $currencyMode === 1
                ? round($monthlyInstallment / 1.95583, 2)
                : round($monthlyInstallment * 1.95583, 2);

            return sprintf(
                '%d x %s %s (%s %s)',
                $months,
                number_format($monthlyInstallment, 2, '.', ''),
                $currencyMode === 1 ? 'BGN' : 'EUR',
                number_format($secondary, 2, '.', ''),
                $currencyMode === 1 ? 'EUR' : 'BGN'
            );
        }

        return sprintf(
            '%d x %s %s',
            $months,
            number_format($monthlyInstallment, 2, '.', ''),
            $currencyMode === 3 ? 'EUR' : 'BGN'
        );
    }
}
