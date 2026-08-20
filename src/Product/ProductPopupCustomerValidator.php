<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupCustomerValidator
{
    /**
     * Validates Step 2 customer fields. When $requireEgn is true (Process 2),
     * EGN and secondary phone are required, matching Woo Process 2.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validate(array $input, bool $requireEgn = false): array
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
                $errors[$field] = 'This field is required.';
            }
        }
        if ($customer['phone'] === '') {
            $errors['phone'] = 'This field is required.';
        } elseif (!$this->validPhone($customer['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
        if ($customer['email'] === '') {
            $errors['email'] = 'This field is required.';
        } elseif (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($requireEgn) {
            $egn = preg_replace('/\D/', '', (string) ($input['egn'] ?? ''));
            $egn = is_string($egn) ? $egn : '';
            $phone2 = $this->phone($input['phone2'] ?? '');
            if ($egn === '') {
                $errors['egn'] = 'This field is required.';
            } elseif (!$this->validEgn($egn)) {
                $errors['egn'] = 'Enter a valid EGN (10 digits; the first 8 must be a YYYYMMDD date).';
            }
            if ($phone2 === '') {
                $errors['phone2'] = 'This field is required.';
            } elseif (!$this->validPhone($phone2)) {
                $errors['phone2'] = 'Enter a valid secondary phone number.';
            }
            if (!isset($errors['egn'])) {
                $customer['egn'] = $egn;
            }
            if (!isset($errors['phone2'])) {
                $customer['phone2'] = $phone2;
            }
        }
        if ($errors !== []) {
            throw new ProductPopupValidationException($errors);
        }

        return $customer;
    }

    public function validEgn(string $egn): bool
    {
        if (!preg_match('/^\d{10}$/', $egn)) {
            return false;
        }

        return checkdate((int) substr($egn, 4, 2), (int) substr($egn, 6, 2), (int) substr($egn, 0, 4));
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
