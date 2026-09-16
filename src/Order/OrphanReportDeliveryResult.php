<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Classification of an orphan-report delivery attempt.
 */
final class OrphanReportDeliveryResult
{
    public const TERMINAL = 'terminal';
    public const RETRYABLE = 'retryable';

    /** @var string */
    private $kind;
    /** @var string */
    private $result;
    /** @var string */
    private $errorClass;

    public function __construct(string $kind, string $result, string $errorClass = '')
    {
        $this->kind = $kind;
        $this->result = $result;
        $this->errorClass = $errorClass;
    }

    public function isTerminal(): bool
    {
        return $this->kind === self::TERMINAL;
    }

    public function result(): string
    {
        return $this->result;
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }
}
