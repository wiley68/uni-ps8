<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\AmountDisplayFormatter;
use PrestaShop\Module\Unipayment\Calculator\CurrencyDisplayLabel;
use PrestaShop\Module\Unipayment\Calculator\InstallmentLabelFormatter;

function assertCurrencyLabel(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$labels = new CurrencyDisplayLabel();
assertCurrencyLabel($labels->forAmount() === 'евро', 'EUR display label');

$amounts = new AmountDisplayFormatter($labels);
assertCurrencyLabel($amounts->format(1000.0) === ['primary' => '1000.00 евро'], 'single EUR amount');

$installments = new InstallmentLabelFormatter($labels);
assertCurrencyLabel($installments->format(12, 97.49) === '12 x 97.49 евро', 'EUR installment label');

fwrite(STDOUT, "OK (single EUR display labels)\n");
