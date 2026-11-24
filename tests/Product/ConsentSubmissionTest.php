<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/Order/OrderOrchestratorTest.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartPopupApplyService;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

final class Context { public $currency; public $cart; public $customer; public $shop; public $language; public $cookie; }
final class Currency { public $id; public $iso_code='EUR'; public function __construct(int $id) { $this->id=$id; } }
final class Cart {
    public $id = 9;
    public $id_currency = 1;
    public $id_address_delivery = 0;
    public $id_address_invoice = 0;
    public function __set($key, $value): void {}
    public function add(): bool { return true; }
    public function update(): bool { return true; }
    public function updateQty($quantity, $idProduct, $idAttribute): void {}
}
final class Cookie extends stdClass {
    public $id_guest = 1;
    public function write(): void {}
}
final class Address extends stdClass {
    public $id;
    public $id_customer = 42;
    public $deleted = false;
    public $firstname = 'Ivan';
    public $lastname = 'Ivanov';
    public $address1 = 'Sofia 1';
    public $address2 = '';
    public $city = '-';
    public $postcode = '0000';
    public $id_country = 1;
    public $id_state = 0;
    public $phone_mobile = '+359888123';
    public $phone = '';

    public function __construct(int $id = 0) { $this->id = $id; }
    public function add(): bool { $this->id = 3; return true; }
}
final class Validate { public static function isLoadedObject($o): bool { return $o->id > 0; } }
final class Configuration { public static function get($key): int { return 1; } }
final class Customer extends stdClass {
    public $id;
    public $secure_key = 'synthetic';

    public function __construct(int $id = 0) { $this->id = $id; }
    public function isLogged(): bool { return $this->id === 42; }
    public function getAddresses(int $idLang): array { return [['id_address' => 3]]; }
    public function add(): bool { $this->id = 7; return true; }
}
$shop=calculatorFixture(['uni_proces' => 1]);
$shop['consents']=[['id'=>1,'name'=>'Zero','mandatory'=>1,'interest_scope'=>'zero_interest'],['id'=>2,'name'=>'Nonzero','mandatory'=>1,'interest_scope'=>'nonzero_interest']];
$calculator=new Calculator('2026-08-17'); $product=new ProductContext(42,[7],1000);
$cart=new CartContext([new CartLine($product,0,1,1000)],1000);
$popupOrder = clone $created;
$popupOrder->total = 1000.0;
$scheme=$calculator->availableSchemes($shop,$product,'standard')[0];
$base=['popup_offer_type'=>'standard','scheme_type'=>$scheme->type,'kop_code'=>$scheme->kopCode,'months'=>$scheme->months,'filter_id'=>$scheme->filterId,'scheme_key'=>ProductPopupSchemeList::key($scheme),'first_installment'=>0,'first_name'=>'Ivan','last_name'=>'Ivanov','address'=>'Sofia 1','phone'=>'+359888123','email'=>'ivan@example.test','glp'=>0,'required_ids'=>[],'interest_scope'=>'all'];
foreach (['product', 'cart'] as $source) {
    foreach ([0, 42] as $customerId) {
        foreach ([[], ['egn' => 'obsolete', 'phone2' => ['ignored']]] as $obsolete) {
            $ctx = new Context();
            $ctx->currency = new Currency(1);
            $ctx->cart = new Cart();
            $ctx->customer = new Customer($customerId);
            $ctx->cookie = new Cookie();
            $ctx->shop = (object) ['id' => 1, 'id_shop_group' => 1];
            $ctx->language = (object) ['id' => 1];

            $a = new MemoryAttempts();
            $s = new MemorySnapshots();
            $o = new FakeOrders($popupOrder);
            $cp = new FakeCp();
            $cp->queue[] = ['data' => ['id' => 901]];
            $flow = new OrderOrchestrator($a, $s, $o, $cp, new FinancingSnapshotFactory(), new ControlPanelOrderPayloadBuilder());
            $productApply = new ProductPopupApplyService($calculator, new ProductPopupCustomerValidator(), new GuestCustomerFactory(), $flow);
            $cartApply = new CartPopupApplyService($calculator, new CartPopupCalculator($calculator, new CartSchemeResolver($calculator)), new ProductPopupCustomerValidator(), new GuestCustomerFactory(), $flow);
            $run = static function (array $posted) use ($source, $shop, $productApply, $cartApply, $product, $cart, $ctx) {
                if ($source === 'product') {
                    return $productApply->apply($shop, $posted, $product, 42, 0, 1, $ctx);
                }

                return $cartApply->apply($shop, $posted, $cart, $ctx);
            };
            try {
                $run($base + ['consent' => [1, true, [2], 999]]);
                assertOrder(false, $source . ' accepted hidden/complex IDs');
            } catch (ProductPopupValidationException $e) {
                assertOrder(isset($e->errors()['consents']), $source . ' must reject before mutation');
            }
            assertOrder($a->rows === [] && $cp->calls === [] && $ctx->customer->id === $customerId, 'invalid consents caused mutation');

            $result = $run($base + ['consent' => [2]] + $obsolete);
            assertOrder($result->state === OrderOrchestrator::CP_CREATED, $source . ' Process 2 failed');
            assertOrder($ctx->customer->id === ($customerId ?: 7), $source . ' customer identity changed unexpectedly');
            assertOrder($o->created === 1 && count($cp->calls) === 1, $source . ' expected one order and CP create');
            assertOrder($s->rows[1]['sensitive_payload'] === null, $source . ' persisted legacy fields');
            assertOrder(!array_key_exists('egn', $s->rows[1]['customer_json']) && !array_key_exists('phone2', $s->rows[1]['customer_json']), $source . ' leaked legacy fields into customer JSON');
        }
    }
}
echo "OK product/cart Process 2 submit and consent handling with synthetic guest and authenticated customers\n";
