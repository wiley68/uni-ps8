<?php

declare(strict_types=1);

/**
 * Proves legacy selection-hash marker failure and operation-token semantics.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\ProductPopupPreselectOperationGuard;

function assertPreselectIdempotency(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * @param array<string, mixed> $calculation
 */
function legacySelectionMutationHash(
    int $cartId,
    int $productId,
    int $productAttributeId,
    int $quantity,
    array $calculation
): string {
    return hash('sha256', implode('|', [
        $cartId,
        $productId,
        $productAttributeId,
        $quantity,
        (string) ($calculation['scheme_type'] ?? ''),
        (string) ($calculation['kop_code'] ?? ''),
        (int) ($calculation['months'] ?? 0),
        (int) ($calculation['filter_id'] ?? 0),
        (string) ($calculation['first_installment'] ?? '0'),
    ]));
}

$calculation = [
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 0.0,
];

$cartId = 501;
$productId = 42;
$attributeId = 7;
$quantity = 1;
$storedLegacyMarker = legacySelectionMutationHash($cartId, $productId, $attributeId, $quantity, $calculation);

// Scenario C after successful first Buy and product removal (qty = 0, cart id unchanged).
$legacyWouldSkip = hash_equals(
    $storedLegacyMarker,
    legacySelectionMutationHash($cartId, $productId, $attributeId, $quantity, $calculation)
);
assertPreselectIdempotency($legacyWouldSkip, 'legacy marker incorrectly skips after remove + same selection Buy');

$changedMonthsCalculation = $calculation;
$changedMonthsCalculation['months'] = 24;
$legacyWouldRunAfterMonthsChange = !hash_equals(
    $storedLegacyMarker,
    legacySelectionMutationHash($cartId, $productId, $attributeId, $quantity, $changedMonthsCalculation)
);
assertPreselectIdempotency(
    $legacyWouldRunAfterMonthsChange,
    'legacy marker only unsticks when financing identity changes'
);

$guard = new ProductPopupPreselectOperationGuard();
$appliedT1 = [
    'token' => hash('sha256', 'operation-t1'),
    'cart_id' => $cartId,
    'product_id' => $productId,
    'product_attribute_id' => $attributeId,
    'line_qty_after' => 1,
];

assertPreselectIdempotency(
    !$guard->shouldSkipCartMutation($appliedT1, hash('sha256', 'operation-t2'), $cartId, $productId, $attributeId, 0),
    'operation token distinguishes new intentional Buy from stale legacy suppression'
);
assertPreselectIdempotency(
    $guard->shouldSkipCartMutation($appliedT1, hash('sha256', 'operation-t1'), $cartId, $productId, $attributeId, 1),
    'same operation token retry remains idempotent when cart still reflects mutation'
);

fwrite(STDOUT, "OK (Product popup checkout preselection idempotency regression)\n");
