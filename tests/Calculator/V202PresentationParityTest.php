<?php

declare(strict_types=1);

/**
 * UniPayment v2.0.2 mandatory presentation / Checkout parity matrix.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\SchemePresentationCategory;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

function assertV202(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function v202Scheme(string $type, string $kop, int $months, int $filterId, float $interest, ?array $filter = null): AvailableScheme
{
    return new AvailableScheme(
        $type,
        $kop,
        $months,
        $filterId,
        $filter,
        ['onlineProductCode' => $kop, 'installmentCount' => $months, 'coeff' => 0.09, 'interestPercent' => $interest]
    );
}

function v202Labels(array $schemes, array $shop): array
{
    $labels = [];
    foreach ($schemes as $scheme) {
        $labels[] = $scheme->months . ':' . SchemePresentationCategory::classify($scheme, $shop);
    }

    return $labels;
}

function v202Line(int $productId, float $total): CartLine
{
    return new CartLine(new ProductContext($productId, [], $total), 0, 1, $total);
}

$shop = calculatorFixture([
    'kop' => [
        'by_default' => [
            'uni_kop_default' => 'STD',
            'uni_kop_promo' => 'PROMO',
        ],
    ],
]);

// --- Presentation ordering unit matrix ---
$mixed = [
    v202Scheme('promo', 'PROMO', 12, 3, 0.0, ['id' => 3, 'uni_promo' => 1]),
    v202Scheme('standard', 'NZP', 12, 2, 10.0, ['id' => 2, 'uni_promo' => 0, 'uni_kop' => 'NZP']),
    v202Scheme('standard', 'STD', 6, 0, 18.0),
    v202Scheme('standard', 'STD', 12, 1, 18.0, ['id' => 1, 'uni_promo' => 0, 'uni_kop' => 'STD']),
    v202Scheme('standard', 'STD', 4, 0, 20.0),
];
$sorted = SchemePresentationCategory::sort($mixed, $shop);
assertV202(
    v202Labels($sorted, $shop) === [
        '4:standard',
        '6:standard',
        '12:standard',
        '12:nonzero_promo',
        '12:zero_promo',
    ],
    '1-3: canonical months + category ordering'
);

// Test 1: standard only
$standardOnly = SchemePresentationCategory::sort([
    v202Scheme('standard', 'STD', 12, 0, 18.0),
    v202Scheme('standard', 'STD', 4, 0, 20.0),
    v202Scheme('standard', 'STD', 6, 0, 19.0),
], $shop);
assertV202(v202Labels($standardOnly, $shop) === ['4:standard', '6:standard', '12:standard'], '1: standard-only order');

// Test 2: standard + non-zero promo
$stdNz = SchemePresentationCategory::sort([
    v202Scheme('standard', 'NZP', 12, 2, 10.0, ['id' => 2, 'uni_promo' => 0]),
    v202Scheme('standard', 'STD', 12, 1, 18.0, ['id' => 1, 'uni_promo' => 0]),
    v202Scheme('standard', 'STD', 6, 0, 19.0),
], $shop);
assertV202(
    v202Labels($stdNz, $shop) === ['6:standard', '12:standard', '12:nonzero_promo'],
    '2: standard before non-zero promo'
);

// Test 3 already covered in $mixed above.

$calculator = new Calculator('2026-08-17');
$resolver = new CartSchemeResolver($calculator);
$popup = new ProductPopupSchemeList($calculator);
$checkout = new CheckoutPaymentPresenter(
    $calculator,
    $resolver,
    new CurrencyGate(),
    new CartSnapshot(),
    new CartSnapshotSigner('test-key'),
    new ConsentResolver()
);

// Test 4: multiple non-zero promos → longest preferred when preferred month not forced
$multiNzShop = calculatorFixture([
    'uni_typekop' => 1,
    'uni_shema_current' => 0,
    'uni_first_vnoska' => 1,
    'kop' => [
        'by_default' => ['uni_kop_default' => 'STD', 'uni_kop_promo' => 'PROMO'],
        'by_schema' => ['filters' => [
            ['id' => 1, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '6', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
            ['id' => 2, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
            ['id' => 3, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '18', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
        ]],
    ],
    'coeff_list' => [
        ['onlineProductCode' => 'CAT', 'installmentCount' => 6, 'coeff' => 0.17, 'interestPercent' => 12],
        ['onlineProductCode' => 'CAT', 'installmentCount' => 12, 'coeff' => 0.09, 'interestPercent' => 10],
        ['onlineProductCode' => 'CAT', 'installmentCount' => 18, 'coeff' => 0.07, 'interestPercent' => 9],
        ['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18],
    ],
]);
foreach ([6, 12, 18] as $m) {
    $multiNzShop['uni_meseci_' . $m] = 1;
}
$multiNz = $resolver->resolve($multiNzShop, new CartContext([v202Line(1, 1000)], 1000));
assertV202(
    $multiNz->standardOffer !== null && $multiNz->standardOffer->months === 18,
    '4: multiple non-zero promos prefer longest when no preferred month'
);

// Test 5: multiple 0% → longest
$viewZeros = $checkout->present(true, calculatorFixture(), new CartContext([v202Line(42, 1000)], 1000), 'BGN');
$zeros = array_values(array_filter($viewZeros['schemes'], static function (array $s): bool {
    return !empty($s['zero_interest']);
}));
$maxZero = max(array_map(static function (array $s): int {
    return (int) $s['months'];
}, $zeros));
assertV202($viewZeros['default_scheme_key'] === 'p:' . $maxZero . ':0', '5: longest 0% remains checkout default');

// Test 6: automatic first installment — Cart button == Cart popup monthly
$autoShop = calculatorFixture([
    'uni_typekop' => 1,
    'uni_shema_current' => 12,
    'uni_first_vnoska' => 1,
    'kop' => [
        'by_default' => ['uni_kop_default' => 'STD', 'uni_kop_promo' => 'PROMO'],
        'by_schema' => ['filters' => [
            [
                'id' => 10,
                'product_id' => 1,
                'category_id' => null,
                'uni_meseci' => '12',
                'uni_promo' => 0,
                'uni_parva' => 0,
                'uni_kop' => 'STD',
            ],
            [
                'id' => 11,
                'product_id' => 1,
                'category_id' => null,
                'uni_meseci' => '12',
                'uni_promo' => 0,
                'uni_parva' => 1,
                'uni_kop' => 'COMP',
                'uni_kop_desc' => 'computer promo',
            ],
        ]],
    ],
    'coeff_list' => [
        ['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18],
        ['onlineProductCode' => 'COMP', 'installmentCount' => 12, 'coeff' => 0.093327, 'interestPercent' => 8],
    ],
]);
$autoShop['uni_meseci_12'] = 1;
$total = 1026.63; // chosen so full-total ≈ 93.33 and parva ≈ 85.55 with given coeffs when rounded
// Prefer deterministic round numbers:
$total = 1000.0;
$autoCoeff = 0.093327;
$autoShop['coeff_list'][1]['coeff'] = $autoCoeff;
$autoCart = new CartContext([v202Line(1, $total)], $total);
$autoResolution = $resolver->resolve($autoShop, $autoCart);
assertV202($autoResolution->standardOffer !== null, '6: auto first-installment cart has preferred offer');
assertV202($autoResolution->standardOffer->kopCode === 'COMP', '6: preferred is promotional COMP candidate');
$popupScheme = null;
foreach ($autoResolution->standardSchemes as $scheme) {
    if ($scheme->kopCode === 'COMP' && $scheme->months === 12) {
        $popupScheme = $scheme;
        break;
    }
}
assertV202($popupScheme !== null, '6: COMP scheme present in cart schemes');
$popupCalc = $calculator->calculateScheme($autoShop, $total, $popupScheme);
assertV202(
    abs($autoResolution->standardOffer->monthlyInstallment - $popupCalc->monthlyInstallment) < 0.011,
    '6: cart button monthly equals popup monthly for uni_parva scheme'
);
assertV202($popupCalc->firstInstallment->locked === true, '6: popup uses locked automatic first installment');
$fullTotalOffer = $calculator->createButtonOffer($popupScheme, $total, 'standard');
assertV202(
    $fullTotalOffer !== null
        && abs($fullTotalOffer->monthlyInstallment - $autoResolution->standardOffer->monthlyInstallment) > 0.5,
    '6: regression — financed amount differs from naive full-total button offer'
);

// Test 7: Checkout without 0% → longest non-zero promo
$noZeroShop = calculatorFixture([
    'uni_typekop' => 1,
    'uni_shema_current' => 6,
    'kop' => [
        'by_default' => ['uni_kop_default' => 'STD', 'uni_kop_promo' => 'PROMO'],
        'by_schema' => ['filters' => [
            ['id' => 1, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '6', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'STD'],
            ['id' => 2, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
            ['id' => 3, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '18', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
        ]],
    ],
    'coeff_list' => [
        ['onlineProductCode' => 'STD', 'installmentCount' => 6, 'coeff' => 0.18, 'interestPercent' => 20],
        ['onlineProductCode' => 'CAT', 'installmentCount' => 12, 'coeff' => 0.09, 'interestPercent' => 10],
        ['onlineProductCode' => 'CAT', 'installmentCount' => 18, 'coeff' => 0.07, 'interestPercent' => 9],
    ],
]);
foreach ([6, 12, 18] as $m) {
    $noZeroShop['uni_meseci_' . $m] = 1;
}
$noZeroView = $checkout->present(true, $noZeroShop, new CartContext([v202Line(1, 1000)], 1000), 'BGN');
assertV202(is_array($noZeroView), '7: checkout without 0% renders');
$nzDefaults = array_values(array_filter($noZeroView['schemes'], static function (array $s): bool {
    return ($s['presentation_category'] ?? '') === SchemePresentationCategory::NONZERO_PROMO;
}));
assertV202($nzDefaults !== [], '7: non-zero promo schemes present');
$maxNz = max(array_map(static function (array $s): int {
    return (int) $s['months'];
}, $nzDefaults));
assertV202($noZeroView['default_scheme_key'] === $maxNz . ':2' || $noZeroView['default_scheme_key'] === $maxNz . ':3', '7: longest non-zero promo is automatic default');

// Test 8 covered by test 5.

// Test 9: valid explicit preference beats longer 0%
$baseView = $checkout->present(true, calculatorFixture(), new CartContext([v202Line(42, 1000)], 1000), 'BGN');
$short = null;
foreach ($baseView['schemes'] as $scheme) {
    if ($scheme['scheme_type'] === 'standard' && (int) $scheme['months'] === 6) {
        $short = $scheme;
        break;
    }
}
assertV202($short !== null, '9: shorter standard exists');
$prefView = $checkout->present(true, calculatorFixture(), new CartContext([v202Line(42, 1000)], 1000), 'BGN', [
    'scheme_type' => $short['scheme_type'],
    'kop_code' => $short['kop_code'],
    'months' => $short['months'],
    'filter_id' => $short['filter_id'],
    'first_installment' => 40.0,
]);
assertV202($prefView['default_scheme_key'] === $short['key'], '9: explicit preference preserved');
assertV202($prefView['default_first_installment'] === 40.0, '9: editable preferred first installment preserved');

// Test 13: conflicting cross-line uni_parva — no line-order-dependent representative
$conflictShop = calculatorFixture([
    'uni_typekop' => 1,
    'uni_shema_current' => 12,
    'kop' => [
        'by_default' => ['uni_kop_default' => 'STD', 'uni_kop_promo' => 'PROMO'],
        'by_schema' => ['filters' => [
            ['id' => 61, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
            ['id' => 62, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 1, 'uni_kop' => 'CAT'],
        ]],
    ],
    'coeff_list' => [
        ['onlineProductCode' => 'CAT', 'installmentCount' => 12, 'coeff' => 0.09, 'interestPercent' => 10],
    ],
]);
$conflictShop['uni_meseci_12'] = 1;
$orderA = $resolver->resolve($conflictShop, new CartContext([v202Line(1, 1000), v202Line(2, 1000)], 1000));
$orderB = $resolver->resolve($conflictShop, new CartContext([v202Line(2, 1000), v202Line(1, 1000)], 1000));
assertV202(count($orderA->standardSchemes) === 1 && count($orderB->standardSchemes) === 1, '13: conflicting scheme remains in intersection membership');
assertV202(
    ($orderA->standardOffer === null) === ($orderB->standardOffer === null),
    '13: representative presence is line-order independent'
);
if ($orderA->standardOffer !== null && $orderB->standardOffer !== null) {
    assertV202(
        abs($orderA->standardOffer->monthlyInstallment - $orderB->standardOffer->monthlyInstallment) < 0.001,
        '13: representative monthly is line-order independent'
    );
} else {
    assertV202($orderA->standardOffer === null && $orderB->standardOffer === null, '13: ambiguous uni_parva excludes representative');
}

// Product popup uses shared ordering
$product = new ProductContext(1, [], 1000);
$productShop = calculatorFixture([
    'uni_typekop' => 1,
    'kop' => [
        'by_default' => ['uni_kop_default' => 'STD', 'uni_kop_promo' => 'PROMO'],
        'by_schema' => ['filters' => [
            ['id' => 1, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'STD'],
            ['id' => 2, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
            ['id' => 3, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 1, 'uni_parva' => 0, 'uni_kop' => 'ZERO', 'uni_kop_desc' => '0%'],
        ]],
    ],
]);
$productShop['uni_meseci_12'] = 1;
$productSchemes = $popup->schemes($productShop, $product, 'standard');
$twelve = array_values(array_filter($productSchemes, static function (AvailableScheme $s): bool {
    return $s->months === 12;
}));
assertV202(count($twelve) >= 3, 'product exposes same-month categories');
assertV202(
    SchemePresentationCategory::classify($twelve[0], $productShop) === SchemePresentationCategory::STANDARD
        && SchemePresentationCategory::classify($twelve[1], $productShop) === SchemePresentationCategory::NONZERO_PROMO
        && SchemePresentationCategory::classify($twelve[2], $productShop) === SchemePresentationCategory::ZERO_PROMO,
    'product same-month order is standard → non-zero → 0%'
);

// Checkout JS transition contract
$js = (string) file_get_contents(dirname(__DIR__, 2) . '/views/js/checkout-payment.js');
assertV202(strpos($js, 'previousFirstLocked') !== false, '10-12: checkout tracks previous locked state');
assertV202(
    strpos($js, 'previousFirstLocked === true') !== false
        && strpos($js, 'first.value = "0"') !== false,
    '10: locked → editable resets first installment to 0'
);

$css = (string) file_get_contents(dirname(__DIR__, 2) . '/views/css/checkout-payment.css');
assertV202(
    strpos($css, '.unipayment-checkout__select') !== false
        && strpos($css, '--unipayment-checkout-red') !== false
        && strpos($css, '.unipayment-checkout__select:disabled') !== false,
    '9: checkout selector keeps UniCredit red styling incl. disabled'
);

fwrite(STDOUT, "OK (v2.0.2 presentation / checkout parity matrix)\n");
