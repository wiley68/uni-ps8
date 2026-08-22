<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;

final class ProductPopupCheckoutPreselectionService
{
    private const MUTATION_COOKIE = 'unipayment_preselect_mutation';

    /** @var CheckoutPreferenceStore */
    private $preferences;

    public function __construct(?CheckoutPreferenceStore $preferences = null)
    {
        $this->preferences = $preferences ?? new CheckoutPreferenceStore();
    }

    /**
     * @param array<string, mixed> $calculation
     * @return array{checkout_url: string}
     */
    public function execute(
        array $calculation,
        int $productId,
        int $productAttributeId,
        int $quantity,
        \Context $context,
        \Link $link
    ): array {
        if ($productId <= 0 || $quantity <= 0) {
            throw new ProductPopupCheckoutPreselectionException('Продуктът не може да бъде добавен в количката.');
        }

        $cart = $this->ensureCart($context);
        $mutationHash = $this->mutationHash(
            (int) $cart->id,
            $productId,
            $productAttributeId,
            $quantity,
            $calculation
        );

        if ((string) $context->cookie->{self::MUTATION_COOKIE} !== $mutationHash) {
            $this->addProductToCart($cart, $productId, $productAttributeId, $quantity);
            $context->cookie->{self::MUTATION_COOKIE} = $mutationHash;
            $context->cookie->write();
        }

        $context->cart = $cart;
        $context->cookie->id_cart = (int) $cart->id;

        $this->preferences->save($context->cookie, [
            'product_id' => $productId,
            'product_attribute_id' => $productAttributeId,
            'quantity' => $quantity,
            'scheme_type' => (string) ($calculation['scheme_type'] ?? ''),
            'kop_code' => (string) ($calculation['kop_code'] ?? ''),
            'months' => (int) ($calculation['months'] ?? 0),
            'filter_id' => (int) ($calculation['filter_id'] ?? 0),
            'first_installment' => $calculation['first_installment'] ?? 0,
            'product_amount' => $calculation['price'] ?? 0,
        ], (int) $cart->id, (int) $context->customer->id);

        return [
            'checkout_url' => $link->getPageLink('order', true),
        ];
    }

    private function ensureCart(\Context $context): \Cart
    {
        $cart = $context->cart;
        if ($cart instanceof \Cart && (int) $cart->id > 0) {
            return $cart;
        }

        if ((int) $context->cookie->id_guest <= 0) {
            \Guest::setNewGuest($context->cookie);
        }

        $cart = new \Cart();
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_lang = (int) $context->language->id;
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_customer = (int) $context->customer->id;
        $cart->id_guest = (int) $context->cookie->id_guest;
        $cart->secure_key = (string) $context->customer->secure_key;
        if (!$cart->add()) {
            throw new ProductPopupCheckoutPreselectionException('Количката не може да бъде инициализирана.');
        }

        $context->cart = $cart;
        $context->cookie->id_cart = (int) $cart->id;
        $context->cookie->write();

        return $cart;
    }

    private function addProductToCart(\Cart $cart, int $productId, int $productAttributeId, int $quantity): void
    {
        $updated = $cart->updateQty(
            $quantity,
            $productId,
            $productAttributeId > 0 ? $productAttributeId : null
        );
        if ($updated === false) {
            throw new ProductPopupCheckoutPreselectionException('Продуктът не може да бъде добавен в количката.');
        }
    }

    /** @param array<string, mixed> $calculation */
    private function mutationHash(
        int $cartId,
        int $productId,
        int $productAttributeId,
        int $quantity,
        array $calculation
    ): string {
        $payload = [
            'cart_id' => $cartId,
            'product_id' => $productId,
            'product_attribute_id' => $productAttributeId,
            'quantity' => $quantity,
            'scheme_type' => (string) ($calculation['scheme_type'] ?? ''),
            'kop_code' => (string) ($calculation['kop_code'] ?? ''),
            'months' => (int) ($calculation['months'] ?? 0),
            'filter_id' => (int) ($calculation['filter_id'] ?? 0),
            'first_installment' => number_format(round((float) ($calculation['first_installment'] ?? 0), 2), 2, '.', ''),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
