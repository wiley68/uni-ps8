<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

final class UnipaymentProductPopupModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax(): void
    {
        $this->ajaxRender(json_encode($this->response(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST'
            || !$this->module->active
            || !hash_equals(Tools::getToken(false), (string) Tools::getValue('token', ''))
        ) {
            return $this->error(403, 'Invalid popup request.');
        }

        $productId = filter_var(Tools::getValue('id_product'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $attributeId = filter_var(Tools::getValue('id_product_attribute', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $quantity = filter_var(Tools::getValue('quantity', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $months = filter_var(Tools::getValue('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $filterId = filter_var(Tools::getValue('filter_id', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $popupType = (string) Tools::getValue('popup_offer_type', '');
        $schemeType = (string) Tools::getValue('scheme_type', '');
        $kopCode = trim((string) Tools::getValue('kop_code', ''));
        $schemeKey = trim((string) Tools::getValue('scheme_key', ''));
        $firstRaw = Tools::getValue('first_installment', 0);
        if ($productId === false || $attributeId === false || $quantity === false || $months === false || $filterId === false
            || !in_array($popupType, ['standard', 'promo'], true) || !in_array($schemeType, ['standard', 'promo'], true)
            || $kopCode === '' || $schemeKey === '' || !is_numeric($firstRaw)
        ) {
            return $this->error(400, 'Invalid popup selection.');
        }

        try {
            $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$repository->isEnabled()) {
                return $this->error(403, 'The module is unavailable.');
            }
            /** @var Unipayment $module */
            $module = $this->module;
            $shop = $module->getShopConfigurationService()->get();
            $product = (new ProductContextFactory())->create((int) $productId, (int) $attributeId, (int) $quantity);
            $calculation = (new ProductPopupCalculator(new Calculator()))->calculate(
                $shop,
                $product,
                (string) $this->context->currency->iso_code,
                $popupType,
                $schemeType,
                $kopCode,
                (int) $months,
                (int) $filterId,
                $schemeKey,
                (float) $firstRaw
            );

            $action = (string) Tools::getValue('popup_action', 'calculate');
            if ($action === 'validate_step2') {
                $customer = (new ProductPopupCustomerValidator())->validate([
                    'first_name' => Tools::getValue('first_name', ''),
                    'last_name' => Tools::getValue('last_name', ''),
                    'address' => Tools::getValue('address', ''),
                    'phone' => Tools::getValue('phone', ''),
                    'email' => Tools::getValue('email', ''),
                ]);

                return [
                    'success' => true,
                    'step' => 'final_placeholder',
                    'calculation' => $calculation,
                    'customer' => $customer,
                ];
            }

            if ($action === 'preselect') {
                $cart = $this->ensureCart();
                (new CheckoutPreferenceStore())->save($this->context->cookie, [
                    'product_id' => (int) $productId,
                    'product_attribute_id' => (int) $attributeId,
                    'quantity' => (int) $quantity,
                    'scheme_type' => $calculation['scheme_type'],
                    'kop_code' => $calculation['kop_code'],
                    'months' => $calculation['months'],
                    'filter_id' => $calculation['filter_id'],
                    'first_installment' => $calculation['first_installment'],
                    'product_amount' => $calculation['price'],
                    'calculation' => $calculation,
                ], (int) $cart->id, (int) $this->context->customer->id);

                return [
                    'success' => true,
                    'calculation' => $calculation,
                    'checkout_url' => $this->context->link->getPageLink('order', true),
                ];
            }

            return ['success' => true, 'calculation' => $calculation];
        } catch (ProductPopupValidationException $exception) {
            http_response_code(422);

            return ['success' => false, 'message' => 'The customer details are invalid.', 'errors' => $exception->errors()];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment product popup request failed: ' . get_class($exception), 2);

            return $this->error(422, 'The financing selection is unavailable.');
        }
    }

    private function ensureCart(): Cart
    {
        $cart = $this->context->cart;
        if ($cart instanceof Cart && (int) $cart->id > 0) {
            return $cart;
        }
        $cart = new Cart();
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->id_shop = (int) $this->context->shop->id;
        $cart->id_lang = (int) $this->context->language->id;
        $cart->id_currency = (int) $this->context->currency->id;
        $cart->id_customer = (int) $this->context->customer->id;
        $cart->id_guest = (int) $this->context->cookie->id_guest;
        $cart->secure_key = (string) $this->context->customer->secure_key;
        if (!$cart->add()) {
            throw new RuntimeException('The cart could not be initialized.');
        }
        $this->context->cart = $cart;
        $this->context->cookie->id_cart = (int) $cart->id;
        $this->context->cookie->write();

        return $cart;
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
