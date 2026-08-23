<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartPopupApplyService;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\NativePrestaShopOrderGateway;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderConfirmationUrlBuilder;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecyclePopupMapper;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostOrderPopupFailureResponse;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

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
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $this->customerValidationMessage($errors),
                'errors' => $errors,
            ];
        } catch (UnavailableSchemeException $exception) {
            return $this->error(422, 'The financing selection is unavailable.');
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment cart popup request failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );

            return $this->error(500, 'Заявката не може да бъде обработена. Моля, опитайте отново.');
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
        $cpApi = $module->getControlPanelClient();
        $cpClient = new ControlPanelOrderClientAdapter($cpApi);
        $orchestrator = new OrderOrchestrator(
            new OrderAttemptRepository(),
            new FinancingSnapshotRepository(),
            new NativePrestaShopOrderGateway($module, $this->context),
            $cpClient,
            new FinancingSnapshotFactory(new SensitiveDataCipher()),
            new ControlPanelOrderPayloadBuilder(),
            new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository()
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

            $lifecycle = (new PostControlPanelLifecycleService())->handle(
                $result,
                $shop,
                new PostControlPanelLifecycleContext(
                    (int) $this->context->shop->id,
                    (string) $this->context->currency->iso_code
                ),
                new SmartUcfSessionCoordinator(
                    null,
                    null,
                    null,
                    null,
                    null,
                    $cpClient,
                    $module,
                    $this->context,
                    $cpApi
                )
            );
            if (ShopConfigurationFlags::isProcess2($shop)) {
                $response['redirect_url'] = (new OrderConfirmationUrlBuilder())->build(
                    $this->context,
                    $module,
                    $result->idOrder
                );
            }
            PostControlPanelLifecyclePopupMapper::apply($response, $lifecycle);

            return $response;
        } catch (ProductPopupValidationException $exception) {
            http_response_code(422);
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $this->customerValidationMessage($errors),
                'errors' => $errors,
            ];
        } catch (UnavailableSchemeException $exception) {
            http_response_code(422);

            return ['success' => false, 'message' => 'The financing selection is unavailable.'];
        } catch (OrderOrchestrationException $exception) {
            PrestaShopLogger::addLog('UniPayment cart popup apply orchestration failed: ' . get_class($exception), 2);
            if ($exception->isPostOrder()) {
                return PostOrderPopupFailureResponse::fromException($exception);
            }
            http_response_code(500);

            return ['success' => false, 'message' => 'The financing request could not be processed. Please try again.'];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment cart popup apply failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );
            http_response_code(500);

            return [
                'success' => false,
                'message' => 'Заявката не може да бъде обработена. Моля, опитайте отново.',
            ];
        }
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
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
}
