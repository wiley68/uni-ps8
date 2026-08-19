<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Address;
use Configuration;
use Context;
use Country;
use Customer;
use Validate;

/**
 * Creates or reuses a PrestaShop guest Customer and Address from product popup
 * Step 2 data, enabling direct order creation for non-logged-in visitors.
 *
 * Follows the standard PrestaShop guest checkout pattern (is_guest = 1).
 */
final class GuestCustomerFactory
{
    /**
     * Ensures a guest customer and address exist for the given Step 2 data.
     * If a customer with the same email already exists, it is reused.
     *
     * @param array<string, string> $customerData Validated Step 2 fields (first_name, last_name, email, phone, address)
     * @return array{customer: Customer, address: Address}
     */
    public function ensure(array $customerData, Context $context): array
    {
        $email = trim((string) ($customerData['email'] ?? ''));
        $firstName = trim((string) ($customerData['first_name'] ?? ''));
        $lastName = trim((string) ($customerData['last_name'] ?? ''));
        $phone = trim((string) ($customerData['phone'] ?? ''));
        $addressLine = trim((string) ($customerData['address'] ?? ''));

        if ($email === '' || $firstName === '' || $lastName === '') {
            throw new \RuntimeException('The customer data is incomplete for guest account creation.');
        }

        $customer = $this->findOrCreateCustomer($email, $firstName, $lastName, $context);
        $address = $this->ensureAddress($customer, $firstName, $lastName, $phone, $addressLine, $context);

        return ['customer' => $customer, 'address' => $address];
    }

    private function findOrCreateCustomer(string $email, string $firstName, string $lastName, Context $context): Customer
    {
        $existingId = (int) Customer::customerExists($email, true);
        if ($existingId > 0) {
            $existing = new Customer($existingId);
            if (Validate::isLoadedObject($existing)) {
                return $existing;
            }
        }

        $customer = new Customer();
        $customer->firstname = substr($firstName, 0, 255);
        $customer->lastname = substr($lastName, 0, 255);
        $customer->email = substr($email, 0, 255);
        $customer->passwd = md5(time() . uniqid((string) mt_rand(), true));
        $customer->is_guest = 1;
        $customer->active = 1;
        $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
        $customer->id_lang = (int) $context->language->id;
        $customer->id_shop = (int) $context->shop->id;
        $customer->id_shop_group = (int) $context->shop->id_shop_group;

        if (!$customer->add()) {
            throw new \RuntimeException('The guest customer account could not be created.');
        }

        return $customer;
    }

    private function ensureAddress(Customer $customer, string $firstName, string $lastName, string $phone, string $addressLine, Context $context): Address
    {
        $defaultCountryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');

        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->firstname = substr($firstName, 0, 255);
        $address->lastname = substr($lastName, 0, 255);
        $address->address1 = $addressLine !== '' ? substr($addressLine, 0, 255) : '-';
        $address->phone_mobile = $phone !== '' ? substr($phone, 0, 32) : '';
        $address->city = '-';
        $address->postcode = '0000';
        $address->id_country = $defaultCountryId > 0 ? $defaultCountryId : (int) Country::getByIso('BG');
        $address->alias = 'UniCredit financing';

        if (!$address->add()) {
            throw new \RuntimeException('The guest delivery address could not be created.');
        }

        return $address;
    }
}
