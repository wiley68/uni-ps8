<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

class ModuleFrontController
{
    public $context;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/controllers/front/productpopup.php';

use PrestaShop\Module\Unipayment\Order\OrderCurrencyGuard;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;

function assertPopupReplay(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$controller = (new ReflectionClass(UnipaymentProductPopupModuleFrontController::class))->newInstanceWithoutConstructor();
$controller->context = (object) [
    'shop' => (object) ['id' => 1],
    'currency' => (object) ['iso_code' => 'EUR'],
];
$boundary = new ReflectionMethod($controller, 'assertExistingOrderReplayProvenance');
$boundary->setAccessible(true);
$row = ['id_attempt' => 7, 'id_order' => 55, 'control_panel_order_id' => 901];
$attempt = [
    'id_attempt' => 7,
    'id_shop' => 1,
    'id_order' => 55,
    'state' => OrderOrchestrator::CP_CREATED,
    'cp_payload' => json_encode(['order_id' => 'REF55', 'currency' => 'EUR']),
];
$snapshot = ['id_order' => 55, 'id_currency' => 1, 'currency_iso' => 'EUR'];
$eurGuard = new OrderCurrencyGuard(static function (int $idOrder): array {
    return ['id_currency' => 1, 'currency_iso' => 'EUR'];
});
$bgnGuard = new OrderCurrencyGuard(static function (int $idOrder): array {
    return ['id_currency' => 2, 'currency_iso' => 'BGN'];
});

$boundary->invoke($controller, $row, $attempt, $snapshot, $eurGuard);

$cases = [
    'old BGN order in EUR browser context' => [$attempt, $snapshot, $bgnGuard],
    'null CP payload' => [array_replace($attempt, ['cp_payload' => null]), $snapshot, $eurGuard],
    'empty CP payload' => [array_replace($attempt, ['cp_payload' => '']), $snapshot, $eurGuard],
    'malformed CP payload' => [array_replace($attempt, ['cp_payload' => '{']), $snapshot, $eurGuard],
    'missing CP currency' => [array_replace($attempt, ['cp_payload' => '{}']), $snapshot, $eurGuard],
    'BGN CP currency' => [array_replace($attempt, ['cp_payload' => '{"currency":"BGN"}']), $snapshot, $eurGuard],
    'snapshot currency mismatch' => [$attempt, array_replace($snapshot, ['currency_iso' => 'BGN']), $eurGuard],
];
foreach ($cases as $label => [$candidateAttempt, $candidateSnapshot, $guard]) {
    $attemptBefore = $candidateAttempt;
    $snapshotBefore = $candidateSnapshot;
    try {
        $boundary->invoke($controller, $row, $candidateAttempt, $candidateSnapshot, $guard);
        assertPopupReplay(false, "{$label} reached successful replay");
    } catch (RuntimeException $exception) {
        assertPopupReplay($candidateAttempt === $attemptBefore && $candidateSnapshot === $snapshotBefore, "{$label} mutated persisted data");
    }
}

$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/productpopup.php');
$replayStart = strpos($controllerSource, 'private function existingOrderResponse(');
$proofCall = strpos($controllerSource, '$this->assertExistingOrderCurrency($row);', $replayStart);
$successResponse = strpos($controllerSource, "'success' => true,", $replayStart);
assertPopupReplay($replayStart !== false && $proofCall !== false && $successResponse !== false && $proofCall < $successResponse, 'controller replay proof must precede success response');

fwrite(STDOUT, "OK (product popup completed replay EUR boundary)\n");
