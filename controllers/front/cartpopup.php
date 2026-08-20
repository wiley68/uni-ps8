<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartPopupApplyService;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
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
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionClient;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;

/**
 * Cart popup calculate / apply endpoint (Woo source=cart parity).
 * Completes the credit application from the cart without checkout.
 */
final class UnipaymentCartPopupModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax(): void
    {
        $this->ajaxRender(json_encode($this->response(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        if (
            $_SERVER['REQUEST_METHOD'] !== 'POST'
            || !$this->module->active
            || !hash_equals(Tools::getToken(false), (string) Tools::getValue('token', ''))
        ) {
            return $this->error(403, 'Invalid popup request.');
        }

        $months = filter_var(Tools::getValue('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $filterId = filter_var(Tools::getValue('filter_id', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $popupType = (string) Tools::getValue('popup_offer_type', '');
        $schemeType = (string) Tools::getValue('scheme_type', '');
        $kopCode = trim((string) Tools::getValue('kop_code', ''));
        $schemeKey = trim((string) Tools::getValue('scheme_key', ''));
        $firstRaw = Tools::getValue('first_installment', 0);
        if (
            $months === false || $filterId === false
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
            $calculator = new Calculator();
            $resolver = new CartSchemeResolver($calculator);
            $cartContext = (new CartContextFactory())->create($this->context->cart);
            $popupCalculator = new CartPopupCalculator($calculator, $resolver);
            $calculation = $popupCalculator->calculate(
                $shop,
                $cartContext,
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
            if ($action === 'apply') {
                return $this->handleApply($shop, $cartContext, $calculator, $popupCalculator);
            }

            return ['success' => true, 'calculation' => $calculation];
        } catch (ProductPopupValidationException $exception) {
            http_response_code(422);

            return ['success' => false, 'message' => 'The customer details are invalid.', 'errors' => $exception->errors()];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment cart popup request failed: ' . get_class($exception), 2);

            return $this->error(422, 'The financing selection is unavailable.');
        }
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function handleApply(
        array $shop,
        \PrestaShop\Module\Unipayment\Cart\CartContext $cartContext,
        Calculator $calculator,
        CartPopupCalculator $popupCalculator
    ): array {
        $posted = [
            'popup_offer_type' => Tools::getValue('popup_offer_type', ''),
            'scheme_type' => Tools::getValue('scheme_type', ''),
            'kop_code' => Tools::getValue('kop_code', ''),
            'months' => Tools::getValue('months', 0),
            'filter_id' => Tools::getValue('filter_id', 0),
            'scheme_key' => Tools::getValue('scheme_key', ''),
            'first_installment' => Tools::getValue('first_installment', 0),
            'first_name' => Tools::getValue('first_name', ''),
            'last_name' => Tools::getValue('last_name', ''),
            'address' => Tools::getValue('address', ''),
            'phone' => Tools::getValue('phone', ''),
            'email' => Tools::getValue('email', ''),
            'egn' => Tools::getValue('egn', ''),
            'phone2' => Tools::getValue('phone2', ''),
            'consent' => Tools::getValue('unipayment_consent', []),
        ];

        /** @var Unipayment $module */
        $module = $this->module;
        $cpClient = new ControlPanelOrderClientAdapter($module->getControlPanelClient());
        $orchestrator = new OrderOrchestrator(
            new OrderAttemptRepository(),
            new FinancingSnapshotRepository(),
            new NativePrestaShopOrderGateway($module, $this->context),
            $cpClient,
            new FinancingSnapshotFactory(new SensitiveDataCipher()),
            new ControlPanelOrderPayloadBuilder()
        );
        $service = new CartPopupApplyService(
            $calculator,
            $popupCalculator,
            new ProductPopupCustomerValidator(),
            new GuestCustomerFactory(),
            $orchestrator
        );

        try {
            $result = $service->apply($shop, $posted, $cartContext, $this->context);

            $response = [
                'success' => true,
                'step' => 'order_created',
                'order' => [
                    'id_order' => $result->idOrder,
                    'order_reference' => $result->orderReference,
                    'control_panel_order_id' => $result->controlPanelOrderId,
                ],
            ];

            try {
                $snapshot = (new FinancingSnapshotRepository())->findByAttempt($result->attemptId);
                if ($snapshot !== null) {
                    $process2 = $this->isProcess2($shop);
                    $finalStatus = BankStatus::successfulSend($process2);
                    if ($process2) {
                        $this->persistBankStatus($result->orderReference, $finalStatus);
                    }

                    if (!$process2) {
                        $shop['_currency_iso'] = (string) $this->context->currency->iso_code;
                        $payloadBuilder = new SmartUcfPayloadBuilder();
                        $smartUcfPayload = $payloadBuilder->build($shop, $snapshot);
                        $smartUcf = new SmartUcfSessionClient($payloadBuilder);
                        try {
                            $session = $smartUcf->createSession($shop, $snapshot);
                            $this->persistBankStatus($result->orderReference, $finalStatus);
                            $response['redirect_url'] = $session['redirect_url'];
                            $this->logSmartUcf($result->idOrder, $result->orderReference, $session);
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
                            $response['smartucf_error'] = $this->smartUcfErrorMessage($e);
                        } catch (\Throwable $smartUcfException) {
                            PrestaShopLogger::addLog(
                                'UniPayment cart popup SmartUCF unexpected failure: ' . get_class($smartUcfException) . ' ' . $smartUcfException->getMessage(),
                                2
                            );
                            $this->markSmartUcfFailure($cpClient, $result->idOrder, $result->orderReference);
                            $finalStatus = BankStatus::smartUcfFailure();
                            $response['smartucf_error'] = 'Има временен проблем с услугата за изпращане на поръчки към Банката.';
                        }
                    }

                    try {
                        (new FinancingOrderMailDispatcher())->send($snapshot, $result->attemptId, $shop, $finalStatus);
                    } catch (\Throwable $emailException) {
                        PrestaShopLogger::addLog(
                            'UniPayment cart popup leasing email failed: ' . get_class($emailException) . ' ' . $emailException->getMessage(),
                            2
                        );
                        $response['email_error'] = 'Leasing email could not be sent.';
                    }
                } else {
                    \PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue::flush();
                }
            } catch (\Throwable $postOrderException) {
                \PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue::flush();
                PrestaShopLogger::addLog(
                    'UniPayment cart popup post-order step failed: ' . get_class($postOrderException) . ' ' . $postOrderException->getMessage(),
                    2
                );
                $response['post_order_error'] = 'Поръчката е създадена, но допълнителната обработка не беше завършена.';
            }

            if ($this->isProcess2($shop)) {
                $response['redirect_url'] = (new OrderConfirmationUrlBuilder())->build(
                    $this->context,
                    $module,
                    $result->idOrder
                );
            }

            return $response;
        } catch (ProductPopupValidationException $exception) {
            http_response_code(422);
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $errors['consents'] ?? 'The customer details are invalid.',
                'errors' => $errors,
            ];
        } catch (OrderOrchestrationException $exception) {
            PrestaShopLogger::addLog('UniPayment cart popup apply orchestration failed: ' . get_class($exception), 2);
            http_response_code(500);

            return ['success' => false, 'message' => 'Заявката за финансиране не може да бъде обработена. Моля, опитайте отново.'];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment cart popup apply failed: ' . get_class($exception) . ' ' . $exception->getMessage(), 2);
            http_response_code(422);

            return ['success' => false, 'message' => 'The financing selection is unavailable.'];
        }
    }

    /** @param array<string, mixed> $session */
    private function logSmartUcf(int $idOrder, string $orderReference, array $session): void
    {
        $journal = $this->diagnosticJournal();
        if ($journal === null) {
            return;
        }
        $journal->record(
            $idOrder,
            $orderReference,
            (int) ($session['http_code'] ?? 0),
            $session['raw_request'] ?? '',
            $session['raw_response'] ?? ''
        );
    }

    private function logSmartUcfError(int $idOrder, string $orderReference, SmartUcfSessionException $exception, $request = '', $response = ''): void
    {
        PrestaShopLogger::addLog('UniPayment SmartUCF session failed: ' . $exception->getMessage(), 2);
        $journal = $this->diagnosticJournal();
        if ($journal === null) {
            return;
        }
        $journal->record($idOrder, $orderReference, $exception->httpCode(), $request, $response, $exception->getMessage());
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

    private function diagnosticJournal(): ?\PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal
    {
        try {
            return new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal(
                new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository(),
                new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository()
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function smartUcfErrorMessage(SmartUcfSessionException $exception): string
    {
        $raw = $exception->rawResponse();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['errorText'])) {
                return 'SmartUCF: ' . (string) $decoded['errorText'];
            }
        }

        return 'Има временен проблем с услугата за изпращане на поръчки към Банката.';
    }

    /** @param array<string, mixed> $shop */
    private function isProcess2(array $shop): bool
    {
        return ((int) ($shop['uni_proces'] ?? 0)) === 1;
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

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
