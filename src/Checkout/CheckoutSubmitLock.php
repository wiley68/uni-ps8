<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

/**
 * Short-lived server lock against double checkout submission (Woo mtuc_acquire_popup_submit_lock parity).
 */
final class CheckoutSubmitLock
{
    private const TTL_SECONDS = 45;
    private const PREFIX = 'UNIPAYMENT_CHECKOUT_LOCK_';

    public function acquire(int $idShop, int $idCart): bool
    {
        if ($idShop <= 0 || $idCart <= 0) {
            return false;
        }
        $key = $this->key($idShop, $idCart);
        $now = time();
        $expires = (int) \Configuration::get($key);
        if ($expires > $now) {
            return false;
        }
        \Configuration::updateValue($key, (string) ($now + self::TTL_SECONDS));

        return true;
    }

    public function release(int $idShop, int $idCart): void
    {
        if ($idShop <= 0 || $idCart <= 0) {
            return;
        }
        \Configuration::updateValue($this->key($idShop, $idCart), '0');
    }

    private function key(int $idShop, int $idCart): string
    {
        return self::PREFIX . $idShop . '_' . $idCart;
    }
}
