<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;

function assertCheckoutPresenter(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$calculator = new Calculator('2026-08-17');
$presenter = new CheckoutPaymentPresenter($calculator, new CartSchemeResolver($calculator), new CurrencyGate(), new CartSnapshot(), new CartSnapshotSigner('test-key'), new ConsentResolver());
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1000), 0, 1, 1000)], 1000);
$shop = calculatorFixture(['uni_eur' => 0, 'consents' => [
    ['id' => 2, 'name' => 'Optional information', 'mandatory' => 0],
    ['id' => 1, 'name' => 'Mandatory terms', 'mandatory' => 1],
]]);

assertCheckoutPresenter($presenter->present(false, $shop, $cart, 'BGN') === null, 'disabled module exposed payment option');
assertCheckoutPresenter($presenter->present(true, $shop, $cart, 'EUR') === null, 'unsupported currency exposed payment option');
assertCheckoutPresenter($presenter->present(true, $shop, new CartContext([], 0), 'BGN') === null, 'cart without schemes exposed payment option');
$view = $presenter->present(true, $shop, $cart, 'BGN');
assertCheckoutPresenter(is_array($view) && count($view['schemes']) === 5, 'unified standard/promo schemes missing');
assertCheckoutPresenter($view['consents'][0]['mandatory'] && !$view['consents'][1]['mandatory'], 'mandatory/optional consent distinction failed');
assertCheckoutPresenter(strpos($view['cart_snapshot'], '.') !== false, 'signed cart snapshot missing');

fwrite(STDOUT, "OK (Phase 8 checkout payment presenter)\n");
