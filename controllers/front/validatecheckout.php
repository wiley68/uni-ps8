<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentValidator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\NativePrestaShopOrderGateway;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

final class UnipaymentValidateCheckoutModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        if (!$this->module->active || !Tools::isSubmit('unipayment_checkout_submit') || !Tools::getIsset('unipayment_checkout_token')
            || !hash_equals(Tools::getToken(false), (string) Tools::getValue('unipayment_checkout_token'))
        ) {
            $this->showError($this->module->getTranslator()->trans('The checkout request is invalid.', [], 'Modules.Unipayment.Shop'));

            return;
        }
        try {
            $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$repository->isEnabled()) {
                throw new CheckoutValidationException('This payment method is disabled.');
            }
            /** @var Unipayment $module */
            $module = $this->module;
            $shop = $module->getShopConfigurationService()->get();
            $cart = (new CartContextFactory())->create($this->context->cart);
            $calculator = new Calculator();
            $validator = new CheckoutPaymentValidator(
                $calculator,
                new CartSchemeResolver($calculator),
                new CurrencyGate(),
                new CartSnapshot(),
                new CartSnapshotSigner(_COOKIE_KEY_),
                new CustomerFieldValidator(),
                new ConsentResolver()
            );
            $request = $validator->validate(
                $shop,
                $cart,
                (string) $this->context->currency->iso_code,
                $this->postedSelection(),
                $module->getCheckoutCustomerData()
            );
            $shop['_is_mobile'] = $this->context->isMobile();
            $orchestrator = new OrderOrchestrator(
                new OrderAttemptRepository(),
                new FinancingSnapshotRepository(),
                new NativePrestaShopOrderGateway($module, $this->context),
                new ControlPanelOrderClientAdapter($module->getControlPanelClient()),
                new FinancingSnapshotFactory(new SensitiveDataCipher()),
                new ControlPanelOrderPayloadBuilder()
            );
            $result = $orchestrator->orchestrate((int) $this->context->shop->id, (int) $this->context->cart->id, $request, $shop);
            $this->context->smarty->assign(['unipayment_order_result' => [
                'id_order' => $result->idOrder,
                'order_reference' => $result->orderReference,
                'control_panel_order_id' => $result->controlPanelOrderId,
            ]]);
            $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');
        } catch (CheckoutValidationException $exception) {
            $this->showError($exception->getMessage());
        } catch (OrderOrchestrationException $exception) {
            PrestaShopLogger::addLog('UniPayment order orchestration failed: ' . get_class($exception) . '; retryable=' . ($exception->isRetryable() ? '1' : '0'), 2);
            $this->showError($this->module->getTranslator()->trans(
                $exception->isRetryable() ? 'The financing order could not be submitted. You can safely try again.' : 'The financing order could not be completed.',
                [],
                'Modules.Unipayment.Shop'
            ));
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment checkout validation failed: ' . get_class($exception), 2);
            $this->showError($this->module->getTranslator()->trans('The financing selection could not be validated.', [], 'Modules.Unipayment.Shop'));
        }
    }

    /** @return array<string, mixed> */
    private function postedSelection(): array
    {
        return [
            'scheme_key' => (string) Tools::getValue('unipayment_scheme_key', ''),
            'kop_code' => (string) Tools::getValue('unipayment_kop_code', ''),
            'first_installment' => Tools::getValue('unipayment_first_installment', 0),
            'cart_snapshot' => (string) Tools::getValue('unipayment_cart_snapshot', ''),
            'egn' => (string) Tools::getValue('unipayment_egn', ''),
            'phone2' => (string) Tools::getValue('unipayment_phone2', ''),
            'consent' => Tools::getValue('unipayment_consent', []),
        ];
    }

    private function showError(string $message): void
    {
        $this->context->smarty->assign([
            'unipayment_checkout_error' => $message,
            'unipayment_checkout_return_url' => $this->context->link->getPageLink('order', true),
        ]);
        $this->setTemplate('module:unipayment/views/templates/front/checkout_validation_error.tpl');
    }
}
