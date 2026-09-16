<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Classification of a Control Panel create-order outcome.
 */
final class ControlPanelCreateFailureClassification
{
    public const KIND_RETRYABLE = 'retryable';
    public const KIND_AMBIGUOUS = 'ambiguous';
    public const KIND_DEFINITIVE = 'definitive';

    /** @var string */
    private $kind;
    /** @var string */
    private $attemptState;
    /** @var bool */
    private $retryable;
    /** @var string */
    private $errorClass;
    /** @var bool */
    private $writeBankSendFailedCp;
    /** @var bool */
    private $createOrphanIntent;

    public function __construct(
        string $kind,
        string $attemptState,
        bool $retryable,
        string $errorClass,
        bool $writeBankSendFailedCp,
        bool $createOrphanIntent
    ) {
        $this->kind = $kind;
        $this->attemptState = $attemptState;
        $this->retryable = $retryable;
        $this->errorClass = $errorClass;
        $this->writeBankSendFailedCp = $writeBankSendFailedCp;
        $this->createOrphanIntent = $createOrphanIntent;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function attemptState(): string
    {
        return $this->attemptState;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }

    public function shouldWriteBankSendFailedCp(): bool
    {
        return $this->writeBankSendFailedCp;
    }

    public function shouldCreateOrphanIntent(): bool
    {
        return $this->createOrphanIntent;
    }

    public function isDefinitive(): bool
    {
        return $this->kind === self::KIND_DEFINITIVE;
    }
}
