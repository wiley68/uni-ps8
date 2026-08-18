<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

class Unipayment extends PaymentModule
{
    /** @var int Whether the module exposes a configuration page. */
    public $is_configurable = 1;

    public function __construct()
    {
        $this->name = 'unipayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->author = 'wiley68';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => constant('_PS_VERSION_'),
        ];

        parent::__construct();

        $this->displayName = $this->trans(
            'UniCredit Credit Calculator',
            [],
            'Modules.Unipayment.Admin'
        );
        $this->description = $this->trans(
            'Allows your customers to purchase goods in installments with UniCredit.',
            [],
            'Modules.Unipayment.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall the module?',
            [],
            'Modules.Unipayment.Admin'
        );
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $cache = new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache();
        $debugLog = new PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository();
        $bankStatus = new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository();
        $attempts = new PrestaShop\Module\Unipayment\Order\OrderAttemptRepository();
        $snapshots = new PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository();
        $orderStates = new PrestaShop\Module\Unipayment\Order\OrderStateInstaller();
        if ($repository->install()
            && $cache->install()
            && $debugLog->install()
            && $bankStatus->install()
            && $attempts->install()
            && $snapshots->install()
            && $orderStates->install()
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayShoppingCart')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('actionFrontControllerSetMedia')
        ) {
            return true;
        }

        $orderStates->uninstall();
        $snapshots->uninstall();
        $attempts->uninstall();
        $bankStatus->uninstall();
        $debugLog->uninstall();
        $cache->uninstall();
        $repository->uninstall();
        parent::uninstall();

        return false;
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $cache = new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache();
        $debugLog = new PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository();
        $bankStatus = new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository();
        $attempts = new PrestaShop\Module\Unipayment\Order\OrderAttemptRepository();
        $snapshots = new PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository();
        $orderStates = new PrestaShop\Module\Unipayment\Order\OrderStateInstaller();

        $orderStatesRemoved = $orderStates->uninstall();
        $snapshotsRemoved = $snapshots->uninstall();
        $attemptsRemoved = $attempts->uninstall();
        $bankStatusRemoved = $bankStatus->uninstall();
        $debugLogRemoved = $debugLog->uninstall();
        $cacheRemoved = $cache->uninstall();
        $configurationRemoved = $repository->uninstall();
        $moduleUninstalled = parent::uninstall();

