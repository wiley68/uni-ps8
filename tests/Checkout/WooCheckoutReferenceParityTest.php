<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', '/tmp/');
/** @param mixed $text */
function __($text, $domain = null) { return $text; }
/** @param mixed $value */
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
/** @param mixed $value */
function esc_url_raw($value) { return filter_var((string) $value, FILTER_VALIDATE_URL) ?: ''; }
/** @param mixed $value */
function absint($value) { return abs((int) $value); }
class WP_Error { /** @var mixed */ private $message; /** @param mixed $message */ public function __construct($code, $message) { $this->message = $message; } public function get_error_message() { return $this->message; } }

require '/var/www/woo.avalonbg.com/wp-content/plugins/mtunicredit/includes/functions.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;
use PrestaShop\Module\Unipayment\Checkout\SchemeSelection;

function assertWooCheckout(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$validator = new CustomerFieldValidator();
foreach (['1990010199', '1990130199', '9001011234'] as $egn) {
    assertWooCheckout(call_user_func('mtuc_validate_bulgarian_egn', $egn) === $validator->validEgn($egn), 'EGN validation parity differs');
}
foreach (['+359 888 123', 'abc', ''] as $phone) {
    assertWooCheckout(call_user_func('mtuc_validate_customer_phone', $phone) === $validator->validPhone($phone), 'phone validation parity differs');
}
$shop = ['consents' => [['id' => 2, 'name' => 'Optional', 'mandatory' => 0], ['id' => 1, 'name' => 'Required', 'mandatory' => 1]]];
/** @var array<int, array<string, mixed>> $wooConsents */
$wooConsents = call_user_func('mtuc_get_shop_consents', $shop);
$domainConsents = (new ConsentResolver())->normalize($shop);
assertWooCheckout($wooConsents[0]['id'] === $domainConsents[0]['id'] && $wooConsents[0]['mandatory'] === $domainConsents[0]['mandatory'], 'consent normalization parity differs');
assertWooCheckout(SchemeSelection::key('standard', 12, 7) === '12:7' && SchemeSelection::key('promo', 12, 7) === 'p:12:7', 'Woo scheme key parity differs');

fwrite(STDOUT, "OK (Phase 8 parity with Woo checkout validation helpers)\n");
