<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Product\ProductCalculatorPresenter;

function assertProductPresenter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$presenter = new ProductCalculatorPresenter(new Calculator('2026-08-17'));
$product = new ProductContext(42, [7, 9], 1000.0);
$view = $presenter->present(calculatorFixture(['uni_eur' => 0]), $product, 'BGN');

assertProductPresenter(is_array($view), 'BGN product calculator must be available');
assertProductPresenter(isset($view['offers']['standard'], $view['offers']['promo']), 'standard and promo buttons must be present');
assertProductPresenter($view['offers']['standard']['months'] === 12, 'preferred standard month must come from the domain');
assertProductPresenter(count($view['offers']['standard']['schemes']) === 3, 'all enabled standard schemes must be exposed');
assertProductPresenter($view['offers']['promo']['schemes'][0]['glp'] === 0.0, 'promo scheme must remain zero-interest');
assertProductPresenter($presenter->present(calculatorFixture(['uni_eur' => 0]), $product, 'EUR') === null, 'mismatched currency must hide calculator');
assertProductPresenter($presenter->present(calculatorFixture(['uni_eur' => 3]), $product, 'EUR') !== null, 'EUR-only configuration must support EUR');
assertProductPresenter($presenter->present(calculatorFixture(['uni_status' => 0]), $product, 'BGN') === null, 'inactive shop must hide calculator');

$schema = calculatorFixture([
    'uni_typekop' => 1,
    'kop' => ['by_schema' => ['filters' => schemaFiltersFixture()]],
]);
$schemaView = $presenter->present($schema, $product, 'BGN');
assertProductPresenter(is_array($schemaView), 'matching schema calculator must be available');
assertProductPresenter(count($schemaView['offers']['standard']['schemes']) === 3, 'schema schemes must preserve duplicate-month filter identity');

fwrite(STDOUT, "OK (Phase 6 product calculator presenter)\n");
