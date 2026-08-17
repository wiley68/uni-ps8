<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api\Exception;

final class ModuleApiException extends \RuntimeException
{
    /** @var int */
    private $statusCode;

    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
