<?php

declare(strict_types=1);

/**
 * AUD-005 — ShopConfigurationSnapshotValidator + pull/push cache safety.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/tests/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationSnapshotValidator;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'test-key');
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        /** @var array<string, mixed> */
        public static $values = [];

        /**
         * @param mixed $value
         */
        public static function updateValue(string $key, $value): bool
        {
            self::$values[$key] = $value;

            return true;
        }

        /** @return mixed */
        public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return self::$values[$key] ?? $default;
        }

        public static function deleteByName(string $key): bool
        {
            unset(self::$values[$key]);

            return true;
        }
    }
}

if (!class_exists('PhpEncryption', false)) {
    class PhpEncryption
    {
        public function __construct(string $key) {}

        public function encrypt(string $plaintext): string
        {
            return base64_encode($plaintext);
        }

        public function decrypt(string $ciphertext)
        {
            $decoded = base64_decode($ciphertext, true);

            return is_string($decoded) ? $decoded : false;
        }
    }
}

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static $messages = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$messages[] = $message;
        }
    }
}

function assertAud005(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function expectViolations(callable $fn, string $pathContains = '', string $code = ''): ShopConfigurationSnapshotValidationException
{
    try {
        $fn();
        assertAud005(false, 'expected validation exception');
        throw new RuntimeException('unreachable');
    } catch (ShopConfigurationSnapshotValidationException $e) {
        if ($pathContains !== '' || $code !== '') {
            $matched = false;
            foreach ($e->violations() as $v) {
                if (($pathContains === '' || strpos($v['path'], $pathContains) !== false)
                    && ($code === '' || $v['code'] === $code)
                ) {
                    $matched = true;
                    break;
                }
            }
            assertAud005($matched, "expected violation path~{$pathContains} code={$code}: " . json_encode($e->violations()));
        }

        return $e;
    }
}

final class Aud005MemoryCache implements ShopConfigurationCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];
    public $replaceCount = 0;
    public $fresh = true;

    public function getFresh(string $unicid): ?array
    {
        return $this->fresh ? ($this->rows[$unicid] ?? null) : null;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        ++$this->replaceCount;
        $this->rows[$unicid] = $shopData;

        return true;
    }

    public function delete(string $unicid): bool
    {
        unset($this->rows[$unicid]);

        return true;
    }

    public function clear(): bool
    {
        $this->rows = [];

        return true;
    }

    public function getMetadata(string $unicid): ?array
    {
        return isset($this->rows[$unicid])
            ? ['fetched_at' => 'x', 'expires_at' => 'y', 'is_fresh' => true]
            : null;
    }
}

final class Aud005Provider implements ShopConfigurationProviderInterface
{
    /** @var list<array<string, mixed>|Throwable> */
    public $responses = [];
    public $calls = 0;

    public function getShop(): array
    {
        ++$this->calls;
        $r = array_shift($this->responses);
        if ($r instanceof Throwable) {
            throw $r;
        }
        if (!is_array($r)) {
            throw new RuntimeException('empty provider queue');
        }

        return $r;
    }
}

$validator = new ShopConfigurationSnapshotValidator();
$unicid = '123e4567-e89b-12d3-a456-426614174000';

// 1 valid full snapshot
$validator->validate(unipayment_valid_shop_snapshot());

// 3 same validator instance used for pull+push paths (service wiring)
Configuration::$values = [
    'UNIPAYMENT_UNICID' => $unicid,
    'UNIPAYMENT_SECRET' => 'test-secret-key-12345678901234567890',
];
$cache = new Aud005MemoryCache();
$provider = new Aud005Provider();
$tokens = new TokenRepository();
$tokens->save('tok', 'Bearer', time() + 3600);
$service = new ShopConfigurationService(
    new ConfigurationRepository(),
    $cache,
    $provider,
    $tokens,
    $validator
);

// 1 pull accepted
$good = unipayment_valid_shop_snapshot();
$provider->responses[] = ['success' => true, 'data' => $good];
$pulled = $service->get(true);
assertAud005($pulled === $good && $cache->replaceCount === 1, '1: valid pull accepted');

// 2 push accepted
$pushed = unipayment_valid_shop_snapshot(['uni_zaglavie' => 'Title']);
assertAud005($service->replaceSnapshot($unicid, $pushed), '2: valid push accepted');
assertAud005($cache->rows[$unicid]['uni_zaglavie'] === 'Title', '2b: push stored');

// 4 missing required
expectViolations(function () use ($validator) {
    $s = unipayment_valid_shop_snapshot();
    unset($s['uni_typekop']);
    $validator->validate($s);
}, 'uni_typekop', 'required');

// 5 wrong scalar type
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot(['uni_typekop' => 'schema']));
}, 'uni_typekop', 'invalid_type');

