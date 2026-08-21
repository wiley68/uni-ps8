<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Product Popup submission lifecycle (AUD-002A). SmartUCF is out of scope.
 */
final class PopupSubmissionStates
{
    public const ISSUED = 'issued';
    public const PROCESSING = 'processing';
    public const ORDER_CREATED = 'order_created';
    public const FAILED = 'failed';
}
