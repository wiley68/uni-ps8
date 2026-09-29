<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/** EUR installment button label (e.g. "12 x 97.49 евро"). */
final class InstallmentLabelFormatter
{
    /** @var CurrencyDisplayLabel */
    private $labels;

    public function __construct(?CurrencyDisplayLabel $labels = null)
    {
        $this->labels = $labels ?? new CurrencyDisplayLabel();
    }

    public function format(int $months, float $monthlyInstallment): string
    {
        return sprintf(
            '%d x %s %s',
            $months,
            number_format($monthlyInstallment, 2, '.', ''),
            $this->labels->forAmount()
        );
    }
}
