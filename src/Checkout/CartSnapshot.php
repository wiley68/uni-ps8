<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Cart\CartContext;

final class CartSnapshot
{
    public function fingerprint(CartContext $cart, string $currencyIso): string
    {
        $lines = [];
        foreach ($cart->lines as $line) {
            $lines[] = [
                'product_id' => $line->product->productId,
                'product_attribute_id' => $line->productAttributeId,
                'quantity' => $line->quantity,
                'line_total' => number_format($line->lineTotal, 2, '.', ''),
            ];
        }
        usort($lines, static function (array $a, array $b): int {
            return [$a['product_id'], $a['product_attribute_id']] <=> [$b['product_id'], $b['product_attribute_id']];
        });
        $payload = [
            'currency' => strtoupper(trim($currencyIso)),
            'total' => number_format($cart->total, 2, '.', ''),
            'lines' => $lines,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
