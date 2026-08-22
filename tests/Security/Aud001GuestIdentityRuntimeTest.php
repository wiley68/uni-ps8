<?php

declare(strict_types=1);

/**
 * AUD-001 runtime integration against the PrestaShop test shop.
 *
 * Creates temporary customers, verifies GuestCustomerFactory never reuses a
 * registered account by e-mail, then deletes the temporary rows.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDERR, "FAIL: PrestaShop config not found at {$config}\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/TestSuiteGuard.php';

use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\PopupCustomerIdentityGate;
use PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard;

TestSuiteGuard::skipUnlessRuntimeIntegration('AUD-001 GuestCustomerFactory runtime');

require $config;

function assertAud001Runtime(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @internal IDE/runtime helper for PrestaShop Db methods used in this test */
interface Aud001DbConnection
{
    /** @return array<int, array<string, mixed>>|false|null */
    public function executeS(string $sql);

    public function getValue(string $sql, bool $useCache = true);
}

/** @return Aud001DbConnection */
function aud001Db()
{
    /** @var Aud001DbConnection */
    return Db::getInstance();
}

function aud001DeleteCustomer(int $idCustomer): void
{
    if ($idCustomer <= 0) {
        return;
    }
    $addresses = aud001Db()->executeS(
        'SELECT `id_address` FROM `' . _DB_PREFIX_ . 'address` WHERE `id_customer` = ' . (int) $idCustomer
    );
    if (is_array($addresses)) {
        foreach ($addresses as $row) {
            $address = new Address((int) $row['id_address']);
            if (Validate::isLoadedObject($address)) {
                $address->delete();
            }
        }
    }
    $customer = new Customer($idCustomer);
    if (Validate::isLoadedObject($customer)) {
        $customer->delete();
    }
}

$suffix = bin2hex(random_bytes(4));
$registeredEmail = 'aud001-reg-' . $suffix . '@example.test';
$guestEmail = 'aud001-guest-' . $suffix . '@example.test';
$newEmail = 'aud001-new-' . $suffix . '@example.test';
$createdIds = [];

