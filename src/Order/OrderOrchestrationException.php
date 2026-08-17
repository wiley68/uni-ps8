<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderOrchestrationException extends \RuntimeException
{
    private $retryable;
    public function __construct(string $message, bool $retryable = false, ?\Throwable $previous = null) { parent::__construct($message, 0, $previous); $this->retryable = $retryable; }
    public function isRetryable(): bool { return $this->retryable; }
}
