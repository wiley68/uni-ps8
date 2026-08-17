<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

use PrestaShop\Module\Unipayment\Calculator\ProductContext;

final class CartContextFactory
{
    public function create(\Cart $cart): CartContext
    {
        $products = $cart->getProducts(true);
        $total = 0.0;
        foreach (is_array($products) ? $products : [] as $row) {
            $total += (float) ($row['total_wt'] ?? 0);
        }
        $total = round($total, 2);
        $lines = [];
        foreach (is_array($products) ? $products : [] as $row) {
            $productId = (int) ($row['id_product'] ?? 0);
            $quantity = (int) ($row['cart_quantity'] ?? $row['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $product = new \Product($productId, false, (int) \Context::getContext()->language->id);
            if (!\Validate::isLoadedObject($product)
                || !$product->active
                || !$product->available_for_order
                || !$product->checkQty($quantity)
            ) {
                continue;
            }
            $lines[] = new CartLine(
                new ProductContext($productId, array_map('intval', $product->getCategories()), $total),
                (int) ($row['id_product_attribute'] ?? 0),
                $quantity,
                (float) ($row['total_wt'] ?? 0)
            );
        }

        return new CartContext($lines, $total);
    }
}
