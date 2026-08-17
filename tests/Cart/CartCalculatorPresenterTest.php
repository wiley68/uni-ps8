<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartCalculatorPresenter;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;

function assertCartPresenter(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$calculator = new Calculator('2026-08-17');
$presenter = new CartCalculatorPresenter(new CartSchemeResolver($calculator), $calculator);
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1000), 0, 2, 1000)], 1000);
$view = $presenter->present(calculatorFixture(['uni_eur' => 0]), $cart, 'BGN');
assertCartPresenter(is_array($view) && isset($view['offers']['standard'], $view['offers']['promo']), 'cart presentation offers missing');
assertCartPresenter($view['line_count'] === 1 && $view['cart_total'] === 1000.0, 'cart presentation context differs');
assertCartPresenter($presenter->present(calculatorFixture(['uni_eur' => 0]), $cart, 'EUR') === null, 'cart currency gate mismatch');
assertCartPresenter($presenter->present(calculatorFixture(['uni_eur' => 3]), $cart, 'EUR') !== null, 'EUR cart was rejected');

fwrite(STDOUT, "OK (Phase 7 cart calculator presenter)\n");