try {
    $context = Context::getContext();
    assertAud001Runtime($context instanceof Context, 'PS Context missing');

    // --- existing registered customer must not be reused ---
    $registered = new Customer();
    $registered->firstname = 'Aud';
    $registered->lastname = 'Registered';
    $registered->email = $registeredEmail;
    $registered->passwd = md5('aud001-not-used');
    $registered->is_guest = 0;
    $registered->active = 1;
    $registered->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
    $registered->id_lang = (int) $context->language->id;
    $registered->id_shop = (int) $context->shop->id;
    $registered->id_shop_group = (int) $context->shop->id_shop_group;
    assertAud001Runtime((bool) $registered->add(), 'failed to create registered fixture customer');
    $createdIds[] = (int) $registered->id;
    $registeredId = (int) $registered->id;
    $registeredPasswd = (string) $registered->passwd;

    $factory = new GuestCustomerFactory();
    $result = $factory->ensure(
        [
            'first_name' => 'Guest',
            'last_name' => 'Visitor',
            'email' => $registeredEmail,
            'phone' => '+359888000001',
            'address' => 'Sofia test',
        ],
        $context
    );
    $guestFromRegisteredEmail = $result['customer'];
    $addressFromRegisteredEmail = $result['address'];
    $createdIds[] = (int) $guestFromRegisteredEmail->id;

    assertAud001Runtime((int) $guestFromRegisteredEmail->id !== $registeredId, 'registered customer must not be reused by e-mail');
    assertAud001Runtime((int) $guestFromRegisteredEmail->is_guest === 1, 'anonymous flow must create is_guest=1');
    assertAud001Runtime(
        (int) $guestFromRegisteredEmail->id_default_group === (int) Configuration::get('PS_GUEST_GROUP'),
        'guest must use PS_GUEST_GROUP'
    );
    assertAud001Runtime((string) $guestFromRegisteredEmail->passwd !== $registeredPasswd, 'cookie/guest passwd must not be registered hash');
    assertAud001Runtime((int) $addressFromRegisteredEmail->id_customer === (int) $guestFromRegisteredEmail->id, 'address must belong to new guest');
    assertAud001Runtime((int) $addressFromRegisteredEmail->id_customer !== $registeredId, 'address must not attach to registered account');

    $registeredReload = new Customer($registeredId);
    assertAud001Runtime(Validate::isLoadedObject($registeredReload), 'registered fixture must remain');
    assertAud001Runtime((int) $registeredReload->is_guest === 0, 'registered customer must stay non-guest');
    $registeredAddressCount = (int) aud001Db()->getValue(
        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address` WHERE `id_customer` = ' . $registeredId
    );
    assertAud001Runtime($registeredAddressCount === 0, 'registered account must receive no new address');

    // --- existing guest e-mail: still create a distinct guest (no reuse by e-mail) ---
    $existingGuest = new Customer();
    $existingGuest->firstname = 'Prior';
    $existingGuest->lastname = 'Guest';
    $existingGuest->email = $guestEmail;
    $existingGuest->passwd = md5('aud001-prior-guest');
    $existingGuest->is_guest = 1;
    $existingGuest->active = 1;
    $existingGuest->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
    $existingGuest->id_lang = (int) $context->language->id;
    $existingGuest->id_shop = (int) $context->shop->id;
    $existingGuest->id_shop_group = (int) $context->shop->id_shop_group;
    assertAud001Runtime((bool) $existingGuest->add(), 'failed to create prior guest fixture');
    $createdIds[] = (int) $existingGuest->id;
    $priorGuestId = (int) $existingGuest->id;

    $second = $factory->ensure(
        [
            'first_name' => 'Second',
            'last_name' => 'Guest',
            'email' => $guestEmail,
            'phone' => '+359888000002',
            'address' => 'Plovdiv test',
        ],
        $context
    );
    $createdIds[] = (int) $second['customer']->id;
    assertAud001Runtime((int) $second['customer']->id !== $priorGuestId, 'existing guest must not be reused by e-mail');
    assertAud001Runtime((int) $second['customer']->is_guest === 1, 'second guest must remain is_guest=1');
    assertAud001Runtime((int) $second['address']->id_customer === (int) $second['customer']->id, 'second address must belong to new guest');

    // --- brand new e-mail ---
    $fresh = $factory->ensure(
        [
            'first_name' => 'Fresh',
            'last_name' => 'Guest',
            'email' => $newEmail,
            'phone' => '+359888000003',
            'address' => 'Varna test',
        ],
        $context
    );
    $createdIds[] = (int) $fresh['customer']->id;
    assertAud001Runtime((int) $fresh['customer']->is_guest === 1, 'new e-mail guest must be is_guest=1');
    assertAud001Runtime(
        (int) $fresh['customer']->id_default_group === (int) Configuration::get('PS_GUEST_GROUP'),
        'new e-mail guest must use guest group'
    );

    // --- concurrent anonymous submissions with same e-mail ---
    $concurrentEmail = 'aud001-conc-' . $suffix . '@example.test';
    $a = $factory->ensure(
        ['first_name' => 'A', 'last_name' => 'C', 'email' => $concurrentEmail, 'phone' => '1', 'address' => 'A'],
        $context
    );
    $b = $factory->ensure(
        ['first_name' => 'B', 'last_name' => 'C', 'email' => $concurrentEmail, 'phone' => '2', 'address' => 'B'],
        $context
    );
    $createdIds[] = (int) $a['customer']->id;
    $createdIds[] = (int) $b['customer']->id;
    assertAud001Runtime((int) $a['customer']->id !== (int) $b['customer']->id, 'concurrent guests must be distinct identities');
    assertAud001Runtime((int) $a['address']->id_customer === (int) $a['customer']->id, 'concurrent A address ownership');
    assertAud001Runtime((int) $b['address']->id_customer === (int) $b['customer']->id, 'concurrent B address ownership');
    assertAud001Runtime((int) $a['customer']->is_guest === 1 && (int) $b['customer']->is_guest === 1, 'concurrent guests must stay guests');

    // --- authenticated gate semantics ---
    $gate = new PopupCustomerIdentityGate();
    $empty = new Customer();
    assertAud001Runtime($gate->shouldUseAuthenticatedCustomer($empty) === false, 'empty customer must not be authenticated');

    $guestLike = new Customer((int) $fresh['customer']->id);
    $guestLike->logged = true;
    assertAud001Runtime($gate->shouldUseAuthenticatedCustomer($guestLike) === false, 'guest customer must never pass authenticated gate');

    fwrite(STDOUT, "OK (AUD-001 GuestCustomerFactory runtime isolation)\n");
} finally {
    foreach (array_reverse($createdIds) as $id) {
        aud001DeleteCustomer((int) $id);
    }
}
