<?php

declare(strict_types=1);

/**
 * AUD-019 — development-only CartContextFactory profiling harness.
 *
 * Usage (from module root):
 *   php tests/Performance/Aud019CartContextProfile.php
 *
 * Requires a bootstrappable PrestaShop installation (config/config.inc.php).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$moduleRoot = dirname(__DIR__, 2);
$psRoot = dirname($moduleRoot, 2);
$config = $psRoot . '/config/config.inc.php';

if (!is_file($config)) {
    fwrite(STDERR, "SKIP: PrestaShop config not found at {$config}\n");
    exit(0);
}

if (!defined('_PS_DEBUG_PROFILING_')) {
    define('_PS_DEBUG_PROFILING_', true);
}

require $config;
require $moduleRoot . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Cart\CartContextFactory;

/** @internal IDE/runtime helper for PrestaShop Db methods used in this harness */
interface Aud019DbConnection
{
    /** @return array<int, array<string, mixed>>|false|null */
    public function executeS(string $sql);

    /**
     * @param array<string, mixed> $data
     */
    public function insert(
        string $table,
        array $data,
        bool $null_values = false,
        bool $use_cache = true,
        int $type = 1,
        bool $add_prefix = true
    ): bool;
}

/** @return Aud019DbConnection */
function aud019Db()
{
    /** @var Aud019DbConnection */
    return Db::getInstance();
}

function aud019Median(array $values): float
{
    sort($values);
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }
    $mid = (int) floor($count / 2);

    return $count % 2 === 1
        ? (float) $values[$mid]
        : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
}

/** @return list<Db> */
function aud019AllDbInstances(): array
{
    $reflection = new ReflectionClass(Db::class);
    $property = $reflection->getProperty('instance');
    $property->setAccessible(true);
    $instances = $property->getValue();
    if (is_array($instances) && $instances !== []) {
        return array_values($instances);
    }

    return [Db::getInstance()];
}

function aud019ResetDbCounter(): void
{
    foreach (aud019AllDbInstances() as $db) {
        if (property_exists($db, 'count')) {
            $db->count = 0;
        }
        if (property_exists($db, 'queries')) {
            $db->queries = [];
        }
    }
}

function aud019DbQueryCount(): int
{
    return count(aud019DbQueries());
}

/** @return list<array<string, mixed>> */
function aud019DbQueries(): array
{
    $queries = [];
    foreach (aud019AllDbInstances() as $db) {
        if (property_exists($db, 'queries') && is_array($db->queries)) {
            foreach ($db->queries as $entry) {
                $queries[] = $entry;
            }
        }
    }

    return $queries;
}

function aud019EnsureContext(): Context
{
    $context = Context::getContext();
    if (!$context->shop || !(int) $context->shop->id) {
        $context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
    }
    if (!$context->language || !(int) $context->language->id) {
        $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
    }
    if (!$context->currency || !(int) $context->currency->id) {
        $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
    }
    $context->country = $context->country instanceof Country && (int) $context->country->id
        ? $context->country
        : new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));

    return $context;
}

/** @return list<int> */
function aud019DistinctProductIds(int $limit): array
{
    $context = aud019EnsureContext();
    $idShop = (int) $context->shop->id;
    $rows = aud019Db()->executeS(
        'SELECT DISTINCT ps.`id_product`
         FROM `' . _DB_PREFIX_ . 'product_shop` ps
         INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = ps.`id_product`
         WHERE ps.`id_shop` = ' . $idShop . '
           AND ps.`active` = 1
           AND ps.`available_for_order` = 1
         ORDER BY ps.`id_product` ASC
         LIMIT ' . (int) $limit
    );
    if (!is_array($rows)) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) $row['id_product'];
    }

    return $ids;
}

function aud019BuildCart(array $productIds): Cart
{
    $context = aud019EnsureContext();
    $cart = new Cart();
    $cart->id_lang = (int) $context->language->id;
    $cart->id_shop = (int) $context->shop->id;
    $cart->id_shop_group = (int) $context->shop->id_shop_group;
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_customer = 0;
    $cart->id_guest = 0;
    $cart->secure_key = md5(uniqid((string) mt_rand(), true));
    $cart->add();

    $idShop = (int) $context->shop->id;
    $now = date('Y-m-d H:i:s');
    foreach ($productIds as $productId) {
        aud019Db()->insert('cart_product', [
            'id_product' => (int) $productId,
            'id_product_attribute' => 0,
            'id_cart' => (int) $cart->id,
            'id_address_delivery' => 0,
            'id_shop' => $idShop,
            'quantity' => 1,
            'date_add' => $now,
            'id_customization' => 0,
        ]);
    }

    $context->cart = $cart;

    return $cart;
}

/**
 * @return array{ms: float, queries: int}
 */
function aud019MeasureCreate(Cart $cart, CartContextFactory $factory, bool $warmCache): array
{
    if (!$warmCache) {
        Cache::clean('*');
    }

    aud019ResetDbCounter();
    $start = microtime(true);
    $factory->create($cart);
    $elapsedMs = (microtime(true) - $start) * 1000;

    return [
        'ms' => $elapsedMs,
        'queries' => aud019DbQueryCount(),
    ];
}

