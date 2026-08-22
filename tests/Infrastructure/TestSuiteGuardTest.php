<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/TestSuiteGuard.php';

use PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard;

function assertTestSuiteGuard(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$originalSuite = getenv(TestSuiteGuard::ENV_SUITE);
$originalAllow = getenv(TestSuiteGuard::ENV_ALLOW_DESTRUCTIVE);
$originalTestDb = getenv(TestSuiteGuard::ENV_TEST_DATABASE);
$originalTestDbName = getenv(TestSuiteGuard::ENV_TEST_DB_NAME);

putenv(TestSuiteGuard::ENV_SUITE . '=' . TestSuiteGuard::SUITE_SAFE);
assertTestSuiteGuard(!TestSuiteGuard::allowsRuntimeIntegration(), 'safe suite must not allow runtime integration');
assertTestSuiteGuard(!TestSuiteGuard::destructiveOptInGranted(), 'safe suite must not grant destructive opt-in');

putenv(TestSuiteGuard::ENV_SUITE . '=' . TestSuiteGuard::SUITE_RUNTIME);
assertTestSuiteGuard(TestSuiteGuard::allowsRuntimeIntegration(), 'runtime suite must allow runtime integration');

putenv(TestSuiteGuard::ENV_SUITE . '=' . TestSuiteGuard::SUITE_DESTRUCTIVE);
putenv(TestSuiteGuard::ENV_ALLOW_DESTRUCTIVE . '=');
putenv(TestSuiteGuard::ENV_TEST_DATABASE . '=');
assertTestSuiteGuard(!TestSuiteGuard::destructiveOptInGranted(), 'destructive test must reject missing opt-in flags');

putenv(TestSuiteGuard::ENV_ALLOW_DESTRUCTIVE . '=1');
putenv(TestSuiteGuard::ENV_TEST_DATABASE . '=');
assertTestSuiteGuard(!TestSuiteGuard::destructiveOptInGranted(), 'destructive test must reject missing test DB marker');

if (!defined('_DB_NAME_')) {
    define('_DB_NAME_', 'presta8');
}

putenv(TestSuiteGuard::ENV_ALLOW_DESTRUCTIVE . '=1');
putenv(TestSuiteGuard::ENV_TEST_DATABASE . '=1');
putenv(TestSuiteGuard::ENV_TEST_DB_NAME . '=');
assertTestSuiteGuard(
    !TestSuiteGuard::destructiveDatabaseAllowed(),
    'destructive guard must reject normal dev database presta8'
);
assertTestSuiteGuard(
    TestSuiteGuard::isDestructiveDatabaseNameAllowed('presta8_test'),
    'test database naming convention must allow destructive execution'
);

putenv(TestSuiteGuard::ENV_TEST_DB_NAME . '=presta8');
assertTestSuiteGuard(
    TestSuiteGuard::isDestructiveDatabaseNameAllowed('presta8'),
    'explicit UNIPAYMENT_TEST_DB_NAME must allow exact database match'
);

if ($originalSuite === false) {
    putenv(TestSuiteGuard::ENV_SUITE);
} else {
    putenv(TestSuiteGuard::ENV_SUITE . '=' . $originalSuite);
}
if ($originalAllow === false) {
    putenv(TestSuiteGuard::ENV_ALLOW_DESTRUCTIVE);
} else {
    putenv(TestSuiteGuard::ENV_ALLOW_DESTRUCTIVE . '=' . $originalAllow);
}
if ($originalTestDb === false) {
    putenv(TestSuiteGuard::ENV_TEST_DATABASE);
} else {
    putenv(TestSuiteGuard::ENV_TEST_DATABASE . '=' . $originalTestDb);
}
if ($originalTestDbName === false) {
    putenv(TestSuiteGuard::ENV_TEST_DB_NAME);
} else {
    putenv(TestSuiteGuard::ENV_TEST_DB_NAME . '=' . $originalTestDbName);
}

fwrite(STDOUT, "OK (test suite safety guard)\n");
