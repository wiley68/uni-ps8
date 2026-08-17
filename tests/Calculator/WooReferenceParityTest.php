<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', '/tmp/');
define('MTUC_SCHEME_MONTH_MIN', 3);
define('MTUC_SCHEME_MONTH_MAX', 36);
function __($text, $domain = null) { return $text; }
function current_time($format) { return $format === 'Y-m-d' ? '2026-08-17' : gmdate($format); }

class WC_Product
{
    private $id;
    private $categories;
    public function __construct(int $id, array $categories) { $this->id = $id; $this->categories = $categories; }
    public function get_id(): int { return $this->id; }
    public function get_category_ids(): array { return $this->categories; }
}

require '/var/www/woo.avalonbg.com/wp-content/plugins/mtunicredit/includes/functions.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;

function assertParity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$shop = calculatorFixture();
$price = 1000.0;
$wooProduct = new WC_Product(42, [7, 9]);
$calculator = new Calculator('2026-08-17');
$product = new ProductContext(42, [7, 9], $price);

$wooStandard = mtuc_resolve_standard_button_offer($shop, $shop['coeff_list'], $price, $wooProduct);
$wooPromo = mtuc_resolve_promo_button_offer($shop, $shop['coeff_list'], $price, $wooProduct);
$domain = $calculator->resolvePreferredOffers($shop, $product);
foreach ([['woo' => $wooStandard, 'domain' => $domain['standard']], ['woo' => $wooPromo, 'domain' => $domain['promo']]] as $pair) {
    $actual = $pair['domain']->toArray();
    foreach (['type', 'kop_code', 'installment_count', 'monthly_installment', 'glp', 'gpr', 'total_amount', 'kimb'] as $key) {
        assertParity($pair['woo'][$key] === $actual[$key], "default parity mismatch for {$key}");
    }
}

$schema = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => schemaFiltersFixture()]]]);
$wooSchema = mtuc_resolve_standard_button_offer($schema, $schema['coeff_list'], $price, $wooProduct);
$domainSchema = $calculator->resolvePreferredOffers($schema, $product)['standard']->toArray();
foreach (['type', 'kop_code', 'installment_count', 'monthly_installment', 'glp', 'gpr', 'total_amount', 'kimb'] as $key) {
    assertParity($wooSchema[$key] === $domainSchema[$key], "schema parity mismatch for {$key}");
}

fwrite(STDOUT, "OK (Phase 5 parity with Woo reference helpers)\n");
