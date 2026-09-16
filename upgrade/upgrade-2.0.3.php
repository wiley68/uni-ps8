<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade UniPayment 2.0.2 → 2.0.3.
 *
 * - Creates the durable orphan-report sync table when absent.
 * - Registers actionCronJob for bounded orphan-report recovery.
 * Safe for existing 2.0.2 installations (CREATE TABLE IF NOT EXISTS + idempotent hook register).
 *
 * @param Unipayment $module
 *
 * @return bool
 */
function upgrade_module_2_0_3($module)
{
    $repository = new PrestaShop\Module\Unipayment\Order\OrphanSyncRepository();
    if (!$repository->install()) {
        return false;
    }

    if (!$module->registerHook('actionCronJob')) {
        return false;
    }

    return true;
}
