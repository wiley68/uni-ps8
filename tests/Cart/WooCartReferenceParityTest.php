<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', '/tmp/');
define('MTUC_SCHEME_MONTH_MIN', 3);
define('MTUC_SCHEME_MONTH_MAX', 36);

/** @param mixed $value */
function absint($value) { return abs((int) $value); }
function mtuc_sort_popup_scheme_options(array $options): array { return $options; }
function mtuc_build_popup_scheme_option_row(int $months, int $filterId, string $kop, string $desc, string $type): array {
    return ['months' => $months, 'filter_id' => $filterId, 'kop_code' => $kop, 'desc' => $desc, 'scheme_type' => $type];
}
require '/var/www/woo.avalonbg.com/wp-content/plugins/mtunicredit/includes/mtuc-cart-calculator.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Calculator\Calculator;

function assertWooCart(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function wooOption(int $filter): array { return ['scheme_type' => 'standard', 'kop_code' => 'CAT', 'months' => 12, 'filter_id' => $filter]; }
function domainScheme(int $filter): AvailableScheme { return new AvailableScheme('standard', 'CAT', 12, $filter, ['id' => $filter], ['coeff' => .09, 'interestPercent' => 10]); }

/** @var array<int, array<string, mixed>> $woo */
$woo = call_user_func('mtuc_intersect_cart_scheme_options', [[wooOption(31)], [wooOption(32)]]);
$domain = (new CartSchemeResolver(new Calculator()))->intersect([[domainScheme(31)], [domainScheme(32)]]);
assertWooCart(count($woo) === count($domain) && count($domain) === 1, 'filter identity parity differs');
assertWooCart((int) $woo[0]['filter_id'] === $domain[0]->filterId && $domain[0]->filterId === 31, 'first-line filter metadata parity differs');

// The Woo helper API itself takes cart_total as the price argument for every product line.
$reflection = new ReflectionFunction('mtuc_get_cart_line_scheme_options');
$priceParameter = $reflection->getParameters()[2];
assertWooCart($priceParameter->getName() === 'cart_total', 'Woo helper no longer declares cart_total as per-line price input');

/** @var int $wooLcm */
$wooLcm = call_user_func('mtuc_lcm_int_list', [6, 8]);
$domainLcm = (new CartSchemeResolver(new Calculator()))->lcm([6, 8]);
assertWooCart($wooLcm === $domainLcm && $domainLcm === 24, 'LCM parity differs');

fwrite(STDOUT, "OK (Phase 7 parity with Woo cart helpers)\n");
