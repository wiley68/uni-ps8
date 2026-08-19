<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\LeasingEmailNotifier;
use PrestaShop\Module\Unipayment\Order\NativePrestaShopOrderGateway;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionClient;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;

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
        if (
            $_SERVER['REQUEST_METHOD'] !== 'POST'
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
        if (
            $productId === false || $attributeId === false || $quantity === false || $months === false || $filterId === false
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
            if ($action === 'apply') {
                return $this->handleApply($shop, $product, (int) $productId, (int) $attributeId, (int) $quantity);
            }

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
            $this->logPopupSelectionFailure($exception);

            return $this->error(422, 'The financing selection is unavailable.');
        }
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function handleApply(array $shop, \PrestaShop\Module\Unipayment\Calculator\ProductContext $product, int $productId, int $attributeId, int $quantity): array
    {
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
        $service = new ProductPopupApplyService(
            new Calculator(),
            new ProductPopupCustomerValidator(),
            new GuestCustomerFactory(),
            $orchestrator,
            new SensitiveDataCipher()
        );

        try {
            $result = $service->apply($shop, $posted, $product, $productId, $attributeId, $quantity, $this->context);

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
                    $snapshot['status_label'] = ((int) ($shop['uni_proces'] ?? 0)) === 1
                        ? 'Изпратен Банка - Процес 2'
                        : 'Изпратен Банка - Процес 1';

                    try {
                        (new LeasingEmailNotifier())->notify($snapshot, $result->attemptId, $shop);
                    } catch (\Throwable $emailException) {
                        PrestaShopLogger::addLog(
                            'UniPayment popup leasing email failed: ' . get_class($emailException) . ' ' . $emailException->getMessage(),
                            2
                        );
                        $this->logPopupPostOrderFailure($result->idOrder, $result->orderReference, $emailException, 'leasing-email');
                        $response['email_error'] = 'Leasing email could not be sent.';
                    }

                    if (!$this->isProcess2($shop)) {
                        $shop['_currency_iso'] = (string) $this->context->currency->iso_code;
                        $payloadBuilder = new SmartUcfPayloadBuilder();
                        $smartUcfPayload = $payloadBuilder->build($shop, $snapshot);
                        $smartUcf = new SmartUcfSessionClient($payloadBuilder);
                        try {
                            $session = $smartUcf->createSession($shop, $snapshot);
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
                            $response['smartucf_error'] = $this->smartUcfErrorMessage($e);
                        } catch (\Throwable $smartUcfException) {
                            PrestaShopLogger::addLog(
                                'UniPayment popup SmartUCF unexpected failure: ' . get_class($smartUcfException) . ' ' . $smartUcfException->getMessage(),
                                2
                            );
                            $this->logPopupPostOrderFailure(
                                $result->idOrder,
                                $result->orderReference,
                                $smartUcfException,
                                'smartucf-unexpected',
                                $smartUcfPayload
                            );
                            $response['smartucf_error'] = 'Има временен проблем с услугата за изпращане на поръчки към Банката.';
                        }
                    }
                }
            } catch (\Throwable $postOrderException) {
                PrestaShopLogger::addLog(
                    'UniPayment popup post-order step failed: ' . get_class($postOrderException) . ' ' . $postOrderException->getMessage(),
                    2
                );
                $this->logPopupPostOrderFailure($result->idOrder, $result->orderReference, $postOrderException, 'post-order');
                $response['post_order_error'] = 'Поръчката е създадена, но допълнителната обработка не беше завършена.';
            }

            if ($this->isDebugResponseEnabled()) {
                if (!empty($response['smartucf_error'])) {
                    $response['debug_smartucf_error'] = $response['smartucf_error'];
                }
                if (!empty($response['email_error'])) {
                    $response['debug_email_error'] = $response['email_error'];
                }
            }

            return $response;
        } catch (ProductPopupValidationException $exception) {
            http_response_code(422);

            return ['success' => false, 'message' => 'The customer details are invalid.', 'errors' => $exception->errors()];
        } catch (OrderOrchestrationException $exception) {
            PrestaShopLogger::addLog('UniPayment popup apply orchestration failed: ' . get_class($exception), 2);
            http_response_code(500);

            return ['success' => false, 'message' => 'Заявката за финансиране не може да бъде обработена. Моля, опитайте отново.'];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment popup apply failed: ' . get_class($exception) . ' ' . $exception->getMessage(), 2);
            $this->logPopupSelectionFailure($exception);
            http_response_code(422);

            return ['success' => false, 'message' => 'The financing selection is unavailable.'];
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
        $statusLabel = 'Неуспешно изпратен Банка - SmartUCF';
        $statusId = 'bank_send_failed_smartucf';
        try {
            $cpClient->updateOrderStatus(
                $cpOrderId,
                $statusLabel,
                $statusId
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('UniPayment CP status update failed after SmartUCF error: ' . get_class($e), 2);
        }

        try {
            (new OrderBankStatusRepository())->updateByOrderIdentifier($orderReference, $statusId, $statusLabel);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('UniPayment local bank status update failed after SmartUCF error: ' . get_class($e), 2);
        }

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

    private function logPopupSelectionFailure(\Throwable $exception): void
    {
        try {
            $configuration = new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$configuration->isDebugEnabled()) {
                return;
            }
            $payload = [
                'popup_action' => (string) Tools::getValue('popup_action', ''),
                'popup_offer_type' => (string) Tools::getValue('popup_offer_type', ''),
                'scheme_type' => (string) Tools::getValue('scheme_type', ''),
                'scheme_key' => (string) Tools::getValue('scheme_key', ''),
                'kop_code' => (string) Tools::getValue('kop_code', ''),
                'months' => (string) Tools::getValue('months', ''),
                'filter_id' => (string) Tools::getValue('filter_id', ''),
                'first_installment' => (string) Tools::getValue('first_installment', ''),
                'id_product' => (string) Tools::getValue('id_product', ''),
                'id_product_attribute' => (string) Tools::getValue('id_product_attribute', ''),
                'quantity' => (string) Tools::getValue('quantity', ''),
                'token_present' => Tools::getValue('token', '') !== '',
            ];
            PrestaShopLogger::addLog(
                'UniPayment popup selection debug failure: '
                    . json_encode(
                        [
                            'exception' => get_class($exception),
                            'message' => $exception->getMessage(),
                            'payload' => $payload,
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                1
            );
            $journal = new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal(
                $configuration,
                new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository()
            );
            $journal->record(
                0,
                'popup-selection',
                422,
                $payload,
                ['source' => 'productpopup', 'error' => $exception->getMessage()],
                $exception->getMessage()
            );
        } catch (\Throwable $ignored) {
            unset($ignored);
        }
    }

    private function logPopupPostOrderFailure(int $idOrder, string $orderReference, \Throwable $exception, string $source = 'post-order', $request = null): void
    {
        try {
            $configuration = new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            $journal = new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal(
                $configuration,
                new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository()
            );
            $journal->record(
                $idOrder,
                $orderReference,
                500,
                $request ?? ['source' => 'productpopup-' . $source],
                ['exception' => get_class($exception), 'message' => $exception->getMessage()],
                $exception->getMessage()
            );
        } catch (\Throwable $ignored) {
            PrestaShopLogger::addLog(
                'UniPayment popup debug journal write failed: ' . $ignored->getMessage(),
                2
            );
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

    private function isDebugResponseEnabled(): bool
    {
        try {
            return (new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository())->isDebugEnabled();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Process 2 = uni_proces === 1 → email + CP only, no SmartUCF.
     * Process 1 = uni_proces === 0 → SmartUCF redirect.
     * Reference: mtunicredit/includes/functions.php → mtuc_is_shop_process_2()
     *
     * @param array<string, mixed> $shop
     */
    private function isProcess2(array $shop): bool
    {
        return ((int) ($shop['uni_proces'] ?? 0)) === 1;
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
