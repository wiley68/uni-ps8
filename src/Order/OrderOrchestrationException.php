<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderOrchestrationException extends \RuntimeException
{
    /** @var bool */
    private $retryable;

    /** @var int */
    private $idOrder;

    /** @var int */
    private $attemptId;

    /** @var string */
    private $state;

    /** @var bool */
    private $outcomeUnknown;

    public function __construct(
        string $message,
        bool $retryable = false,
        ?\Throwable $previous = null,
        int $idOrder = 0,
        int $attemptId = 0,
        string $state = '',
        bool $outcomeUnknown = false
    ) {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
        $this->idOrder = $idOrder;
        $this->attemptId = $attemptId;
        $this->state = $state;
        $this->outcomeUnknown = $outcomeUnknown;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function idOrder(): int
    {
        return $this->idOrder;
    }

    public function attemptId(): int
    {
        return $this->attemptId;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }

    public function isPostOrder(): bool
    {
        return $this->idOrder > 0;
    }
}
