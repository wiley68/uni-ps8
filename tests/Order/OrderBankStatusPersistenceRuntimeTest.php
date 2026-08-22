<?php

declare(strict_types=1);

/**
 * Runtime persistence for unipayment_order_bank_status (dev/staging bootstrap).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDOUT, "SKIP (PrestaShop config missing)\n");
    exit(0);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/TestSuiteGuard.php';

use PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard;

TestSuiteGuard::skipUnlessRuntimeIntegration('order bank status runtime persistence');

require $config;

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;

function assertBankPersist(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = Db::getInstance();
$row = $db->getRow(
    'SELECT s.`id_order`, o.`reference`, o.`id_shop`
     FROM `' . _DB_PREFIX_ . 'unipayment_financing_snapshot` s
     INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = s.`id_order`
     ORDER BY s.`id_attempt` DESC'
);
if (!is_array($row)) {
    fwrite(STDOUT, "SKIP (no financing snapshot in database)\n");
    exit(0);
}

$idOrder = (int) $row['id_order'];
$reference = (string) $row['reference'];
$idShop = (int) $row['id_shop'];
$status = BankStatus::successfulSend(false);
$repo = new OrderBankStatusRepository();

$result = $repo->updateByOrderIdentifier($idShop, $reference, $status['status_id'], $status['status_label']);
assertBankPersist(is_array($result), 'updateByOrderIdentifier must persist a financing order');
assertBankPersist((int) ($result['ps_order_id'] ?? 0) === $idOrder, 'persisted row must target the financing order');

$persisted = $repo->findByOrderId($idOrder);
assertBankPersist(is_array($persisted), 'persisted bank status must be readable by id_order');
assertBankPersist(
    (string) ($persisted['status_label'] ?? '') === $status['status_label'],
    'status_label must match successful Process 1 send label'
);

$foreign = $repo->updateByOrderIdentifier($idShop, 'NONEXISTENT999', $status['status_id'], $status['status_label']);
assertBankPersist($foreign === null, 'non-financing reference must not create a row');

fwrite(STDOUT, "OK (Order bank status runtime persistence)\n");
