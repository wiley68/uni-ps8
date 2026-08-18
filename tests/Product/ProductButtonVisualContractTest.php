<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertProductVisualContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/product_calculator.tpl');
$css = (string) file_get_contents($root . '/views/css/product-calculator.css');
$javascript = (string) file_get_contents($root . '/views/js/product-calculator.js');
$controller = (string) file_get_contents($root . '/controllers/front/productcalculator.php');

assertProductVisualContract(strpos($template, 'margin-top: {$unipayment_button_top_spacing|intval}px') !== false, 'local top spacing must be rendered as integer px');
assertProductVisualContract(strpos($template, 'data-unipayment-logo') !== false, 'standard button must render the official logo');
assertProductVisualContract(strpos($template, '>0%</span>') !== false, 'promo button must retain the textual 0% distinction');
assertProductVisualContract(strpos($template, 'data-unipayment-preferred-price') !== false, 'server-presented preferred installment must remain in the button');
assertProductVisualContract(strpos($template, '.installment_label|escape') !== false, 'initial button label must use the server-side Woo format');
assertProductVisualContract(strpos($css, 'RobotoCondensed-Regular-Cyrillic.woff2') !== false, 'local Cyrillic WOFF2 must be registered');
assertProductVisualContract(strpos($css, '[data-unipayment-calculator] button.unipayment-product-calculator__button') !== false, 'button selectors must be module-scoped');
assertProductVisualContract(strpos($css, '@container unipayment-product-buttons') !== false, 'narrow containers must use the Woo responsive behavior');
assertProductVisualContract(strpos($javascript, 'applyVisualConfig(root, next)') !== false, 'AJAX refresh must reapply presenter visual configuration');
assertProductVisualContract(strpos($javascript, 'price.textContent = buttonInstallmentLabel(offer)') !== false, 'AJAX refresh must reuse the presenter-derived Woo label');
assertProductVisualContract(strpos($javascript, "formatAmount(offer.monthly_installment") === false, 'AJAX button label must not use locale currency formatting');
assertProductVisualContract(strpos($controller, "'calculator' => \$calculator") !== false, 'AJAX response must return the complete presenter result');
assertProductVisualContract(is_file($root . '/views/fonts/roboto-condensed/LICENSE-Roboto-Condensed.txt'), 'font license must be included');
assertProductVisualContract(is_file($root . '/views/img/product/uni_logo.svg'), 'standard logo asset must be local');
assertProductVisualContract(is_file($root . '/views/img/product/uni_logo_red.svg'), 'alternative logo asset must be local');

preg_match_all('/[^{}]*:(?:hover|focus-visible)[^{]*\{([^}]+)\}/', $css, $hoverBlocks);
assertProductVisualContract($hoverBlocks[1] !== [], 'button hover/focus rules must exist');
foreach ($hoverBlocks[1] as $hoverBlock) {
    assertProductVisualContract(!preg_match('/border(?:-color|-width|-style)?\s*:/', $hoverBlock), 'hover/focus rules must not change the button border');
}
assertProductVisualContract(strpos($css, 'background: #fff none') !== false, 'standard design color must remain intact');
assertProductVisualContract(strpos($css, 'background: var(--unipayment-red) none') !== false, 'alternative design color must remain intact');

fwrite(STDOUT, "OK (Product button visual contract)\n");
