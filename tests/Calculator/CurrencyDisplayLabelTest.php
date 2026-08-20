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
assertCurrencyLabel($labels->forIso('EUR') === 'EUR', 'CLI fallback must keep English EUR source');
assertCurrencyLabel($labels->forIso('BGN') === 'BGN', 'CLI fallback must keep English BGN source');
assertCurrencyLabel($labels->forIso('eur') === 'EUR', 'ISO lookup must be case-insensitive');

$amounts = new AmountDisplayFormatter($labels);
$single = $amounts->format(1000.0, ['uni_eur' => 3]);
assertCurrencyLabel($single['primary'] === '1000.00 EUR' && $single['dual'] === false, 'EUR-only amount must use translated suffix path');

$installments = new InstallmentLabelFormatter($labels);
assertCurrencyLabel(
    $installments->format(12, 97.49, 3) === '12 x 97.49 EUR',
    'installment label must route EUR through CurrencyDisplayLabel'
);
assertCurrencyLabel(
    $installments->format(12, 97.49, 0) === '12 x 97.49 BGN',
    'installment label must route BGN through CurrencyDisplayLabel'
);

$module = (string) file_get_contents(dirname(__DIR__, 2) . '/unipayment.php');
assertCurrencyLabel(strpos($module, "trans('EUR', [], 'Modules.Unipayment.Shop')") !== false, 'EUR must be registered on the module for the PS catalog');
assertCurrencyLabel(strpos($module, "trans('BGN', [], 'Modules.Unipayment.Shop')") !== false, 'BGN must be registered on the module for the PS catalog');

fwrite(STDOUT, "OK (Currency display labels are translatable)\n");
