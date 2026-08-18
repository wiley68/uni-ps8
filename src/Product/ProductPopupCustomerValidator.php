<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupCustomerValidator
{
    /** @param array<string, mixed> $input @return array<string, string> */
    public function validate(array $input): array
    {
        $customer = [
            'first_name' => $this->text($input['first_name'] ?? ''),
            'last_name' => $this->text($input['last_name'] ?? ''),
            'address' => $this->text($input['address'] ?? ''),
            'phone' => $this->phone($input['phone'] ?? ''),
            'email' => trim((string) ($input['email'] ?? '')),
        ];
        $errors = [];
        foreach (['first_name', 'last_name', 'address'] as $field) {
            if ($customer[$field] === '') {
                $errors[$field] = 'Полето е задължително.';
            }
        }
        if ($customer['phone'] === '') {
            $errors['phone'] = 'Полето е задължително.';
        } elseif (!$this->validPhone($customer['phone'])) {
            $errors['phone'] = 'Въведете валиден телефонен номер.';
        }
        if ($customer['email'] === '') {
            $errors['email'] = 'Полето е задължително.';
        } elseif (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Въведете валиден e-mail адрес.';
        }
        if ($errors !== []) {
            throw new ProductPopupValidationException($errors);
        }

        return $customer;
    }

    public function validPhone(string $phone): bool
    {
        return $phone !== '' && (bool) preg_match('/^[-0-9+() ]+$/', $phone) && (bool) preg_match('/\d/', $phone);
    }

    /** @param mixed $value */
    private function text($value): string
    {
        return trim(strip_tags((string) $value));
    }

    /** @param mixed $value */
    private function phone($value): string
    {
        $phone = preg_replace('/[^0-9+() -]/', '', (string) $value);

        return is_string($phone) ? trim($phone) : '';
    }
}
