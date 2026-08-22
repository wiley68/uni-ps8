<?php

declare(strict_types=1);

/**
 * AUD-002A: deterministic selection_hash + repository atomic claim (unit/DB).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionSelectionHash;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionStates;

function assertAud002a(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$hash = new PopupSubmissionSelectionHash();
$base = [
    'id_shop' => 1,
    'id_product' => 10,
    'id_product_attribute' => 2,
    'quantity' => 1,
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'scheme_key' => 'standard|STD|12|0',
    'first_installment' => 10.5,
    'id_guest' => 99,
    'id_customer' => 0,
];

$h1 = $hash->hash($base);
$h2 = $hash->hash($base);
assertAud002a($h1 === $h2 && strlen($h1) === 64, 'selection hash must be stable sha256');

$changedMonths = $base;
$changedMonths['months'] = 24;
assertAud002a($hash->hash($changedMonths) !== $h1, 'months change must invalidate hash');

$changedFirst = $base;
$changedFirst['first_installment'] = '10.50';
assertAud002a($hash->hash($changedFirst) === $h1, 'first installment normalization must be deterministic');

$changedProduct = $base;
$changedProduct['id_product'] = 11;
assertAud002a($hash->hash($changedProduct) !== $h1, 'product change must invalidate hash');

$delimiterTrap = $base;
$delimiterTrap['kop_code'] = 'ST|D';
assertAud002a($hash->hash($delimiterTrap) !== $h1, 'structured JSON must not treat delimiter-like values as field splits');

$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDOUT, "OK (AUD-002A hash unit; PS config missing — skipped repository)\n");
    exit(0);
}

require dirname(__DIR__) . '/Support/TestSuiteGuard.php';

use PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard;

TestSuiteGuard::skipUnlessRuntimeIntegration('AUD-002A repository integration');

require $config;

$repo = new PopupSubmissionRepository();
assertAud002a($repo->install(), 'popup submission table install');

$suffix = bin2hex(random_bytes(4));
$selectionHash = $hash->hash(array_merge($base, ['kop_code' => 'T' . $suffix]));

$issued = $repo->issueOrReuse(1, $selectionHash, 42, 0, '');
$token = (string) $issued['submission_token'];
assertAud002a(strlen($token) === 64, 'issued token length');
assertAud002a((string) $issued['state'] === PopupSubmissionStates::ISSUED, 'issued state');

$reused = $repo->issueOrReuse(1, $selectionHash, 42, 0, $token);
assertAud002a((string) $reused['submission_token'] === $token, 'same binding must reuse preferred token');

$reusedByHash = $repo->issueOrReuse(1, $selectionHash, 42, 0, '');
assertAud002a((string) $reusedByHash['submission_token'] === $token, 'same binding must reuse issued row without preferred token');

$otherSelection = $hash->hash(array_merge($base, ['kop_code' => 'X' . $suffix, 'months' => 6]));
$newTokenRow = $repo->issueOrReuse(1, $otherSelection, 42, 0, $token);
assertAud002a((string) $newTokenRow['submission_token'] !== $token, 'changed selection must issue a new token');

$winner = $repo->claimForProcessing($token);
$loser = $repo->claimForProcessing($token);
assertAud002a(is_array($winner) && (string) $winner['state'] === PopupSubmissionStates::PROCESSING, 'first claim wins');
assertAud002a($loser === null, 'second claim must not win');

$repo->attachCart((int) $winner['id_submission'], 12345);
$withCart = $repo->findByToken($token);
assertAud002a((int) $withCart['id_cart'] === 12345, 'id_cart must persist before orchestrator');

$repo->markOrderCreated((int) $winner['id_submission'], 7, 100, 'ABCDEFGHIJKLM', 55);
$done = $repo->findByToken($token);
assertAud002a((string) $done['state'] === PopupSubmissionStates::ORDER_CREATED, 'order_created state');
assertAud002a((int) $done['id_order'] === 100, 'id_order persisted');
assertAud002a((string) $done['order_reference'] === 'ABCDEFGHIJKLM', 'order_reference persisted');
assertAud002a((int) $done['control_panel_order_id'] === 55, 'cp id persisted');
assertAud002a(!$repo->isExpired($done), 'order_created must not expire for short-term replay');

$processingNoCart = $repo->issueOrReuse(1, $hash->hash(array_merge($base, ['kop_code' => 'P' . $suffix])), 42, 0, '');
$claimed = $repo->claimForProcessing((string) $processingNoCart['submission_token']);
assertAud002a(is_array($claimed), 'claim for revert fixture');
$repo->revertProcessingWithoutCart((int) $claimed['id_submission']);
$reverted = $repo->findByToken((string) $processingNoCart['submission_token']);
assertAud002a((string) $reverted['state'] === PopupSubmissionStates::ISSUED, 'processing without cart reverts to issued');

fwrite(STDOUT, "OK (AUD-002A selection hash + repository)\n");
