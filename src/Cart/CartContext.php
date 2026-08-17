<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

final class CartContext
{
    /** @var CartLine[] */
    public $lines;

    /** @var float */
    public $total;

    /** @param CartLine[] $lines */
    public function __construct(array $lines, float $total)
    {
        $this->lines = array_values($lines);
        $this->total = round($total, 2);
    }
}
