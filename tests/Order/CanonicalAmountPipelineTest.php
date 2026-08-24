<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('_NEW_COOKIE_KEY_', 'canonical-amount-test-key');
final class PhpEncryption {
    public function __construct(string $key) {}
    public function encrypt(string $value): string { return base64_encode($value); }
    public function decrypt(string $value) { return base64_decode($value, true); }
}
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
function assertCanonical(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$factorySource = (string) file_get_contents($root . '/src/Cart/CartContextFactory.php');
assertCanonical(substr_count($factorySource, 'payableTotal($cart)') === 2, 'cart and checkout do not share payable total resolver');
assertCanonical(strpos($factorySource, 'getOrderTotal(true, \\Cart::BOTH)') !== false, 'tax-inclusive PrestaShop total is not used');
assertCanonical(strpos((string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php'), 'neutralizeShipping') === false, 'cart popup still strips shipping');
$calculator = new Calculator('2026-08-24');
$resolver = new CartSchemeResolver($calculator);
$shop = calculatorFixture(['uni_eur' => 0]);
$cases = ['products only' => 1000.00, 'products + shipping' => 1050.00, 'products + taxable shipping' => 1060.00, 'products + fees' => 1025.00, 'discounts' => 900.00, 'taxes' => 1200.00, 'mixed adjustments' => 1137.50, 'final checkout total' => 987.65];
foreach ($cases as $label => $total) {
    $cart = new CartContext([new CartLine(new ProductContext(42, [7], $total), 0, 1, 800.0)], $total);
    $scheme = $resolver->resolve($shop, $cart)->standardSchemes[0] ?? null;
    assertCanonical($scheme !== null && $calculator->isAvailableForAmount($shop, $total), "{$label}: eligibility diverged");
    $calculation = $calculator->calculateScheme($shop, $total, $scheme, 0.0);
    $request = new ValidatedPaymentRequest($calculation, [], [], hash('sha256', $label));
    $order = new CreatedOrder(1, 'CANONICAL001', $total, 'BGN', 1, [], [], [['id_product' => 42, 'id_product_attribute' => 0, 'name' => 'Product', 'quantity' => 1, 'total' => 800.0]]);
    $snapshot = (new FinancingSnapshotFactory(new SensitiveDataCipher()))->create($request, $order);
    $cp = (new ControlPanelOrderPayloadBuilder())->build($snapshot, $shop);
    $smart = (new SmartUcfPayloadBuilder())->build($shop, $snapshot);
    assertCanonical($calculation->price === $total, "{$label}: calculation diverged");
    assertCanonical((float) $snapshot['order_total'] === $total, "{$label}: snapshot diverged");
    assertCanonical((float) $cp['price'] === $total, "{$label}: CP payload diverged");
    assertCanonical((float) $smart['totalPrice'] === $total, "{$label}: SmartUCF payload diverged");
}
fwrite(STDOUT, "OK (canonical payable amount across eligibility, calculation, snapshot, CP and SmartUCF)\n");