/**
 * @return array{ms: float, queries: int}
 */
function aud019MeasureCheckout(Cart $cart, CartContextFactory $factory, bool $warmCache): array
{
    aud019EnsureContext()->cart = $cart;

    if (!$warmCache) {
        Cache::clean('*');
    }

    aud019ResetDbCounter();
    $start = microtime(true);
    $factory->createForCheckout($cart);
    $elapsedMs = (microtime(true) - $start) * 1000;

    return [
        'ms' => $elapsedMs,
        'queries' => aud019DbQueryCount(),
    ];
}

/**
 * @return array{ms: float, queries: int}
 */
function aud019MeasureCreateContextOnly(Cart $cart, CartContextFactory $factory, bool $warmCache): array
{
    if (!$warmCache) {
        Cache::clean('*');
    }

    $products = $cart->getProducts(true);
    $total = 0.0;
    foreach (is_array($products) ? $products : [] as $row) {
        $total += (float) ($row['total_wt'] ?? 0);
    }
    $total = round($total, 2);

    $reflection = new ReflectionClass(CartContextFactory::class);
    $method = $reflection->getMethod('createContext');
    $method->setAccessible(true);

    aud019ResetDbCounter();
    $start = microtime(true);
    $method->invoke($factory, is_array($products) ? $products : [], $total);
    $elapsedMs = (microtime(true) - $start) * 1000;

    return [
        'ms' => $elapsedMs,
        'queries' => aud019DbQueryCount(),
    ];
}

/**
 * @return array{ms: float, queries: int}
 */
function aud019MeasureGetProducts(Cart $cart, bool $refresh, bool $warmCache): array
{
    if (!$warmCache) {
        Cache::clean('*');
    }

    aud019ResetDbCounter();
    $start = microtime(true);
    $cart->getProducts($refresh);
    $elapsedMs = (microtime(true) - $start) * 1000;

    return [
        'ms' => $elapsedMs,
        'queries' => aud019DbQueryCount(),
    ];
}

/** @return array{create: array{ms: float, queries: int}, createContext: array{ms: float, queries: int}, checkout: array{ms: float, queries: int}, getProducts: array{ms: float, queries: int}} */
function aud019MeasureLineCount(int $lineCount, int $repetitions, bool $warmOnly): array
{
    $productPool = aud019DistinctProductIds(max(50, $lineCount));
    if (count($productPool) < $lineCount) {
        throw new RuntimeException(
            'Not enough distinct active products in catalog (need ' . $lineCount . ', have ' . count($productPool) . ')'
        );
    }

    $productIds = array_slice($productPool, 0, $lineCount);
    $cart = aud019BuildCart($productIds);
    $factory = new CartContextFactory();

    $createMs = [];
    $createQueries = [];
    $createContextMs = [];
    $createContextQueries = [];
    $checkoutMs = [];
    $checkoutQueries = [];
    $getProductsMs = [];
    $getProductsQueries = [];

    for ($i = 0; $i < $repetitions; ++$i) {
        $warm = $warmOnly || $i > 0;
        $getProducts = aud019MeasureGetProducts($cart, true, !$warm);
        $getProductsMs[] = $getProducts['ms'];
        $getProductsQueries[] = $getProducts['queries'];

        $createContext = aud019MeasureCreateContextOnly($cart, $factory, $warm);
        $createContextMs[] = $createContext['ms'];
        $createContextQueries[] = $createContext['queries'];

        $create = aud019MeasureCreate($cart, $factory, $warm);
        $createMs[] = $create['ms'];
        $createQueries[] = $create['queries'];

        $checkout = aud019MeasureCheckout($cart, $factory, $warm);
        $checkoutMs[] = $checkout['ms'];
        $checkoutQueries[] = $checkout['queries'];
    }

    $cart->delete();

    return [
        'getProducts' => [
            'ms' => aud019Median($getProductsMs),
            'queries' => (int) aud019Median(array_map('floatval', $getProductsQueries)),
        ],
        'createContext' => [
            'ms' => aud019Median($createContextMs),
            'queries' => (int) aud019Median(array_map('floatval', $createContextQueries)),
        ],
        'create' => [
            'ms' => aud019Median($createMs),
            'queries' => (int) aud019Median(array_map('floatval', $createQueries)),
        ],
        'checkout' => [
            'ms' => aud019Median($checkoutMs),
            'queries' => (int) aud019Median(array_map('floatval', $checkoutQueries)),
        ],
    ];
}

// One-time inspection of getProducts(true) row fields relevant to CartContextFactory.
$inspectIds = aud019DistinctProductIds(1);
if ($inspectIds !== []) {
    $inspectCart = aud019BuildCart($inspectIds);
    $inspectRows = $inspectCart->getProducts(true);
    $inspectRow = is_array($inspectRows) && isset($inspectRows[0]) ? $inspectRows[0] : [];
    $relevantKeys = [
        'id_product',
        'id_product_attribute',
        'cart_quantity',
        'active',
        'available_for_order',
        'quantity_available',
        'quantity',
        'id_category_default',
    ];
    fwrite(STDOUT, "getProducts(true) relevant fields sample:\n");
    foreach ($relevantKeys as $key) {
        $value = array_key_exists($key, $inspectRow) ? $inspectRow[$key] : '(missing)';
        fwrite(STDOUT, "  {$key}: " . (is_scalar($value) ? (string) $value : json_encode($value)) . "\n");
    }
    $inspectCart->delete();
    fwrite(STDOUT, "\n");
}

