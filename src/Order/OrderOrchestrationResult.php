<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderOrchestrationResult
{
    public $attemptId;
    public $state;
    public $idOrder;
    public $orderReference;
    public $controlPanelOrderId;

    public function __construct(int $attemptId, string $state, int $idOrder, string $orderReference, int $controlPanelOrderId = 0)
    {
        $this->attemptId = $attemptId; $this->state = $state; $this->idOrder = $idOrder;
        $this->orderReference = $orderReference; $this->controlPanelOrderId = $controlPanelOrderId;
    }
}
