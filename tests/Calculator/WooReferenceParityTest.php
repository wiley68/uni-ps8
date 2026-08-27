<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', '/tmp/');
define('MTUC_SCHEME_MONTH_MIN', 3);
define('MTUC_SCHEME_MONTH_MAX', 36);
function __(string $text, ?string $domain = null): string
{
    return $text;
}
function current_time(string $format): string
{
    return $format === 'Y-m-d' ? '2026-08-17' : gmdate($format);
}

class WC_Product
{
    /** @var int */
    private $id;

    /** @var list<int> */
    private $categories;

    /** @var string Woo product type (e.g. simple, variation). */
    private $type;

    /**
     * @param list<int> $categories
     */
    public function __construct(int $id, array $categories, string $type = 'simple')
    {
        $this->id = $id;
        $this->categories = $categories;
        $this->type = $type;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    /** @return list<int> */
    public function get_category_ids(): array
    {
        return $this->categories;
    }

    public function get_type(): string
    {
        return $this->type;
    }

    /**
     * @param string|list<string> $type
     */
    public function is_type($type): bool
    {
        if (is_array($type)) {
            return in_array($this->type, $type, true);
        }

        return $this->type === $type;
    }

    public function get_parent_id(): int
    {
        return 0;
    }
}

// IDE-only signatures; runtime definitions come from the Woo reference include below.
if (false) {
    /**
     * @param array<string, mixed>             $shop
     * @param array<int, array<string, mixed>> $coeff_list
     * @return array<string, mixed>|null
     */
    function mtuc_resolve_standard_button_offer(array $shop, array $coeff_list, float $price, ?WC_Product $product = null): ?array
    {
        return null;
    }

    /**
     * @param array<string, mixed>             $shop
     * @param array<int, array<string, mixed>> $coeff_list
     * @return array<string, mixed>|null
     */
    function mtuc_resolve_promo_button_offer(array $shop, array $coeff_list, float $price, ?WC_Product $product = null): ?array
    {
        return null;
    }
}

$wooIncludes = '/var/www/woo.avalonbg.com/wp-content/plugins/mtunicredit/includes';
require $wooIncludes . '/functions.php';
// Woo v2.0.2 extracted helpers (plugin bootstrap loads these after functions.php).
require $wooIncludes . '/mtuc-financing-calculator.php';
require $wooIncludes . '/mtuc-product-offer-selection.php';
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

$stubSimple = new WC_Product(1, [], 'simple');
assertParity($stubSimple->is_type('simple'), 'WC_Product stub: simple product is_type(simple)');
assertParity(!$stubSimple->is_type('variable'), 'WC_Product stub: simple product is not variable');
assertParity($stubSimple->is_type(['simple', 'variable']), 'WC_Product stub: simple product is_type([simple, variable])');

$wooProduct = new WC_Product(42, [7, 9], 'simple');
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