$lineCounts = [1, 5, 10];
$maxProducts = count(aud019DistinctProductIds(50));
if ($maxProducts >= 19) {
    $lineCounts[] = min(25, $maxProducts);
}
if ($maxProducts >= 50) {
    $lineCounts[] = 50;
}
$lineCounts = array_values(array_unique($lineCounts));
sort($lineCounts);

$repetitions = 5;
$results = [];

fwrite(STDOUT, "AUD-019 CartContextFactory profile (median of {$repetitions} runs; run 1 cold cache, runs 2+ warm)\n");
fwrite(STDOUT, 'Shop: ' . (int) Context::getContext()->shop->id . ', lang: ' . (int) Context::getContext()->language->id . "\n");
fwrite(STDOUT, 'Profiling Db counter: ' . (property_exists(aud019Db(), 'count') ? 'yes' : 'no') . "\n\n");

foreach ($lineCounts as $lines) {
    try {
        $cold = aud019MeasureLineCount($lines, 1, false);
        Cache::clean('*');
        $warm = aud019MeasureLineCount($lines, $repetitions, true);
        $results[$lines] = [
            'cold' => $cold,
            'warm' => $warm,
        ];
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "WARN lines={$lines}: " . $exception->getMessage() . "\n");
    }
}

fwrite(STDOUT, "Lines | createCtx q (cold/warm) | createCtx ms (cold/warm) | create q (cold/warm) | create ms (cold/warm) | checkout q (cold/warm) | checkout ms (cold/warm)\n");
foreach ($results as $lines => $row) {
    fwrite(STDOUT, sprintf(
        "%5d | %4d / %4d             | %6.1f / %6.1f          | %4d / %4d          | %6.1f / %6.1f       | %4d / %4d            | %7.1f / %7.1f\n",
        $lines,
        $row['cold']['createContext']['queries'],
        $row['warm']['createContext']['queries'],
        $row['cold']['createContext']['ms'],
        $row['warm']['createContext']['ms'],
        $row['cold']['create']['queries'],
        $row['warm']['create']['queries'],
        $row['cold']['create']['ms'],
        $row['warm']['create']['ms'],
        $row['cold']['checkout']['queries'],
        $row['warm']['checkout']['queries'],
        $row['cold']['checkout']['ms'],
        $row['warm']['checkout']['ms']
    ));
}
fwrite(STDOUT, "\nWarm getProducts(true) median queries/ms by line count:\n");
foreach ($results as $lines => $row) {
    fwrite(STDOUT, sprintf(
        "  %2d lines: %d queries, %.1f ms\n",
        $lines,
        $row['warm']['getProducts']['queries'],
        $row['warm']['getProducts']['ms']
    ));
}

// Attribution sample on warm cart near the largest measured size.
$attributionLines = max($lineCounts);
if ($attributionLines > 0) {
    $productPool = aud019DistinctProductIds($attributionLines);
    $cart = aud019BuildCart(array_slice($productPool, 0, $attributionLines));
    aud019ResetDbCounter();
    $factory = new CartContextFactory();
    $reflection = new ReflectionClass(CartContextFactory::class);
    $method = $reflection->getMethod('createContext');
    $method->setAccessible(true);
    $products = $cart->getProducts(true);
    $total = 0.0;
    foreach (is_array($products) ? $products : [] as $row) {
        $total += (float) ($row['total_wt'] ?? 0);
    }
    $method->invoke($factory, is_array($products) ? $products : [], round($total, 2));
    $db = aud019Db();
    $categoryQueries = 0;
    $productLoadQueries = 0;
    $stockQueries = 0;
    foreach (aud019DbQueries() as $entry) {
        $sql = strtolower((string) ($entry['query'] ?? ''));
        if (strpos($sql, 'category_product') !== false) {
            ++$categoryQueries;
        }
        if (preg_match('/from\s+[`"]?' . preg_quote(_DB_PREFIX_, '/') . 'product[`"]?\s/u', $sql)) {
            ++$productLoadQueries;
        }
        if (strpos($sql, 'stock_available') !== false) {
            ++$stockQueries;
        }
    }
    fwrite(STDOUT, "\nWarm {$attributionLines}-line createContext() query attribution (approximate):\n");
    fwrite(STDOUT, "  category_product queries: {$categoryQueries}\n");
    fwrite(STDOUT, "  product table SELECT queries: {$productLoadQueries}\n");
    fwrite(STDOUT, "  stock_available queries: {$stockQueries}\n");
    fwrite(STDOUT, '  total queries: ' . aud019DbQueryCount() . "\n");
    $cart->delete();
}

fwrite(STDOUT, "\nOK (AUD-019 profiling harness)\n");
