<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

class Currency
{
    public $id;
    public $iso_code;
    public function __construct(int $id)
    {
        $this->id = $id;
        $this->iso_code = [1 => 'EUR', 2 => 'BGN', 3 => 'USD'][$id] ?? '';
    }
}
class Cart
{
    public $id_currency;
    public function __construct(int $idCurrency) { $this->id_currency = $idCurrency; }
}
class Context
{
    public $currency;
    public function __construct(Currency $currency) { $this->currency = $currency; }
}
class Validate
{
    public static function isLoadedObject($object): bool { return isset($object->id) && $object->id > 0 && $object->iso_code !== ''; }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\CartCurrencyGuard;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;

function assertEurGuard(bool $value, string $message): void
{
    if (!$value) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$gate = new CurrencyGate();
foreach (['EUR', ' eur '] as $iso) assertEurGuard($gate->supports($iso), "EUR variant rejected: {$iso}");
foreach (['BGN', 'USD', 'GBP', ''] as $iso) assertEurGuard(!$gate->supports($iso), "non-EUR accepted: {$iso}");
$guard = new CartCurrencyGuard();
assertEurGuard($guard->supportedIso(new Cart(1), new Context(new Currency(1))) === 'EUR', 'EUR cart rejected');
foreach ([[1, 2], [2, 1], [2, 2], [3, 3], [0, 1], [9, 1]] as [$cartId, $contextId]) {
    assertEurGuard($guard->supportedIso(new Cart($cartId), new Context(new Currency($contextId))) === '', "unsafe cart/context pair accepted: {$cartId}/{$contextId}");
}
fwrite(STDOUT, "OK (EUR cart/context currency guard)\n");
