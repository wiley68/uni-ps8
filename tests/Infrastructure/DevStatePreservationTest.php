<?php

declare(strict_types=1);

/**
 * Verifies the safe default suite does not mutate persistent module dev state.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/TestSuiteGuard.php';
require __DIR__ . '/../Support/ModuleSchemaInventory.php';
require __DIR__ . '/../Support/DevStateFingerprint.php';

use PrestaShop\Module\Unipayment\Tests\Support\DevStateFingerprint;
use PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard;

if (!TestSuiteGuard::prestashopConfigAvailable()) {
    fwrite(STDOUT, "SKIP (dev-state preservation; PS config missing)\n");
    exit(0);
}

require TestSuiteGuard::prestashopConfigPath();

$before = DevStateFingerprint::capture(Db::getInstance());

putenv(TestSuiteGuard::ENV_SUITE . '=' . TestSuiteGuard::SUITE_SAFE);
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/run.php') . ' safe';
$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "FAIL: safe suite failed before dev-state verification\n");
    fwrite(STDERR, implode("\n", $output) . "\n");
    exit(1);
}

$after = DevStateFingerprint::capture(Db::getInstance());
DevStateFingerprint::assertPreserved($before, $after);

fwrite(STDOUT, "OK (dev module state preserved by safe suite)\n");
