<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupCustomerPrefill
{
    /**
     * @param array<string, mixed> $customer
     * @param array<int, array<string, mixed>> $addresses
     * @return array{first_name:string,last_name:string,address:string,phone:string,email:string,is_logged:bool}
     */
    public function present(bool $isLogged, array $customer, array $addresses, int $deliveryAddressId = 0, int $invoiceAddressId = 0): array
    {
        $empty = ['first_name' => '', 'last_name' => '', 'address' => '', 'phone' => '', 'email' => '', 'is_logged' => false];
        if (!$isLogged) {
            return $empty;
        }

        $address = $this->selectAddress($addresses, $deliveryAddressId, $invoiceAddressId);

        return [
            'first_name' => trim((string) ($address['firstname'] ?? $customer['firstname'] ?? '')),
            'last_name' => trim((string) ($address['lastname'] ?? $customer['lastname'] ?? '')),
            'address' => $this->joinAddress($address),
            'phone' => trim((string) ($address['phone_mobile'] ?? '')) ?: trim((string) ($address['phone'] ?? '')),
            'email' => trim((string) ($customer['email'] ?? '')),
            'is_logged' => true,
        ];
    }

    /** @param array<int, array<string, mixed>> $addresses @return array<string, mixed> */
    private function selectAddress(array $addresses, int $deliveryAddressId, int $invoiceAddressId): array
    {
        foreach ([$deliveryAddressId, $invoiceAddressId] as $preferredId) {
            foreach ($addresses as $address) {
                if ($preferredId > 0 && (int) ($address['id_address'] ?? 0) === $preferredId) {
                    return $address;
                }
            }
        }

        return $addresses[0] ?? [];
    }

    /** @param array<string, mixed> $address */
    private function joinAddress(array $address): string
    {
        $parts = [];
        foreach (['address1', 'address2', 'city', 'postcode'] as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return substr(implode(', ', $parts), 0, 256);
    }
}
