<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

final class SmartUcfSessionException extends \RuntimeException
{
    /** @var bool */
    private $retryable;
    /** @var int */
    private $httpCode;
    /** @var string */
    private $rawResponse;

    public function __construct(
        string $message,
        bool $retryable = false,
        string $rawResponse = '',
        int $httpCode = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
        $this->rawResponse = $rawResponse;
        $this->httpCode = $httpCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }

    public function rawResponse(): string
    {
        return $this->rawResponse;
    }
}
