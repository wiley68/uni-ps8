<?php

declare(strict_types=1);

/**
 * Operation-level idempotency for silent Product Buy cart mutation.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\ProductPopupCheckoutPreselectionException;
use PrestaShop\Module\Unipayment\Product\ProductPopupPreselectOperationGuard;

function assertPreselectGuard(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function operationToken(string $suffix = ''): string
{
    return hash('sha256', 'preselect-guard-test' . $suffix);
}

$guard = new ProductPopupPreselectOperationGuard();

try {
    $guard->validateOperationToken('');
    assertPreselectGuard(false, 'empty token must be rejected');
} catch (ProductPopupCheckoutPreselectionException $exception) {
    assertPreselectGuard(true, 'empty token rejected');
}

$guard->validateOperationToken(operationToken('valid'));

$appliedT1 = [
    'token' => operationToken('t1'),
    'cart_id' => 100,
    'product_id' => 42,
    'product_attribute_id' => 7,
    'line_qty_after' => 2,
];

// Test A — first mutation would not skip
assertPreselectGuard(
    !$guard->shouldSkipCartMutation(null, operationToken('t1'), 100, 42, 7, 0),
    'Test A: first operation must mutate cart'
);

// Test B — exact retry of same operation
assertPreselectGuard(
    $guard->shouldSkipCartMutation($appliedT1, operationToken('t1'), 100, 42, 7, 2),
    'Test B: same operation token retry must skip duplicate mutation'
);
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedT1, operationToken('t1'), 100, 42, 7, 1),
    'Test B: retry must not skip when cart quantity dropped below applied snapshot'
);

// Test C — remove product then NEW Buy with same selection
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedT1, operationToken('t2'), 100, 42, 7, 0),
    'Test C: new operation token must mutate cart after product removal'
);

// Test D — same cart + same scheme + new action (different token, not months)
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedT1, operationToken('t3'), 100, 42, 7, 0),
    'Test D: new intentional Buy must not rely on scheme hash changes'
);

// Test E — new cart id resets binding
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedT1, operationToken('t1'), 101, 42, 7, 0),
    'Test E: applied marker must be scoped to cart id'
);

// Test F — pre-existing quantity + retry + new Buy
$appliedExisting = [
    'token' => operationToken('existing-t1'),
    'cart_id' => 100,
    'product_id' => 42,
    'product_attribute_id' => 7,
    'line_qty_after' => 3,
];
assertPreselectGuard(
    $guard->shouldSkipCartMutation($appliedExisting, operationToken('existing-t1'), 100, 42, 7, 3),
    'Test F: retry after 2 + Buy 1 must remain at 3'
);
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedExisting, operationToken('existing-t2'), 100, 42, 7, 3),
    'Test F: new Buy operation must be allowed after successful prior Buy'
);

// Test G — combination attribute binding
$appliedCombination = [
    'token' => operationToken('combo-t1'),
    'cart_id' => 100,
    'product_id' => 42,
    'product_attribute_id' => 9,
    'line_qty_after' => 1,
];
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedCombination, operationToken('combo-t1'), 100, 42, 7, 1),
    'Test G: applied marker must be scoped to product attribute id'
);

$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (is_file($config)) {
    require $config;

    $cookie = new Cookie('ps-preselect-guard-' . bin2hex(random_bytes(3)));
    $guard->persistApplied($cookie, operationToken('cookie-t1'), 200, 55, 0, 4);
    $loaded = $guard->readApplied($cookie);
    assertPreselectGuard(is_array($loaded), 'applied marker must roundtrip through cookie');
    assertPreselectGuard((string) $loaded['token'] === operationToken('cookie-t1'), 'Test J: cookie marker must preserve operation token');
    assertPreselectGuard((int) $loaded['line_qty_after'] === 4, 'Test J: cookie marker must preserve post-mutation quantity');

    $cookie->{ProductPopupPreselectOperationGuard::LEGACY_MUTATION_COOKIE} = 'stale-marker';
    $guard->clearLegacyMarker($cookie);
    assertPreselectGuard(
        !isset($cookie->{ProductPopupPreselectOperationGuard::LEGACY_MUTATION_COOKIE}),
        'legacy mutation marker must be cleared safely'
    );
}

fwrite(STDOUT, "OK (Product popup preselect operation guard)\n");
