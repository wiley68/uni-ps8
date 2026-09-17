<?php

declare(strict_types=1);

/**
 * When PrestaShop multishop is inactive, Configuration rows are always global
 * (id_shop NULL). Credential reads for the FO shop id must still hydrate.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\ConfigurationSmartUcfCredentialSettingStore;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository;

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'test-key-for-smartucf-credentials-ms');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!function_exists('pSQL')) {
    function pSQL($string, $htmlOK = false)
    {
        unset($htmlOK);

        return addslashes((string) $string);
    }
}

if (!class_exists('PhpEncryption', false)) {
    final class PhpEncryption
    {
        /** @var string */
        private $key;

        public function __construct(string $key)
        {
            $this->key = $key;
        }

        public function encrypt(string $plaintext): string
        {
            return base64_encode($this->key . '|' . $plaintext);
        }

        public function decrypt(string $ciphertext)
        {
            $decoded = base64_decode($ciphertext, true);
            if ($decoded === false || strpos($decoded, $this->key . '|') !== 0) {
                return false;
            }

            return substr($decoded, strlen($this->key) + 1);
        }
    }
}

function assertCredMs(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class CredMsFakeDb
{
    /** @var list<array{name: string, value: string, id_shop: ?int, id_shop_group: ?int}> */
    public $rows = [];

    /** @var string */
    public $lastSql = '';

    /**
     * @return list<array<string, mixed>>|false
     */
    public function executeS(string $sql)
    {
        $this->lastSql = $sql;
        $out = [];
        foreach ($this->rows as $row) {
            $shop = $row['id_shop'];
            $group = $row['id_shop_group'];
            $isGlobal = ($shop === null || (int) $shop === 0)
                && ($group === null || (int) $group === 0);
            $wantsGlobal = (strpos($sql, 'id_shop` IS NULL') !== false || strpos($sql, '`id_shop` IS NULL') !== false);
            $wantsShop1 = (bool) preg_match('/`id_shop`\s*=\s*1\b/', $sql);

            if ($wantsGlobal && $isGlobal) {
                $out[] = ['name' => $row['name'], 'value' => $row['value']];
            } elseif ($wantsShop1 && (int) $shop === 1) {
                $out[] = ['name' => $row['name'], 'value' => $row['value']];
            }
        }

        return $out;
    }
}

$cipher = new SmartUcfCredentialCipher();
$userEnc = $cipher->encrypt('live-user');
$passEnc = $cipher->encrypt('live-pass');

$db = new CredMsFakeDb();
$db->rows = [
    [
        'name' => SmartUcfCredentialRepository::USER_KEY,
        'value' => $userEnc,
        'id_shop' => null,
        'id_shop_group' => null,
    ],
    [
        'name' => SmartUcfCredentialRepository::PASSWORD_KEY,
        'value' => $passEnc,
        'id_shop' => null,
        'id_shop_group' => null,
    ],
];

$store = new ConfigurationSmartUcfCredentialSettingStore($db);
$pair = $store->getPair(1);

assertCredMs($pair['user'] === $userEnc, 'shop-1 read finds global encrypted user when multishop inactive');
assertCredMs($pair['password'] === $passEnc, 'shop-1 read finds global encrypted password when multishop inactive');
assertCredMs(
    strpos($db->lastSql, 'id_shop` IS NULL') !== false || strpos($db->lastSql, '`id_shop` IS NULL') !== false,
    'SQL targets global Configuration row shape'
);
assertCredMs(!preg_match('/`id_shop`\s*=\s*1\b/', $db->lastSql), 'SQL must not require id_shop=1 when multishop inactive');

$repo = new SmartUcfCredentialRepository($store, $cipher, 1);
assertCredMs($repo->hasCompleteReadablePair(), 'repository decrypts complete pair for FO shop id');
$hydrated = $repo->hydrateShopSnapshot(['uni_sertificat' => 1, 'uni_proces' => 0]);
assertCredMs(($hydrated['uni_user'] ?? '') === 'live-user', 'hydrate restores uni_user');
assertCredMs(($hydrated['uni_password'] ?? '') === 'live-pass', 'hydrate restores uni_password');

$src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/ConfigurationSmartUcfCredentialSettingStore.php');
assertCredMs(strpos($src, 'isMultishopInactive') !== false, 'store documents multishop-inactive read alignment');

fwrite(STDOUT, "OK (SmartUCF credential multishop-inactive hydrate)\n");
