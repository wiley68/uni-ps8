<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * UniCredit bank-status identifiers aligned with wiley68/uni-woo.
 */
final class BankStatus
{
    public const SENT_PROCESS1 = 'bank_sent_process1';
    public const SENT_PROCESS2 = 'bank_sent_process2';
    public const SEND_FAILED_SMARTUCF = 'bank_send_failed_smartucf';

    public const LABEL_SENT_PROCESS1 = 'Sent to bank - Process 1';
    public const LABEL_SENT_PROCESS2 = 'Sent to bank - Process 2';
    public const LABEL_SEND_FAILED_SMARTUCF = 'Bank send failed - SmartUCF';

    /** @return array{status_id: string, status_label: string} */
    public static function successfulSend(bool $process2): array
    {
        return $process2
            ? ['status_id' => self::SENT_PROCESS2, 'status_label' => self::LABEL_SENT_PROCESS2]
            : ['status_id' => self::SENT_PROCESS1, 'status_label' => self::LABEL_SENT_PROCESS1];
    }

    /** @return array{status_id: string, status_label: string} */
    public static function smartUcfFailure(): array
    {
        return [
            'status_id' => self::SEND_FAILED_SMARTUCF,
            'status_label' => self::LABEL_SEND_FAILED_SMARTUCF,
        ];
    }
}
