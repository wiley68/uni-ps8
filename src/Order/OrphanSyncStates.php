<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Durable Shop→CP orphan-report sync states (local module table).
 */
final class OrphanSyncStates
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const TERMINAL_FAILED = 'terminal_failed';

    public const EVENT_CP_CREATE_ORPHAN = 'cp_create_orphan';

    public const STATUS_ID = BankStatus::SEND_FAILED_CP;
}
