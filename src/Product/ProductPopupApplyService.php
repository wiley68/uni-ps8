<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Cart;
use Context;
use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

/**
 * Coordinates the direct order flow from the product popup Step 2 "Submit".
 *
 * Flow:
 *  1. Re-validate financing scheme availability and recalculate
 *  2. Validate customer data (including EGN when Process 2)
 *  3. Ensure guest customer/address for non-logged-in visitors
 *  4. Build a single-product cart
 *  5. Build ValidatedPaymentRequest
 *  6. Delegate to OrderOrchestrator
 */
final class ProductPopupApplyService
{
    /** @var Calculator */
    private $calculator;
    /** @var ProductPopupCustomerValidator */
    private $customerValidator;
    /** @var GuestCustomerFactory */
    private $guestFactory;
    /** @var OrderOrchestrator */
    private $orchestrator;
    /** @var SensitiveDataCipher */
    private $cipher;
    /** @var CurrencyGate */
    private $currencyGate;
    /** @var ConsentResolver */
    private $consents;

    public function __construct(
        Calculator $calculator,
        ProductPopupCustomerValidator $customerValidator,
        GuestCustomerFactory $guestFactory,
        OrderOrchestrator $orchestrator,
        SensitiveDataCipher $cipher,
        ?CurrencyGate $currencyGate = null,
        ?ConsentResolver $consents = null
    ) {
        $this->calculator = $calculator;
        $this->customerValidator = $customerValidator;
        $this->guestFactory = $guestFactory;
        $this->orchestrator = $orchestrator;
        $this->cipher = $cipher;
        $this->currencyGate = $currencyGate ?? new CurrencyGate();
        $this->consents = $consents ?? new ConsentResolver();
    }

    /**
     * @param array<string, mixed> $shop      Cached shop configuration
     * @param array<string, mixed> $posted     Raw POST data
     * @return OrderOrchestrationResult
     */
    public function apply(
        array $shop,
        array $posted,
        ProductContext $product,
        int $productId,
        int $attributeId,
        int $quantity,
        Context $context
    ): OrderOrchestrationResult {
        $popupType = (string) ($posted['popup_offer_type'] ?? '');
        $schemeType = (string) ($posted['scheme_type'] ?? '');
        $kopCode = trim((string) ($posted['kop_code'] ?? ''));
        $months = (int) ($posted['months'] ?? 0);
        $filterId = (int) ($posted['filter_id'] ?? 0);
        $schemeKey = trim((string) ($posted['scheme_key'] ?? ''));
        $firstInstallment = is_numeric($posted['first_installment'] ?? null) ? (float) $posted['first_installment'] : 0.0;
        $currencyIso = (string) $context->currency->iso_code;

        $allowedTypes = $popupType === 'standard' ? ['standard', 'promo'] : ($popupType === 'promo' ? ['promo'] : []);
        if (!$this->currencyGate->supports($shop, $currencyIso) || !in_array($schemeType, $allowedTypes, true)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $scheme = $this->findScheme($this->calculator->availableSchemes($shop, $product, $schemeType), $kopCode, $months, $filterId);
        if ($scheme === null || !hash_equals(ProductPopupSchemeList::key($scheme), $schemeKey)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $calcResult = $this->calculator->calculateScheme($shop, $product->price, $scheme, $firstInstallment);

        $requireEgn = ((int) ($shop['uni_proces'] ?? 0)) === 1;
        $customerData = $this->customerValidator->validate($posted, $requireEgn);

        try {
            $accepted = $this->consents->validate($shop, $posted['consent'] ?? []);
        } catch (CheckoutValidationException $exception) {
            throw new ProductPopupValidationException([
                'consents' => 'Моля, приемете всички задължителни съгласия.',
            ]);
        }
        $acceptedConsents = array_values(array_filter(
            $this->consents->normalize($shop),
            static function (array $consent) use ($accepted): bool {
                return in_array($consent['id'], $accepted, true);
            }
        ));

        $this->ensureCustomerAndCart($customerData, $productId, $attributeId, $quantity, $context);

        $cart = $context->cart;
        $cartFingerprint = md5((int) $cart->id . ':' . $product->price . ':' . $schemeKey);

        $request = new ValidatedPaymentRequest($calcResult, $customerData, $accepted, $cartFingerprint, $acceptedConsents);

        return $this->orchestrator->orchestrate(
            (int) $context->shop->id,
            (int) $cart->id,
            $request,
            $shop,
            'product_popup'
        );
    }

    /** @param array<string, string> $customerData */
    private function ensureCustomerAndCart(array $customerData, int $productId, int $attributeId, int $quantity, Context $context): void
    {
        $customerId = (int) $context->customer->id;

        if ($customerId <= 0) {
            $result = $this->guestFactory->ensure($customerData, $context);
            $context->customer = $result['customer'];
            $context->cookie->id_customer = (int) $result['customer']->id;
            $context->cookie->customer_lastname = $result['customer']->lastname;
            $context->cookie->customer_firstname = $result['customer']->firstname;
            $context->cookie->logged = 0;
            $context->cookie->passwd = $result['customer']->passwd;
            $context->cookie->email = $result['customer']->email;
            $context->cookie->is_guest = 1;

            $cart = $this->createFreshCart($context, (int) $result['address']->id);
        } else {
            $addressId = (int) \Address::getFirstCustomerAddressId($customerId);
            $cart = $this->createFreshCart($context, $addressId > 0 ? $addressId : 0);
        }

        $cart->updateQty($quantity, $productId, $attributeId > 0 ? $attributeId : null);

        $context->cart = $cart;
        $context->cookie->id_cart = (int) $cart->id;
        $context->cookie->write();
    }

    private function createFreshCart(Context $context, int $addressId): Cart
    {
        $cart = new Cart();
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_lang = (int) $context->language->id;
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_customer = (int) $context->customer->id;
        $cart->id_guest = (int) $context->cookie->id_guest;
        $cart->secure_key = (string) $context->customer->secure_key;
        if ($addressId > 0) {
            $cart->id_address_delivery = $addressId;
            $cart->id_address_invoice = $addressId;
        }
        if (!$cart->add()) {
            throw new \RuntimeException('The cart could not be created for the popup order.');
        }

        return $cart;
    }

    /** @param AvailableScheme[] $schemes */
    private function findScheme(array $schemes, string $kopCode, int $months, int $filterId): ?AvailableScheme
    {
        foreach ($schemes as $scheme) {
            if ($scheme->kopCode === $kopCode && $scheme->months === $months && $scheme->filterId === $filterId) {
                return $scheme;
            }
        }

        return null;
    }
}
