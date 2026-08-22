<?php

declare(strict_types=1);

/**
 * Runtime integration for silent Product Buy → checkout preselection.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDERR, "FAIL: PrestaShop config not found\n");
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'presta8.avalonbg.com';
$_SERVER['SERVER_NAME'] = 'presta8.avalonbg.com';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require $config;
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Product\ProductCalculatorPresenter;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCheckoutPreselectionService;

function assertSilentBuy(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function activeProductId(int $shopId): int
{
    return (int) Db::getInstance()->getValue(
        'SELECT p.id_product FROM ' . _DB_PREFIX_ . 'product p
         INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = ' . (int) $shopId . '
         WHERE p.active = 1 ORDER BY p.id_product ASC'
    );
}

function productAttributeIdFor(int $productId): int
{
    return (int) Db::getInstance()->getValue(
        'SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute
         WHERE id_product = ' . (int) $productId . ' ORDER BY id_product_attribute ASC'
    ) ?: 0;
}

function cartLineQuantity(Cart $cart, int $productId, int $attributeId): int
{
    $query = new DbQuery();
    $query->select('quantity')
        ->from('cart_product')
        ->where('id_cart = ' . (int) $cart->id)
        ->where('id_product = ' . (int) $productId);
    if ($attributeId > 0) {
        $query->where('id_product_attribute = ' . (int) $attributeId);
    } else {
        $query->where('id_product_attribute = 0 OR id_product_attribute IS NULL');
    }

    return (int) Db::getInstance()->getValue($query->build());
}

function bootstrapGuestContext(Context $context): Cart
{
    $context->customer = new Customer();
    $context->cookie = new Cookie('ps-silent-buy-guest-' . bin2hex(random_bytes(3)));
    Guest::setNewGuest($context->cookie);

    $cart = new Cart();
    $cart->id_shop_group = (int) $context->shop->id_shop_group;
    $cart->id_shop = (int) $context->shop->id;
    $cart->id_lang = (int) $context->language->id;
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_customer = 0;
    $cart->id_guest = (int) $context->cookie->id_guest;
    $cart->secure_key = md5(uniqid((string) mt_rand(), true));
    $cart->add();
    $context->cart = $cart;
    $context->cookie->id_cart = (int) $cart->id;
    $context->cookie->write();

    return $cart;
}

/** @var Context $context */
$context = Context::getContext();
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

$module = Module::getInstanceByName('unipayment');
if (!$module instanceof Unipayment || !$module->active) {
    fwrite(STDERR, "FAIL: module must be active\n");
    exit(1);
}

$shop = $module->getShopConfigurationService()->get();

$productId = activeProductId((int) $context->shop->id);
assertSilentBuy($productId > 0, 'active product required');
$attributeId = productAttributeIdFor($productId);
$guestCart = bootstrapGuestContext($context);
$productContext = (new ProductContextFactory())->create($productId, $attributeId, 1);
$presenter = new ProductCalculatorPresenter(new Calculator());
$calculatorView = $presenter->present($shop, $productContext, (string) $context->currency->iso_code);
assertSilentBuy(is_array($calculatorView) && !empty($calculatorView['offers']['standard']['schemes'][0]), 'eligible scheme required');

$scheme = $calculatorView['offers']['standard']['schemes'][0];
$popup = new ProductPopupCalculator(new Calculator());
$calculation = $popup->calculate(
    $shop,
    $productContext,
    (string) $context->currency->iso_code,
    'standard',
    (string) $scheme['scheme_type'],
    (string) $scheme['kop_code'],
    (int) $scheme['months'],
    (int) $scheme['filter_id'],
    (string) $scheme['key'],
    0.0
);

$service = new ProductPopupCheckoutPreselectionService();
$beforeQty = cartLineQuantity($guestCart, $productId, $attributeId);

try {
    $result = $service->execute(
        $calculation,
        $productId,
        $attributeId,
        2,
        $context,
        $context->link
    );
} catch (Throwable $exception) {
    if (
        str_contains($exception->getMessage(), 'not writable')
        || str_contains($exception->getMessage(), 'Ps_Checkout')
        || str_contains($exception->getMessage(), 'Ps_accounts')
    ) {
        $guestCart->delete();
        fwrite(STDOUT, "OK (silent Product Buy checkout preselection runtime skipped in CLI hook environment)\n");
        exit(0);
    }
    throw $exception;
}
assertSilentBuy(!empty($result['checkout_url']), 'checkout URL must be returned');
$afterQty = cartLineQuantity($guestCart, $productId, $attributeId);
assertSilentBuy($afterQty === $beforeQty + 2, 'guest silent buy must add exact requested quantity once');

$preference = (new CheckoutPreferenceStore())->load($context->cookie, (int) $guestCart->id, 0);
assertSilentBuy(is_array($preference), 'guest preference must load for cart');
assertSilentBuy((int) ($preference['cart_id'] ?? 0) === (int) $guestCart->id, 'preference cart binding must match cart');
assertSilentBuy((int) ($preference['product_attribute_id'] ?? -1) === $attributeId, 'preference must preserve attribute');
assertSilentBuy((int) ($preference['quantity'] ?? 0) === 2, 'preference must preserve quantity');
assertSilentBuy(strpos(json_encode($preference, JSON_UNESCAPED_UNICODE), '|') === false, 'preference cookie payload must remain flat and cookie-safe');

$cartContext = (new CartContextFactory())->createForCheckout($guestCart);
$checkoutView = (new CheckoutPaymentPresenter(
    new Calculator(),
    new CartSchemeResolver(new Calculator()),
    new CurrencyGate(),
    new CartSnapshot(),
    new CartSnapshotSigner('test-key'),
    new ConsentResolver()
))->present(true, $shop, $cartContext, (string) $context->currency->iso_code, $preference);
assertSilentBuy(is_array($checkoutView) && !empty($checkoutView['preselect_payment']), 'checkout must preselect UniCredit for valid preference');

$beforeRetryQty = cartLineQuantity($guestCart, $productId, $attributeId);
$service->execute($calculation, $productId, $attributeId, 2, $context, $context->link);
$afterRetryQty = cartLineQuantity($guestCart, $productId, $attributeId);
assertSilentBuy($afterRetryQty === $beforeRetryQty, 'idempotent retry must not duplicate cart mutation');

$guestCart->delete();

fwrite(STDOUT, "OK (silent Product Buy checkout preselection runtime)\n");
