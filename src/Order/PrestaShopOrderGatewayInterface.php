<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;

interface PrestaShopOrderGatewayInterface
{
    public function create(ValidatedPaymentRequest $request): CreatedOrder;

    public function load(int $idOrder): CreatedOrder;

    public function markFailed(int $idOrder): void;

    public function markAwaiting(int $idOrder): void;
}
