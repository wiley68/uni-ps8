<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertProductPopupStepTransition(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$javascript = (string) file_get_contents($root . '/views/js/product-calculator.js');
$css = (string) file_get_contents($root . '/views/css/product-calculator.css');

assertProductPopupStepTransition(strpos($javascript, 'var STEP_TRANSITION_MS = 600;') !== false, 'Creditjet hide/show("slow") duration missing');
assertProductPopupStepTransition(strpos($javascript, 'setStep(2, { animate: true })') !== false, 'Step 1 → Step 2 must animate');
assertProductPopupStepTransition(strpos($javascript, 'setStep(1, { animate: true })') !== false, 'Step 2 → Step 1 must animate');
assertProductPopupStepTransition(strpos($javascript, 'prefers-reduced-motion') !== false, 'step animation must respect reduced motion');
assertProductPopupStepTransition(strpos($css, 'unipayment-product-calculator__step--animating') !== false, 'animating step overflow contract missing');

fwrite(STDOUT, "OK (Product popup Step 1/2 transition)\n");