// 6/7/8/31 partial / malformed KOP
expectViolations(function () use ($validator) {
    $s = unipayment_valid_shop_snapshot();
    unset($s['kop']['by_default']);
    $validator->validate($s);
}, 'kop.by_default', 'required');

expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'kop' => ['by_default' => ['uni_kop_default' => ''], 'by_schema' => ['filters' => []]],
    ]));
}, 'uni_kop_default', 'required');

expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'kop' => [
            'by_default' => [
                'uni_kop_default' => 'X',
                'uni_promo_meseci_znak' => 'lte',
            ],
            'by_schema' => ['filters' => []],
        ],
    ]));
}, 'uni_promo_meseci_znak', 'invalid_enum');

// 9 invalid months flag type
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot(['uni_meseci_12' => ['x']]));
}, 'uni_meseci_12', 'invalid_type');

// 10 coeff_list wrong type
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot(['coeff_list' => 'nope']));
}, 'coeff_list', 'invalid_type');

// 11 empty coeff_list accepted
$emptyListSnapshot = unipayment_valid_shop_snapshot();
$emptyListSnapshot['coeff_list'] = [];
$validator->validate($emptyListSnapshot);

// 12 valid coefficient entries (already in base)
$validator->validate(unipayment_valid_shop_snapshot());

// 13/14 malformed coefficient
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'coeff_list' => [['onlineProductCode' => 'A', 'installmentCount' => 12, 'coeff' => 'x', 'interestPercent' => 1]],
    ]));
}, 'coeff', 'invalid_numeric');

expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'coeff_list' => [['onlineProductCode' => 'A', 'installmentCount' => 99, 'coeff' => 1, 'interestPercent' => 1]],
    ]));
}, 'installmentCount', 'invalid_months');

// 15/16/17 filters
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'uni_typekop' => 1,
        'kop' => [
            'by_default' => ['uni_kop_default' => 'X'],
            'by_schema' => ['filters' => [['id' => 1]]],
        ],
    ]));
}, 'uni_kop', 'required');

expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'uni_typekop' => 1,
        'kop' => [
            'by_default' => ['uni_kop_default' => 'X'],
            'by_schema' => [
                'filters' => [[
                    'id' => 1,
                    'uni_kop' => 'K1',
                    'uni_date_from' => '2026-06-01',
                    'uni_date_to' => '2026-01-01',
                ]],
            ],
        ],
    ]));
}, 'uni_date_from', 'invalid_range');

expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'uni_typekop' => 1,
        'kop' => [
            'by_default' => ['uni_kop_default' => 'X'],
            'by_schema' => [
                'filters' => [[
                    'id' => 1,
                    'uni_kop' => 'K1',
                    'uni_price_from' => 500,
                    'uni_price_to' => 100,
                ]],
            ],
        ],
    ]));
}, 'uni_price_from', 'invalid_range');

// 19 mandatory consent malformed
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'consents' => [['id' => 1, 'name' => '', 'mandatory' => 1]],
    ]));
}, 'consents[0].name', 'required');

// 20 optional consent soft-skip
$validator->validate(unipayment_valid_shop_snapshot([
    'consents' => [
        ['id' => 1, 'name' => 'OK', 'mandatory' => 1, 'url' => 'https://example.com'],
        ['name' => '', 'mandatory' => 0],
    ],
]));

// 21 duplicate consent id
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'consents' => [
            ['id' => 5, 'name' => 'A', 'mandatory' => 1],
            ['id' => 5, 'name' => 'B', 'mandatory' => 0],
        ],
    ]));
}, 'consents[1].id', 'duplicate');

// 22–24 enums
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot(['uni_eur' => 9]));
}, 'uni_eur', 'invalid_enum');
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot(['uni_proces' => 3]));
}, 'uni_proces', 'invalid_enum');
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot(['uni_env' => 4]));
}, 'uni_env', 'invalid_enum');

// 25 Process1 missing URL
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'uni_proces' => 0,
        'uni_env' => 0,
        'uni_test_service' => '',
    ]));
}, 'uni_test_service', 'required');

// 26 Process2 does not require SmartUCF fields
$validator->validate(unipayment_valid_shop_snapshot([
    'uni_proces' => 1,
    'uni_test_service' => '',
    'uni_test_application' => '',
    'uni_user' => '',
    'uni_password' => '',
]));

// 27 hostile URL structurally OK; AUD-003 still rejects
$hostile = unipayment_valid_shop_snapshot([
    'uni_proces' => 0,
    'uni_env' => 0,
    'uni_test_service' => 'https://evil.example/suos/api/otp/',
    'uni_test_application' => 'https://evil.example/sucf-online/Request/Start',
]);
$validator->validate($hostile);
$policy = new SmartUcfEndpointPolicy();
$aud003Rejected = false;
try {
    $policy->assertTrustedServiceBase($hostile['uni_test_service']);
} catch (InvalidArgumentException $e) {
    $aud003Rejected = true;
}
assertAud005($aud003Rejected, '27: AUD-003 still rejects hostile URL');

