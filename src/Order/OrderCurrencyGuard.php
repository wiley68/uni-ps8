<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;

/** Checks durable PrestaShop order currency against the financing snapshot. */
final class OrderCurrencyGuard
{
    /** @var callable|null */
    private $nativeOrderCurrencyReader;

    /** @param callable|null $nativeOrderCurrencyReader Test seam returning [id_currency, currency_iso]. */
    public function __construct(?callable $nativeOrderCurrencyReader = null)
    {
        $this->nativeOrderCurrencyReader = $nativeOrderCurrencyReader;
    }

    public function assertOrder(CreatedOrder $order): void
    {
        if ($order->idOrder <= 0 || $order->idCurrency <= 0
            || !(new CurrencyGate())->supports($order->currencyIso)
        ) {
            throw new \RuntimeException('The financing order currency must be EUR.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function assertMatchesSnapshot(CreatedOrder $order, array $snapshot): void
    {
        $this->assertOrder($order);
        if ((int) ($snapshot['id_order'] ?? 0) !== $order->idOrder
            || (int) ($snapshot['id_currency'] ?? 0) !== $order->idCurrency
            || (string) ($snapshot['currency_iso'] ?? '') !== 'EUR'
        ) {
            throw new \RuntimeException('The financing snapshot currency does not match the EUR order.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function assertNativeSnapshot(array $snapshot): void
    {
        $orderId = (int) ($snapshot['id_order'] ?? 0);
        if ($orderId <= 0) {
            throw new \RuntimeException('The financing order currency is unavailable.');
        }
        if ($this->nativeOrderCurrencyReader !== null) {
            $currency = call_user_func($this->nativeOrderCurrencyReader, $orderId);
        } else {
            $nativeOrder = new \Order($orderId);
            if (!\Validate::isLoadedObject($nativeOrder)) {
                throw new \RuntimeException('The financing order currency is unavailable.');
            }
            $nativeCurrency = new \Currency((int) $nativeOrder->id_currency);
            if (!\Validate::isLoadedObject($nativeCurrency)) {
                throw new \RuntimeException('The financing order currency is unavailable.');
            }
            $currency = [
                'id_currency' => (int) $nativeOrder->id_currency,
                'currency_iso' => (string) $nativeCurrency->iso_code,
            ];
        }

        if (!is_array($currency)
            || (int) ($currency['id_currency'] ?? 0) <= 0
            || (int) ($currency['id_currency'] ?? 0) !== (int) ($snapshot['id_currency'] ?? 0)
            || strtoupper(trim((string) ($currency['currency_iso'] ?? ''))) !== 'EUR'
            || (string) ($snapshot['currency_iso'] ?? '') !== 'EUR'
        ) {
            throw new \RuntimeException('The financing snapshot currency does not match the EUR order.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function assertSavedCpPayload(array $payload): void
    {
        if (($payload['currency'] ?? null) !== 'EUR') {
            throw new \RuntimeException('The saved Control Panel payload currency must be EUR.');
        }
    }

    /** @return array<string, mixed> */
    public function decodeSavedCpPayload($savedPayload): array
    {
        if (!is_string($savedPayload) || trim($savedPayload) === '') {
            throw new \RuntimeException('The saved Control Panel payload is unavailable.');
        }

        $decoded = json_decode($savedPayload);
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('The saved Control Panel payload is invalid.');
        }

        $payload = get_object_vars($decoded);
        $this->assertSavedCpPayload($payload);

        return $payload;
    }
}
