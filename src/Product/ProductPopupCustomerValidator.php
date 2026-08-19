<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupCustomerValidator
{
    /**
     * Validates Step 2 customer fields. When $requireEgn is true (Process 2 / direct apply),
     * EGN is required and validated as a 10-digit Bulgarian personal identifier.
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
        if ($requireEgn) {
            $egn = preg_replace('/\D/', '', (string) ($input['egn'] ?? ''));
            $egn = is_string($egn) ? $egn : '';
            if ($egn === '') {
                $errors['egn'] = 'Полето е задължително.';
            } elseif (!$this->validEgn($egn)) {
                $errors['egn'] = 'Въведете валидно ЕГН (10 цифри).';
            }
            if (!isset($errors['egn'])) {
                $customer['egn'] = $egn;
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
        $month = (int) substr($egn, 2, 2);
        $day = (int) substr($egn, 4, 2);
        $year = (int) substr($egn, 0, 2);
        if ($month > 40) {
            $month -= 40;
            $year += 2000;
        } elseif ($month > 20) {
            $month -= 20;
            $year += 1800;
        } else {
            $year += 1900;
        }

        return checkdate($month, $day, $year);
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
