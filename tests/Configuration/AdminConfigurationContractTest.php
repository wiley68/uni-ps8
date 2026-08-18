<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

function assertAdminConfiguration(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
$module = (string) file_get_contents($root . '/unipayment.php');

$actions = ['submitUnipaymentConfiguration', 'submitUnipaymentRefresh', 'submitUnipaymentDownloadJournal'];
foreach ($actions as $action) {
    assertAdminConfiguration(substr_count($template, 'name="' . $action . '"') === 1, "admin action {$action} is missing or duplicated");
}
assertAdminConfiguration(substr_count($template, '<button ') === 3, 'admin UI must contain exactly three action buttons');
foreach (['submitUnipaymentConnect', 'submitUnipaymentLogout', 'Control Panel status'] as $removed) {
    assertAdminConfiguration(strpos($template, $removed) === false && strpos($module, "Tools::isSubmit('{$removed}')") === false, "removed admin control {$removed} is still exposed");
}
foreach (['UNIPAYMENT_ADVERTISING_ENABLED', 'UNIPAYMENT_DEBUG_ENABLED', 'UNIPAYMENT_PRODUCT_BUTTON_ACTION', 'UNIPAYMENT_BUTTON_TOP_SPACING'] as $field) {
    assertAdminConfiguration(strpos($template, 'name="' . $field . '"') !== false, "admin setting {$field} is missing");
}
assertAdminConfiguration(strpos($module, '$credentialsChanged =') !== false, 'credential-change detection was removed');
assertAdminConfiguration(strpos($module, '$tokens->invalidate();') !== false, 'credential change no longer invalidates tokens');
assertAdminConfiguration(strpos($module, 'ShopConfigurationCache())->clear();') !== false, 'credential change no longer clears shop cache');

fwrite(STDOUT, "OK (admin configuration settings and action contract)\n");