// 28 optional banner absent
$s = unipayment_valid_shop_snapshot();
unset($s['uni_picture'], $s['uni_picturem'], $s['uni_container_txt1'], $s['uni_zaglavie']);
$validator->validate($s);

// 29 unknown future field preserved
$future = unipayment_valid_shop_snapshot(['future_cp_field' => ['nested' => true]]);
$validator->validate($future);
$cache->replace($unicid, $future);
assertAud005(($cache->rows[$unicid]['future_cp_field']['nested'] ?? false) === true, '29: unknown preserved');

// 30 min > max
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'uni_minstojnost' => 9000,
        'uni_maxstojnost' => 100,
    ]));
}, 'uni_minstojnost', 'invalid_range');

// 32 uni_typekop=1 malformed filters type
expectViolations(function () use ($validator) {
    $validator->validate(unipayment_valid_shop_snapshot([
        'uni_typekop' => 1,
        'kop' => [
            'by_default' => ['uni_kop_default' => 'X'],
            'by_schema' => ['filters' => 'bad'],
        ],
    ]));
}, 'filters', 'invalid_type');

// 36 invalid PUSH leaves cache unchanged
$before = unipayment_valid_shop_snapshot(['coeff_list' => [
    ['onlineProductCode' => 'OLD', 'installmentCount' => 12, 'coeff' => 1.1, 'interestPercent' => 1],
]]);
$cache->rows[$unicid] = $before;
$replaceBefore = $cache->replaceCount;
try {
    $service->replaceSnapshot($unicid, unipayment_valid_shop_snapshot(['coeff_list' => 'bad']));
    assertAud005(false, '36: expected push validation fail');
} catch (ShopConfigurationSnapshotValidationException $e) {
    assertAud005($cache->replaceCount === $replaceBefore, '36: no replace on invalid push');
    assertAud005($cache->rows[$unicid] === $before, '36: previous cache unchanged');
    assertAud005($e->errorCode() === 'shop_snapshot_invalid', '33: error code');
    $json = json_encode($e->responseData(), JSON_THROW_ON_ERROR);
    assertAud005(strpos($json, 'demo-secret-password') === false, '41: no password in violations');
    assertAud005(strpos($json, 'test-secret') === false, '42: no secret in violations');
}

// 37 invalid PULL leaves cache unchanged
$cache->rows[$unicid] = $before;
$replaceBefore = $cache->replaceCount;
$provider->responses[] = ['success' => true, 'data' => unipayment_valid_shop_snapshot(['uni_typekop' => 'x'])];
try {
    $service->get(true);
    assertAud005(false, '37: expected pull validation fail');
} catch (ShopConfigurationSnapshotValidationException $e) {
    assertAud005($cache->replaceCount === $replaceBefore, '37: no replace on invalid pull');
    assertAud005($cache->rows[$unicid] === $before, '37: pull kept previous');
    assertAud005($tokens->hasToken(), '37: tokens not purged on validation fail');
}

// 38 invalid first snapshot creates no cache
$cache->rows = [];
$replaceBefore = $cache->replaceCount;
$provider->responses[] = ['success' => true, 'data' => ['uni_status' => 1]];
try {
    $service->get(true);
    assertAud005(false, '38: expected fail');
} catch (ShopConfigurationSnapshotValidationException $e) {
    assertAud005($cache->replaceCount === $replaceBefore && $cache->rows === [], '38: no partial cache');
}

// 39/40 empty coeff_list replaces old non-empty
$cache->rows[$unicid] = $before;
$emptyCoeffs = unipayment_valid_shop_snapshot();
$emptyCoeffs['coeff_list'] = [];
assertAud005($service->replaceSnapshot($unicid, $emptyCoeffs), '40: empty coeff push ok');
assertAud005($cache->rows[$unicid]['coeff_list'] === [], '40: empty list replaced old coeffs');

// auth purge still works
$cache->rows[$unicid] = $before;
$tokens->save('tok2', 'Bearer', time() + 3600);
$provider->responses[] = new AuthenticationException('nope');
try {
    $service->get(true);
} catch (AuthenticationException $e) {
    assertAud005(!isset($cache->rows[$unicid]), 'auth still purges');
    assertAud005(!$tokens->hasToken(), 'auth still invalidates token');
}

// InvalidPayloadException still purges (empty data envelope)
$cache->rows[$unicid] = $before;
$tokens->save('tok3', 'Bearer', time() + 3600);
$provider->responses[] = ['success' => true, 'data' => []];
try {
    $service->get(true);
} catch (InvalidPayloadException $e) {
    assertAud005(!isset($cache->rows[$unicid]), 'empty data still purges via InvalidPayload');
}

fwrite(STDOUT, "OK (AUD-005 ShopConfigurationSnapshotValidator)\n");
