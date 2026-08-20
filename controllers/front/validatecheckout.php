<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentValidator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
use PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLock;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\FinancingOrderMailDispatcher;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\NativePrestaShopOrderGateway;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Order\OrderConfirmationUrlBuilder;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionClient;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;

final class UnipaymentValidateCheckoutModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        if (
            !$this->module->active || !Tools::isSubmit('unipayment_checkout_submit') || !Tools::getIsset('unipayment_checkout_token')
            || !hash_equals(Tools::getToken(false), (string) Tools::getValue('unipayment_checkout_token'))
        ) {
            $this->showError($this->module->getTranslator()->trans('The checkout request is invalid.', [], 'Modules.Unipayment.Shop'));

            return;
        }

        $idShop = (int) $this->context->shop->id;
        $idCart = (int) $this->context->cart->id;
        $lock = new CheckoutSubmitLock();
        if (!$lock->acquire($idShop, $idCart)) {
            $this->showError($this->module->getTranslator()->trans(
                'The request is already being processed. Please wait.',
                [],
                'Modules.Unipayment.Shop'
            ));

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
            $cart = (new CartContextFactory())->createForCheckout($this->context->cart);
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
            $cpClient = new ControlPanelOrderClientAdapter($module->getControlPanelClient());
            $orchestrator = new OrderOrchestrator(
                new OrderAttemptRepository(),
                new FinancingSnapshotRepository(),
                new NativePrestaShopOrderGateway($module, $this->context),
                $cpClient,
                new FinancingSnapshotFactory(new SensitiveDataCipher()),
                new ControlPanelOrderPayloadBuilder()
            );
            $result = $orchestrator->orchestrate($idShop, $idCart, $request, $shop, 'checkout');
            (new CheckoutPreferenceStore())->clear($this->context->cookie);
            $snapshot = (new FinancingSnapshotRepository())->findByAttempt($result->attemptId);
            $process2 = $this->isProcess2($shop);
            $finalStatus = BankStatus::successfulSend($process2);
            if ($snapshot !== null && $process2) {
                $this->persistBankStatus($result->orderReference, $finalStatus);
            }

            if (!$process2) {
                if ($snapshot !== null) {
                    $shop['_currency_iso'] = (string) $this->context->currency->iso_code;
                    $payloadBuilder = new SmartUcfPayloadBuilder();
                    $smartUcfPayload = $payloadBuilder->build($shop, $snapshot);
                    $smartUcf = new SmartUcfSessionClient($payloadBuilder);
                    try {
                        $session = $smartUcf->createSession($shop, $snapshot);
                        $this->persistBankStatus($result->orderReference, $finalStatus);
                        $this->logSmartUcfSession($result->idOrder, $result->orderReference, $session);
                        (new FinancingOrderMailDispatcher())->send($snapshot, $result->attemptId, $shop, $finalStatus);
                        Tools::redirect($session['redirect_url']);

                        return;
                    } catch (SmartUcfSessionException $e) {
                        $this->logSmartUcfError(
                            $result->idOrder,
                            $result->orderReference,
                            $e,
                            $smartUcfPayload,
                            $e->rawResponse()
                        );
                        $this->markSmartUcfFailure($cpClient, $result->idOrder, $result->orderReference);
                        $finalStatus = BankStatus::smartUcfFailure();
                    }
                }
            }

            if ($snapshot !== null) {
                (new FinancingOrderMailDispatcher())->send($snapshot, $result->attemptId, $shop, $finalStatus);
            } else {
                \PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue::flush();
            }

            if ($process2) {
                Tools::redirect(
                    (new OrderConfirmationUrlBuilder())->build($this->context, $module, $result->idOrder)
                );

                return;
            }

            $this->context->smarty->assign(['unipayment_order_result' => [
                'id_order' => $result->idOrder,
                'order_reference' => $result->orderReference,
                'control_panel_order_id' => $result->controlPanelOrderId,
            ]]);
            $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');
        } catch (CheckoutValidationException $exception) {
            $lock->release($idShop, $idCart);
            $this->showError($exception->getMessage());
        } catch (OrderOrchestrationException $exception) {
            if ($exception->isRetryable()) {
                $lock->release($idShop, $idCart);
            }
            PrestaShopLogger::addLog('UniPayment order orchestration failed: ' . get_class($exception) . '; retryable=' . ($exception->isRetryable() ? '1' : '0'), 2);
            $this->showError($this->module->getTranslator()->trans(
                $exception->isRetryable() ? 'The financing order could not be submitted. You can safely try again.' : 'The financing order could not be completed.',
                [],
                'Modules.Unipayment.Shop'
            ));
        } catch (Throwable $exception) {
            $lock->release($idShop, $idCart);
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

    /** @param array<string, mixed> $session */
    private function logSmartUcfSession(int $idOrder, string $orderReference, array $session): void
    {
        try {
            $journal = new SmartUcfDiagnosticJournal(
                new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository(),
                new SmartUcfDebugLogRepository()
            );
            $journal->record(
                $idOrder,
                $orderReference,
                (int) ($session['http_code'] ?? 0),
                $session['raw_request'] ?? '',
                $session['raw_response'] ?? ''
            );
        } catch (\Throwable $e) {
            // Logging failure must not break the checkout flow.
        }
    }

    private function logSmartUcfError(int $idOrder, string $orderReference, SmartUcfSessionException $exception, $request = '', $response = ''): void
    {
        PrestaShopLogger::addLog('UniPayment SmartUCF session failed during checkout: ' . $exception->getMessage(), 2);
        try {
            $journal = new SmartUcfDiagnosticJournal(
                new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository(),
                new SmartUcfDebugLogRepository()
            );
            $journal->record(
                $idOrder,
                $orderReference,
                $exception->httpCode(),
                $request,
                $response !== '' ? $response : $exception->getMessage(),
                $exception->getMessage()
            );
        } catch (\Throwable $e) {
            // Logging failure must not break the checkout flow.
        }
    }

    private function markSmartUcfFailure(ControlPanelOrderClientAdapter $cpClient, int $idOrder, string $orderReference): void
    {
        $cpOrderId = substr($orderReference, 0, 13);
        $failedStatus = BankStatus::smartUcfFailure();
        try {
            $cpClient->updateOrderStatus(
                $cpOrderId,
                $failedStatus['status_label'],
                $failedStatus['status_id']
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('UniPayment CP status update failed after SmartUCF error: ' . get_class($e), 2);
        }

        $this->persistBankStatus($orderReference, $failedStatus);

        try {
            (new NativePrestaShopOrderGateway($this->module, $this->context))->markFailed($idOrder);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('UniPayment order mark failed after SmartUCF error failed: ' . get_class($e), 2);
        }
    }

    /** @param array{status_id: string, status_label: string} $status */
    private function persistBankStatus(string $orderReference, array $status): void
    {
        try {
            (new OrderBankStatusRepository())->updateByOrderIdentifier(
                $orderReference,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment local bank status update failed: ' . get_class($exception),
                2
            );
        }
    }

    private function showError(string $message): void
    {
        $this->context->smarty->assign([
            'unipayment_checkout_error' => $message,
            'unipayment_checkout_return_url' => $this->context->link->getPageLink('order', true),
        ]);
        $this->setTemplate('module:unipayment/views/templates/front/checkout_validation_error.tpl');
    }

    /**
     * @param array<string, mixed> $shop
     * @see productpopup.php::isProcess2()
     */
    private function isProcess2(array $shop): bool
    {
        return ((int) ($shop['uni_proces'] ?? 0)) === 1;
    }
}