        return $bankStatusRemoved
            && $attemptsRemoved
            && $snapshotsRemoved
            && $orderStatesRemoved
            && $debugLogRemoved
            && $cacheRemoved
            && $configurationRemoved
            && $moduleUninstalled;
    }

    public function getContent(): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $output = '';

        if (Tools::isSubmit('submitUnipaymentDownloadJournal')) {
            $output .= $this->handleDebugJournalDownload($repository);
        }

        if (Tools::isSubmit('submitUnipaymentConfiguration')) {
            $output .= $this->handleConfigurationSubmit($repository);
        }

        if (Tools::isSubmit('submitUnipaymentRefresh')) {
            $output .= $this->handleBankDataRefresh();
        }

        $configurationSubmitted = Tools::isSubmit('submitUnipaymentConfiguration');
        $this->context->smarty->assign([
            'unipayment_form_action' => $this->context->link->getAdminLink(
                'AdminModules',
                true,
                [],
                ['configure' => $this->name]
            ),
            'unipayment_enabled' => $configurationSubmitted
                ? (bool) Tools::getValue('UNIPAYMENT_ENABLED', false)
                : $repository->isEnabled(),
            'unipayment_unicid' => $configurationSubmitted
                ? trim((string) Tools::getValue('UNIPAYMENT_UNICID', ''))
                : $repository->getUnicid(),
            'unipayment_advertising_enabled' => $configurationSubmitted
                ? (bool) Tools::getValue('UNIPAYMENT_ADVERTISING_ENABLED', false)
                : $repository->isAdvertisingEnabled(),
            'unipayment_debug_enabled' => $configurationSubmitted
                ? (bool) Tools::getValue('UNIPAYMENT_DEBUG_ENABLED', false)
                : $repository->isDebugEnabled(),
            'unipayment_product_button_action' => $configurationSubmitted
                ? (string) Tools::getValue(
                    'UNIPAYMENT_PRODUCT_BUTTON_ACTION',
                    PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository::DEFAULT_PRODUCT_BUTTON_ACTION
                )
                : $repository->getProductButtonAction(),
            'unipayment_button_top_spacing' => $configurationSubmitted
                ? (string) Tools::getValue('UNIPAYMENT_BUTTON_TOP_SPACING', '0')
                : (string) $repository->getButtonTopSpacing(),
            'unipayment_has_secret' => $repository->hasSecret(),
            'unipayment_secret_readable' => $repository->isSecretReadable(),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
    }

    private function handleConfigurationSubmit(
        PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository $repository
    ): string {
        $validator = new PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator();
        $tokens = new PrestaShop\Module\Unipayment\Security\TokenRepository();
        $unicid = trim((string) Tools::getValue('UNIPAYMENT_UNICID', ''));
        $secret = trim((string) Tools::getValue('UNIPAYMENT_SECRET', ''));
        $buttonAction = (string) Tools::getValue('UNIPAYMENT_PRODUCT_BUTTON_ACTION', '');
        $buttonTopSpacing = Tools::getValue('UNIPAYMENT_BUTTON_TOP_SPACING', '');
        $errors = $validator->validate(
            $unicid,
            $secret,
            $repository->hasSecret(),
            $buttonAction,
            $buttonTopSpacing
        );

        if ($errors !== []) {
            return $this->displayError(array_map(function (string $error): string {
                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_UNICID_REQUIRED) {
                    return $this->trans('Полето „Уникален идентификационен код на магазина Ви“ е задължително.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_UNICID_INVALID) {
                    return $this->trans('Идентификационният код трябва да бъде валиден UUID и не може да надвишава 36 символа.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_SECRET_REQUIRED) {
                    return $this->trans('Полето „Секретен код на магазина Ви“ е задължително.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_BUTTON_ACTION_INVALID) {
                    return $this->trans('Моля изберете валидно действие за бутона Купи.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_BUTTON_TOP_SPACING_INVALID) {
                    return $this->trans('Свободното място над бутона трябва да бъде цяло число между 0 и 200 px.', [], 'Modules.Unipayment.Admin');
                }

                return $this->trans('Секретният код не може да надвишава 64 символа.', [], 'Modules.Unipayment.Admin');
            }, $errors));
        }

        $credentialsChanged = $repository->getUnicid() !== $unicid || $secret !== '';
        $saved = $repository->save(
            (bool) Tools::getValue('UNIPAYMENT_ENABLED', false),
            $unicid,
            $secret !== '' ? $secret : null,
            (bool) Tools::getValue('UNIPAYMENT_ADVERTISING_ENABLED', false),
            (bool) Tools::getValue('UNIPAYMENT_DEBUG_ENABLED', false),
            $buttonAction,
            (int) $buttonTopSpacing
        );

        if (!$saved) {
            return $this->displayError(
                $this->trans('Настройките на модула не можаха да бъдат записани.', [], 'Modules.Unipayment.Admin')
            );
        }

        if ($credentialsChanged) {
            $tokens->invalidate();
            (new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache())->clear();
        }

        return $this->displayConfirmation(
            $this->trans('Настройките са записани успешно.', [], 'Modules.Unipayment.Admin')
        );
    }

    private function handleBankDataRefresh(): string
    {
        $client = $this->createControlPanelClient();

        try {
            $this->createShopConfigurationService($client)->get(true);

            return $this->displayConfirmation(
                $this->trans('Данните са обновени успешно.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\TimeoutException $exception) {
            return $this->displayError(
                $this->trans('Времето за връзка с банката изтече. Моля опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\ConnectionException $exception) {
            return $this->displayError(
                $this->trans('Неуспешна връзка с банката. Моля опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException $exception) {
            return $this->displayError(
                $this->trans('Данните за достъп до банката бяха отхвърлени.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException $exception) {
            return $this->displayError(
                $this->trans('Банката върна нечетим отговор.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException $exception) {
            return $this->displayError(
                $this->trans('Банката върна невалиден отговор.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\HttpException $exception) {
            return $this->displayError(
                $this->trans('Данните не можаха да бъдат обновени. Проверете настройките и опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        }
    }

    private function handleDebugJournalDownload(
        PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository $repository
    ): string {
        $employee = $this->context->employee;
        $submittedToken = (string) Tools::getValue('token', '');
        if (!$employee instanceof Employee
            || !Validate::isLoadedObject($employee)
            || !hash_equals(Tools::getAdminTokenLite('AdminModules'), $submittedToken)
        ) {
            return $this->displayError(
                $this->trans('Нямате право да изтеглите журнала с операции.', [], 'Modules.Unipayment.Admin')
            );
        }

        $journal = new PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal(
            $repository,
            new PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository()
        );
        try {
            $json = json_encode(
                $journal->buildExport(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            return $this->displayError(
                $this->trans('Журналът с операции не можа да бъде изтеглен.', [], 'Modules.Unipayment.Admin')
            );
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="unipayment-smartucf-log-' . gmdate('Ymd-His') . '.json"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    private function createControlPanelClient(): PrestaShop\Module\Unipayment\Api\ControlPanelClient
    {
        $shopUrl = rtrim(Tools::getShopDomainSsl(true) . __PS_BASE_URI__, '/');

        return new PrestaShop\Module\Unipayment\Api\ControlPanelClient(
            new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository(),
            new PrestaShop\Module\Unipayment\Security\TokenRepository(),
            new PrestaShop\Module\Unipayment\Api\CurlHttpTransport(),
            $shopUrl
        );
    }

    private function createShopConfigurationService(
        ?PrestaShop\Module\Unipayment\Api\ControlPanelClient $client = null
    ): PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $tokens = new PrestaShop\Module\Unipayment\Security\TokenRepository();

        return new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService(
            $repository,
            new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache(),
            $client ?? $this->createControlPanelClient(),
            $tokens
        );
    }

    public function getShopConfigurationService(): PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService
    {
        return $this->createShopConfigurationService();
    }

    public function getControlPanelClient(): PrestaShop\Module\Unipayment\Api\ControlPanelClient
    {
        return $this->createControlPanelClient();
    }

    /** @param array<string, mixed> $params */
    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        if (!$this->active || !$repository->isEnabled()) {
            return '';
        }

        $productId = $this->resolveHookProductId($params);
        $productAttributeId = max(0, (int) Tools::getValue('id_product_attribute', 0));
        if ($productId <= 0) {
            return '';
        }

        try {
            $shop = $this->createShopConfigurationService()->get();
            $product = (new PrestaShop\Module\Unipayment\Product\ProductContextFactory())
                ->create($productId, $productAttributeId);
            $calculator = (new PrestaShop\Module\Unipayment\Product\ProductCalculatorPresenter(
                new PrestaShop\Module\Unipayment\Calculator\Calculator()
            ))->present($shop, $product, (string) $this->context->currency->iso_code);
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment product calculator could not be rendered: ' . get_class($exception), 2);

            return '';
        }

        if ($calculator === null) {
            return '';
        }

        $this->context->smarty->assign([
            'unipayment_calculator' => $calculator,
            'unipayment_button_top_spacing' => $repository->getButtonTopSpacing(),
            'unipayment_logo_url' => $this->_path . 'views/img/product/uni_logo.svg',
            'unipayment_logo_alternative_url' => $this->_path . 'views/img/product/uni_logo_red.svg',
            'unipayment_calculator_json' => json_encode(
                $calculator,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
            'unipayment_calculator_url' => $this->context->link->getModuleLink(
                $this->name,
                'productcalculator',
                ['ajax' => 1],
                true
            ),
            'unipayment_offer_types' => ['standard', 'promo'],
        ]);

        return $this->display(__FILE__, 'views/templates/hook/product_calculator.tpl');
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        if (!$this->active || !isset($this->context->controller->php_self)) {
            return;
        }

        if ($this->context->controller->php_self === 'cart') {
            $this->context->controller->registerStylesheet(
                'module-unipayment-cart-calculator',
                'modules/' . $this->name . '/views/css/cart-calculator.css',
                ['media' => 'all', 'priority' => 150]
            );
            $this->context->controller->registerJavascript(
                'module-unipayment-cart-calculator',
                'modules/' . $this->name . '/views/js/cart-calculator.js',
                ['position' => 'bottom', 'priority' => 150]
            );

            return;
        }

        if ($this->context->controller->php_self === 'order') {
            $this->context->controller->registerStylesheet(
                'module-unipayment-checkout-payment',
                'modules/' . $this->name . '/views/css/checkout-payment.css',
                ['media' => 'all', 'priority' => 150]
            );
            $this->context->controller->registerJavascript(
                'module-unipayment-checkout-payment',
                'modules/' . $this->name . '/views/js/checkout-payment.js',
                ['position' => 'bottom', 'priority' => 150]
            );

            return;
        }

        if ($this->context->controller->php_self !== 'product') {
            return;
        }

        $this->context->controller->registerStylesheet(
            'module-unipayment-product-calculator',
            'modules/' . $this->name . '/views/css/product-calculator.css',
            ['media' => 'all', 'priority' => 150]
        );
        $this->context->controller->registerJavascript(
            'module-unipayment-product-calculator',
            'modules/' . $this->name . '/views/js/product-calculator.js',
            ['position' => 'bottom', 'priority' => 150]
        );
    }

    /** @param array<string, mixed> $params */
    public function hookDisplayShoppingCart(array $params): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        if (!$this->active || !$repository->isEnabled()) {
            return '';
        }
        try {
            $shop = $this->createShopConfigurationService()->get();
            $calculator = new PrestaShop\Module\Unipayment\Calculator\Calculator();
            $view = (new PrestaShop\Module\Unipayment\Cart\CartCalculatorPresenter(
                new PrestaShop\Module\Unipayment\Cart\CartSchemeResolver($calculator),
                $calculator
            ))->present(
                $shop,
                (new PrestaShop\Module\Unipayment\Cart\CartContextFactory())->create($this->context->cart),
                (string) $this->context->currency->iso_code
            );
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment cart calculator could not be rendered: ' . get_class($exception), 2);

            return '';
        }
        if ($view === null) {
            return '';
        }
        $this->context->smarty->assign([
            'unipayment_cart_calculator' => $view,
            'unipayment_cart_calculator_json' => json_encode($view, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'unipayment_cart_calculator_url' => $this->context->link->getModuleLink($this->name, 'cartcalculator', ['ajax' => 1], true),
            'unipayment_offer_types' => ['standard', 'promo'],
        ]);

        return $this->display(__FILE__, 'views/templates/hook/cart_calculator.tpl');
    }

    /** @param array<string, mixed> $params @return array<int, PrestaShop\PrestaShop\Core\Payment\PaymentOption> */
    public function hookPaymentOptions(array $params): array
    {
        $cart = $params['cart'] ?? $this->context->cart;
        if (!$this->active || !$cart instanceof Cart) {
            return [];
        }
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        if (!$repository->isEnabled()) {
            return [];
        }
        try {
            $shop = $this->createShopConfigurationService()->get();
            $calculator = new PrestaShop\Module\Unipayment\Calculator\Calculator();
            $cartContext = (new PrestaShop\Module\Unipayment\Cart\CartContextFactory())->createForCheckout($cart);
            $currency = $this->context->currency;
            if (!$currency instanceof Currency && (int) $cart->id_currency > 0) {
                $currency = new Currency((int) $cart->id_currency);
            }
            if (!$currency instanceof Currency || !Validate::isLoadedObject($currency)) {
                return [];
            }
            $view = (new PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter(
                $calculator,
                new PrestaShop\Module\Unipayment\Cart\CartSchemeResolver($calculator),
                new PrestaShop\Module\Unipayment\Calculator\CurrencyGate(),
                new PrestaShop\Module\Unipayment\Checkout\CartSnapshot(),
                new PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner(_COOKIE_KEY_),
                new PrestaShop\Module\Unipayment\Checkout\ConsentResolver()
            ))->present(true, $shop, $cartContext, (string) $currency->iso_code);
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment checkout option could not be rendered: ' . get_class($exception), 2);

            return [];
        }
        if ($view === null) {
            return [];
        }
        $this->context->smarty->assign([
            'unipayment_checkout' => $view,
            'unipayment_checkout_json' => json_encode($view, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'unipayment_checkout_token' => Tools::getToken(false),
            'unipayment_checkout_action' => $this->context->link->getModuleLink($this->name, 'validatecheckout', [], true),
        ]);
        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->trans('UniCredit purchases on credit', [], 'Modules.Unipayment.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validatecheckout', [], true))
            ->setForm($this->fetch('module:unipayment/views/templates/hook/checkout_payment.tpl'));

        return [$option];
    }

    /** @return array<string, string> */
    public function getCheckoutCustomerData(): array
    {
        $customer = $this->context->customer;
        $addressId = (int) ($this->context->cart->id_address_invoice ?: $this->context->cart->id_address_delivery);
        $address = $addressId > 0 ? new Address($addressId) : null;

        return [
            'first_name' => $address instanceof Address && Validate::isLoadedObject($address) ? (string) $address->firstname : (string) $customer->firstname,
            'last_name' => $address instanceof Address && Validate::isLoadedObject($address) ? (string) $address->lastname : (string) $customer->lastname,
            'address' => $address instanceof Address && Validate::isLoadedObject($address) ? trim((string) $address->address1 . ' ' . (string) $address->address2) : '',
            'phone' => $address instanceof Address && Validate::isLoadedObject($address) ? (string) ($address->phone_mobile ?: $address->phone) : '',
            'email' => (string) $customer->email,
        ];
    }

    /** @param array<string, mixed> $params */
    private function resolveHookProductId(array $params): int
    {
        $product = $params['product'] ?? null;
        if (is_object($product) && isset($product->id)) {
            return (int) $product->id;
        }
        if (is_array($product)) {
            return (int) ($product['id_product'] ?? $product['id'] ?? 0);
        }

        return max(0, (int) Tools::getValue('id_product', 0));
    }

    /** @param array<string, mixed> $params */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $bankStatus = (new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository())
            ->findByOrderId($idOrder);
        if ($bankStatus === null) {
            return '';
        }

        $this->context->smarty->assign([
            'unipayment_bank_status_id' => $bankStatus['status_id'],
            'unipayment_bank_status_label' => $bankStatus['status_label'],
            'unipayment_bank_status_updated_at' => $bankStatus['updated_at'] . ' UTC',
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order_bank_status.tpl');
    }
}
