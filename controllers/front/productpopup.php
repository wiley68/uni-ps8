<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
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
use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionStates;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

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
            if ($action === 'issue_submission_token') {
                return $this->handleIssueSubmissionToken(
                    $calculation,
                    (int) $productId,
                    (int) $attributeId,
                    (int) $quantity
                );
            }
            if ($action === 'apply') {
                return $this->handleApply($shop, $product, (int) $productId, (int) $attributeId, (int) $quantity);
            }

            if ($action === 'validate_step2') {
                $requireEgn = ((int) ($shop['uni_proces'] ?? 0)) === 1;
                $customer = (new ProductPopupCustomerValidator())->validate([
                    'first_name' => Tools::getValue('first_name', ''),
                    'last_name' => Tools::getValue('last_name', ''),
                    'address' => Tools::getValue('address', ''),
                    'phone' => Tools::getValue('phone', ''),
                    'email' => Tools::getValue('email', ''),
                    'egn' => Tools::getValue('egn', ''),
                    'phone2' => Tools::getValue('phone2', ''),
                ], $requireEgn);

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
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $this->customerValidationMessage($errors),
                'errors' => $errors,
            ];
        } catch (UnavailableSchemeException $exception) {
            $this->logPopupSelectionFailure($exception);

            return $this->error(422, 'The financing selection is unavailable.');
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment product popup request failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );
            $this->logPopupSelectionFailure($exception);

            return $this->error(500, 'Заявката не може да бъде обработена. Моля, опитайте отново.');
        }
    }

    /**
     * @param array<string, mixed> $calculation
     * @return array<string, mixed>
     */
    private function handleIssueSubmissionToken(array $calculation, int $productId, int $attributeId, int $quantity): array
    {
        $resolved = (new PopupSubmissionBindingFactory())->fromSelection([
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'quantity' => $quantity,
            'scheme_type' => (string) ($calculation['scheme_type'] ?? ''),
            'kop_code' => (string) ($calculation['kop_code'] ?? ''),
            'months' => (int) ($calculation['months'] ?? 0),
            'filter_id' => (int) ($calculation['filter_id'] ?? 0),
            'scheme_key' => (string) ($calculation['scheme_key'] ?? trim((string) Tools::getValue('scheme_key', ''))),
            'first_installment' => $calculation['first_installment'] ?? 0,
        ], $this->context);

        $preferred = trim((string) Tools::getValue('popup_submission_token', ''));
        $row = (new PopupSubmissionRepository())->issueOrReuse(
            (int) $this->context->shop->id,
            $resolved['hash'],
            $resolved['id_guest'],
            $resolved['id_customer'],
            $preferred
        );

        return [
            'success' => true,
            'step' => 'submission_token_issued',
            'popup_submission_token' => (string) $row['submission_token'],
            'calculation' => $calculation,
        ];
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
            'phone2' => Tools::getValue('phone2', ''),
            'consent' => Tools::getValue('unipayment_consent', []),
        ];

        $token = trim((string) Tools::getValue('popup_submission_token', ''));
        $submissions = new PopupSubmissionRepository();
        $resolved = (new PopupSubmissionBindingFactory())->fromSelection([
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'quantity' => $quantity,
            'scheme_type' => (string) $posted['scheme_type'],
            'kop_code' => (string) $posted['kop_code'],
            'months' => (int) $posted['months'],
            'filter_id' => (int) $posted['filter_id'],
            'scheme_key' => (string) $posted['scheme_key'],
            'first_installment' => $posted['first_installment'],
        ], $this->context);

        /** @var Unipayment $module */
        $module = $this->module;
        $cpApi = $module->getControlPanelClient();
        $cpClient = new ControlPanelOrderClientAdapter($cpApi);

        $gate = $this->resolvePopupSubmissionGate($submissions, $token, $resolved['hash'], $shop, $module, $cpClient);
        if (isset($gate['response'])) {
            return $gate['response'];
        }

        /** @var array<string, mixed> $submission */
        $submission = $gate['submission'];
        $reuseCartId = (int) ($submission['id_cart'] ?? 0);
        $submissionId = (int) $submission['id_submission'];

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
            new SensitiveDataCipher(),
            null,
            null,
            null,
            $submissions
        );

        try {
            $result = $service->apply(
                $shop,
                $posted,
                $product,
                $productId,
                $attributeId,
                $quantity,
                $this->context,
                $submissionId,
                $reuseCartId
            );

            $submissions->markOrderCreated(
                $submissionId,
                $result->attemptId,
                $result->idOrder,
                $result->orderReference,
                $result->controlPanelOrderId
            );

            return $this->buildApplySuccessResponse($shop, $module, $cpClient, $result, true);
        } catch (ProductPopupValidationException $exception) {
            $submissions->revertProcessingWithoutCart($submissionId);
            http_response_code(422);
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $this->customerValidationMessage($errors),
                'errors' => $errors,
            ];
        } catch (UnavailableSchemeException $exception) {
            $submissions->revertProcessingWithoutCart($submissionId);
            http_response_code(422);
            $this->logPopupSelectionFailure($exception);

            return ['success' => false, 'message' => 'The financing selection is unavailable.'];
        } catch (OrderOrchestrationException $exception) {
            PrestaShopLogger::addLog('UniPayment popup apply orchestration failed: ' . get_class($exception), 2);
            if ($exception->isRetryable()) {
                return $this->processingResponse($token);
            }
            $submissions->markFailed($submissionId);
            http_response_code(500);

            return ['success' => false, 'message' => 'The financing request could not be processed. Please try again.'];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment popup apply failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );
            $this->logPopupSelectionFailure($exception);
            $rowAfter = $submissions->findByToken($token);
            if (is_array($rowAfter) && (int) ($rowAfter['id_cart'] ?? 0) <= 0) {
                $submissions->revertProcessingWithoutCart($submissionId);
            } else {
                $submissions->markFailed($submissionId);
            }
            http_response_code(500);

            return [
                'success' => false,
                'message' => 'Заявката не може да бъде обработена. Моля, опитайте отново.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $shop
     * @return array{response?: array<string, mixed>, submission?: array<string, mixed>}
     */
    private function resolvePopupSubmissionGate(
        PopupSubmissionRepository $submissions,
        string $token,
        string $selectionHash,
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient
    ): array {
        if ($token === '') {
            http_response_code(400);

            return ['response' => ['success' => false, 'message' => 'Missing popup submission token.']];
        }

        $row = $submissions->findByToken($token);
        if ($row === null) {
            http_response_code(400);

            return ['response' => ['success' => false, 'message' => 'Invalid popup submission token.']];
        }

        if (!hash_equals((string) $row['selection_hash'], $selectionHash)) {
            http_response_code(409);

            return [
                'response' => [
                    'success' => false,
                    'message' => 'The financing selection changed. Please continue from Step 1.',
                    'selection_changed' => true,
                ],
            ];
        }

        $state = (string) $row['state'];
        if ($state === PopupSubmissionStates::ORDER_CREATED && (int) ($row['id_order'] ?? 0) > 0) {
            return [
                'response' => $this->existingOrderResponse($row, $shop, $module, $cpClient),
            ];
        }

        if ($state === PopupSubmissionStates::FAILED) {
            http_response_code(409);

            return [
                'response' => [
                    'success' => false,
                    'message' => 'This financing submission can no longer be used. Please start again.',
                ],
            ];
        }

        if ($state === PopupSubmissionStates::PROCESSING) {
            $idCart = (int) ($row['id_cart'] ?? 0);
            if ($idCart <= 0) {
                return ['response' => $this->processingResponse($token)];
            }

            return ['submission' => $row];
        }

        if ($state === PopupSubmissionStates::ISSUED) {
            if ($submissions->isExpired($row)) {
                http_response_code(409);

                return [
                    'response' => [
                        'success' => false,
                        'message' => 'The popup submission token expired. Please continue from Step 1.',
                    ],
                ];
            }

            $claimed = $submissions->claimForProcessing($token);
            if ($claimed !== null) {
                return ['submission' => $claimed];
            }

            $latest = $submissions->findByToken($token);
            if (is_array($latest) && (string) $latest['state'] === PopupSubmissionStates::ORDER_CREATED) {
                return ['response' => $this->existingOrderResponse($latest, $shop, $module, $cpClient)];
            }
            if (is_array($latest) && (string) $latest['state'] === PopupSubmissionStates::PROCESSING) {
                if ((int) ($latest['id_cart'] ?? 0) > 0) {
                    return ['submission' => $latest];
                }

                return ['response' => $this->processingResponse($token)];
            }

            return ['response' => $this->processingResponse($token)];
        }

        http_response_code(409);

        return [
            'response' => [
                'success' => false,
                'message' => 'The popup submission is in an unknown state.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function existingOrderResponse(
        array $row,
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient
    ): array {
        $response = [
            'success' => true,
            'step' => 'order_created',
            'replay' => true,
            'popup_submission_token' => (string) $row['submission_token'],
            'order' => [
                'id_order' => (int) $row['id_order'],
                'order_reference' => (string) $row['order_reference'],
                'control_panel_order_id' => (int) ($row['control_panel_order_id'] ?? 0),
                'id_attempt' => (int) ($row['id_attempt'] ?? 0),
            ],
        ];

        $attemptId = (int) ($row['id_attempt'] ?? 0);
        if ($attemptId <= 0) {
            return $response;
        }

        $process2 = $this->isProcess2($shop);
        if ($process2) {
            $response['redirect_url'] = (new OrderConfirmationUrlBuilder())->build(
                $this->context,
                $module,
                (int) $row['id_order']
            );

            return $response;
        }

        $shop['_currency_iso'] = (string) $this->context->currency->iso_code;
        $coordinator = new SmartUcfSessionCoordinator(
            null,
            null,
            null,
            null,
            null,
            $cpClient,
            $module,
            $this->context,
            $module->getControlPanelClient()
        );
        $smart = $coordinator->resume($attemptId, $shop, false);
        $this->applySmartUcfResultToResponse($response, $smart);

        return $response;
    }

    /** @return array<string, mixed> */
    private function processingResponse(string $token): array
    {
        return [
            'success' => true,
            'step' => 'processing',
            'popup_submission_token' => $token,
            'message' => SmartUcfSessionCoordinator::CUSTOMER_PROCESSING,
        ];
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function buildApplySuccessResponse(
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient,
        \PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult $result,
        bool $runPostOrderSteps
    ): array {
        $response = [
            'success' => true,
            'step' => 'order_created',
            'order' => [
                'id_order' => $result->idOrder,
                'order_reference' => $result->orderReference,
                'control_panel_order_id' => $result->controlPanelOrderId,
            ],
        ];

        if (!$runPostOrderSteps) {
            return $response;
        }

        try {
            $snapshot = (new FinancingSnapshotRepository())->findByAttempt($result->attemptId);
            if ($snapshot === null) {
                \PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue::flush();

                return $response;
            }

            $process2 = $this->isProcess2($shop);
            $finalStatus = BankStatus::successfulSend($process2);
            if ($process2) {
                $this->persistBankStatus($result->orderReference, $finalStatus);
                $response['redirect_url'] = (new OrderConfirmationUrlBuilder())->build(
                    $this->context,
                    $module,
                    $result->idOrder
                );
            } else {
                $shop['_currency_iso'] = (string) $this->context->currency->iso_code;
                $coordinator = new SmartUcfSessionCoordinator(
                    null,
                    null,
                    null,
                    null,
                    null,
                    $cpClient,
                    $module,
                    $this->context,
                    $module->getControlPanelClient()
                );
                $smart = $coordinator->run($result->attemptId, $shop, false, $snapshot);
                $this->applySmartUcfResultToResponse($response, $smart);
                if ($smart->isFailed()) {
                    $finalStatus = BankStatus::smartUcfFailure();
                } elseif ($smart->isOutcomeUnknown()) {
                    // Order exists; do not claim SmartUCF failure in e-mail status.
                    $finalStatus = BankStatus::successfulSend(false);
                } elseif ($smart->isCreated()) {
                    $finalStatus = BankStatus::successfulSend(false);
                } elseif ($smart->isProcessing()) {
                    // Still in flight — skip e-mail until terminal (leasing marker protects later).
                    return $response;
                }
            }

            try {
                (new FinancingOrderMailDispatcher())->send($snapshot, $result->attemptId, $shop, $finalStatus);
            } catch (\Throwable $emailException) {
                PrestaShopLogger::addLog(
                    'UniPayment popup leasing email failed: ' . get_class($emailException) . ' ' . $emailException->getMessage(),
                    2
                );
                $this->logPopupPostOrderFailure($result->idOrder, $result->orderReference, $emailException, 'leasing-email');
                $response['email_error'] = 'Leasing email could not be sent.';
            }
        } catch (\Throwable $postOrderException) {
            \PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue::flush();
            PrestaShopLogger::addLog(
                'UniPayment popup post-order step failed: ' . get_class($postOrderException) . ' ' . $postOrderException->getMessage(),
                2
            );
            $this->logPopupPostOrderFailure($result->idOrder, $result->orderReference, $postOrderException, 'post-order');
            $response['post_order_error'] = 'The order was created, but additional processing was not completed.';
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
    }

    /**
     * @param array<string, mixed> $response
     */
    private function applySmartUcfResultToResponse(array &$response, SmartUcfCoordinationResult $smart): void
    {
        if ($smart->isCreated()) {
            $redirectUrl = $smart->redirectUrl();
            if (!(new SmartUcfEndpointPolicy())->isTrustedApplicationRedirect($redirectUrl)) {
                $response['step'] = 'outcome_unknown';
                $response['smartucf_error'] = SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN;

                return;
            }
            $response['redirect_url'] = $redirectUrl;
            $response['step'] = 'order_created';

            return;
        }
        if ($smart->isProcessing()) {
            $response['step'] = 'processing';
            $response['message'] = $smart->customerMessage() !== ''
                ? $smart->customerMessage()
                : SmartUcfSessionCoordinator::CUSTOMER_PROCESSING;

            return;
        }
        if ($smart->isOutcomeUnknown()) {
            $response['step'] = 'outcome_unknown';
            $response['smartucf_error'] = $smart->customerMessage() !== ''
                ? $smart->customerMessage()
                : SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN;

            return;
        }
        if ($smart->isFailed()) {
            $response['smartucf_error'] = $smart->customerMessage() !== ''
                ? $smart->customerMessage()
                : SmartUcfSessionCoordinator::CUSTOMER_FAILED;
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
            $safeMessage = $this->sanitizeExceptionMessage($exception);
            PrestaShopLogger::addLog(
                'UniPayment popup selection debug failure: '
                    . json_encode(
                        [
                            'exception' => get_class($exception),
                            'message' => $safeMessage,
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
                ['source' => 'productpopup', 'error' => $safeMessage],
                $safeMessage
            );
        } catch (\Throwable $ignored) {
            unset($ignored);
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function customerValidationMessage(array $errors): string
    {
        if (isset($errors['consents']) && $errors['consents'] !== '') {
            return $errors['consents'];
        }
        foreach ($errors as $message) {
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'Данните не могат да бъдат валидирани.';
    }

    private function sanitizeExceptionMessage(\Throwable $exception): string
    {
        $message = trim(strip_tags($exception->getMessage()));
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $message) ?? $message;
        $message = preg_replace(
            '/\b(popup_submission_token|token|secret|passwd|password)=[^\s&]+/i',
            '$1=[redacted]',
            $message
        ) ?? $message;

        return mb_substr($message, 0, 500);
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

    /** @param array{status_id: string, status_label: string} $status */
    private function persistBankStatus(string $orderReference, array $status): void
    {
        try {
            (new OrderBankStatusRepository())->updateByOrderIdentifier(
                (int) $this->context->shop->id,
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
