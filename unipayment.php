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
        $this->author = 'Avalon Ltd';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => constant('_PS_VERSION_'),
        ];

        parent::__construct();

        $this->displayName = $this->trans(
            'УниКредит покупки на Кредит',
            [],
            'Modules.Unipayment.Admin'
        );
        $this->description = $this->trans(
            'Дава възможност на Вашите клиенти да закупуват стока на изплащане с УниКредит.',
            [],
            'Modules.Unipayment.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Сигурни ли сте, че искате да деинсталирате модула? Настройките и локалните данни на UniPayment ще бъдат изтрити. Съществуващите поръчки в PrestaShop няма да бъдат засегнати.',
            [],
            'Modules.Unipayment.Admin'
        );
    }

    /**
     * Display-only currency suffix for UI amounts (Woo: евро / лв. / лева).
     */
    public function getDisplayCurrencyLabel(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        if ($iso === 'EUR') {
            return $this->trans('евро', [], 'Modules.Unipayment.Shop');
        }
        if ($iso === 'BGN') {
            return $this->trans('лв.', [], 'Modules.Unipayment.Shop');
        }

        return $iso;
    }

    /** Dual-button BGN suffix used by installment labels (Woo: лева). */
    public function getDisplayCurrencyLabelDualBgn(): string
    {
        return $this->trans('лева', [], 'Modules.Unipayment.Shop');
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
        $apiNonce = new PrestaShop\Module\Unipayment\Security\ApiNonceRepository();
        $checkoutLock = new PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLockRepository();
        $attempts = new PrestaShop\Module\Unipayment\Order\OrderAttemptRepository();
        $snapshots = new PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository();
        $popupSubmissions = new PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository();
        $orderStates = new PrestaShop\Module\Unipayment\Order\OrderStateInstaller();
        try {
            (new PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore())->ensureProtectionFiles();
        } catch (\Throwable $exception) {
            // Non-fatal: sync will retry protection files at runtime.
        }
        if (
            $repository->install()
            && $cache->install()
            && $debugLog->install()
            && $bankStatus->install()
            && $apiNonce->install()
            && $checkoutLock->install()
            && $attempts->install()
            && $snapshots->install()
            && $popupSubmissions->install()
            && $orderStates->install()
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayShoppingCart')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('sendMailAlterTemplateVars')
            && $this->registerHook('actionEmailSendBefore')
            && $this->registerHook('actionOrderGridDefinitionModifier')
            && $this->registerHook('actionOrderGridQueryBuilderModifier')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('displayFooter')
        ) {
            return true;
        }

        $orderStates->uninstall();
        $popupSubmissions->uninstall();
        $snapshots->uninstall();
        $attempts->uninstall();
        $apiNonce = new PrestaShop\Module\Unipayment\Security\ApiNonceRepository();
        $apiNonce->uninstall();
        $checkoutLock = new PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLockRepository();
        $checkoutLock->uninstall();
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

    /**
     * Native order-confirmation extras: Process 2 leasing table or SmartUCF failure notice.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        $order = $params['order'] ?? null;
        $idOrder = $order instanceof Order ? (int) $order->id : (int) ($params['id_order'] ?? 0);
        if ($idOrder <= 0) {
            return '';
        }

        if ((new PrestaShop\Module\Unipayment\Order\OrderConfirmationSmartUcfFailurePresenter())->shouldDisplay($idOrder)) {
            return $this->display(__FILE__, 'views/templates/hook/order_confirmation_smartucf_failure.tpl');
        }

        $leasingRows = (new PrestaShop\Module\Unipayment\Order\OrderLeasingDetailsPresenter())
            ->thankYouRows($idOrder);
        if ($leasingRows === []) {
            return '';
        }

        $this->context->smarty->assign([
            'unipayment_leasing_rows' => $leasingRows,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_confirmation_leasing.tpl');
    }

    public function uninstall(): bool
    {
        $client = null;
        try {
            if ((new PrestaShop\Module\Unipayment\Security\TokenRepository())->hasToken()) {
                $client = $this->createControlPanelClient();
            }
        } catch (\Throwable $exception) {
            $client = null;
        }

        $cleanup = (new PrestaShop\Module\Unipayment\Uninstall\ModuleDataPurger(null, $client))->purge();
        if (!$cleanup->isSuccess()) {
            return false;
        }

        return parent::uninstall();
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
            'unipayment_sync_bank_rejection_state' => $configurationSubmitted
                ? (bool) Tools::getValue('UNIPAYMENT_SYNC_BANK_REJECTION_STATE', false)
                : $repository->isSyncBankRejectionStateEnabled(),
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
                    return $this->trans('Идентификационният код трябва да е валиден UUID и не може да надвишава 36 символа.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_SECRET_REQUIRED) {
                    return $this->trans('Полето „Секретен код на магазина Ви“ е задължително.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_BUTTON_ACTION_INVALID) {
                    return $this->trans('Моля, изберете валидно действие за бутона Купи.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_BUTTON_TOP_SPACING_INVALID) {
                    return $this->trans('Свободното място над бутона трябва да е цяло число между 0 и 200 px.', [], 'Modules.Unipayment.Admin');
                }

                return $this->trans('Секретният код не може да надвишава 64 символа.', [], 'Modules.Unipayment.Admin');
            }, $errors));
        }

        $credentialsChanged = $repository->getUnicid() !== $unicid || $secret !== '';
        $syncBankRejection = (bool) Tools::getValue('UNIPAYMENT_SYNC_BANK_REJECTION_STATE', false);
        $saved = $repository->save(
            (bool) Tools::getValue('UNIPAYMENT_ENABLED', false),
            $unicid,
            $secret !== '' ? $secret : null,
            (bool) Tools::getValue('UNIPAYMENT_ADVERTISING_ENABLED', false),
            (bool) Tools::getValue('UNIPAYMENT_DEBUG_ENABLED', false),
            $buttonAction,
            (int) $buttonTopSpacing,
            $syncBankRejection
        );

        if (!$saved) {
            return $this->displayError(
                $this->trans('Настройките на модула не могат да бъдат записани.', [], 'Modules.Unipayment.Admin')
            );
        }

        if ($syncBankRejection) {
            (new PrestaShop\Module\Unipayment\Order\OrderStateInstaller())->install();
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
                $this->trans('Данните от банката са обновени успешно.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException $exception) {
            return $this->displayError(
                $this->trans(
                    'Данните от банката са невалидни и не бяха приложени. Предишната конфигурация е запазена.',
                    [],
                    'Modules.Unipayment.Admin'
                )
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\TimeoutException $exception) {
            return $this->displayError(
                $this->trans('Връзката с банката изтече. Моля, опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\ConnectionException $exception) {
            return $this->displayError(
                $this->trans('Неуспешна връзка с банката. Моля, опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException $exception) {
            return $this->displayError(
                $this->trans('Удостоверенията към банката бяха отхвърлени.', [], 'Modules.Unipayment.Admin')
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
                $this->trans('Данните не могат да бъдат обновени. Проверете настройките и опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        }
    }

    private function handleDebugJournalDownload(
        PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository $repository
    ): string {
        $employee = $this->context->employee;
        $submittedToken = (string) Tools::getValue('token', '');
        if (
            !$employee instanceof Employee
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
                $this->trans('Журналът с операции не може да бъде изтеглен.', [], 'Modules.Unipayment.Admin')
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

        $contextCustomer = $this->context->customer;
        $isLogged = $contextCustomer instanceof Customer && $contextCustomer->isLogged();
        $addresses = $isLogged ? $contextCustomer->getAddresses((int) $this->context->language->id) : [];
        $cart = $this->context->cart;
        $customerPrefill = (new PrestaShop\Module\Unipayment\Product\ProductPopupCustomerPrefill())->present(
            $isLogged,
            $isLogged ? [
                'firstname' => (string) $contextCustomer->firstname,
                'lastname' => (string) $contextCustomer->lastname,
                'email' => (string) $contextCustomer->email,
            ] : [],
            is_array($addresses) ? $addresses : [],
            $cart instanceof Cart ? (int) $cart->id_address_delivery : 0,
            $cart instanceof Cart ? (int) $cart->id_address_invoice : 0
        );

        $this->context->smarty->assign([
            'unipayment_calculator' => $calculator,
            'unipayment_popup' => (new PrestaShop\Module\Unipayment\Product\ProductPopupPresenter())->present(
                $shop,
                $repository->getProductButtonAction(),
                $customerPrefill
            ),
            'unipayment_button_top_spacing' => $repository->getButtonTopSpacing(),
            'unipayment_logo_url' => $this->_path . 'views/img/product/uni_logo.svg',
            'unipayment_logo_alternative_url' => $this->_path . 'views/img/product/uni_logo_red.svg',
            'unipayment_popup_badge_url' => $this->_path . 'views/img/product/uni_mini_logo.png',
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
            'unipayment_popup_url' => $this->context->link->getModuleLink($this->name, 'productpopup', ['ajax' => 1], true),
            'unipayment_popup_token' => Tools::getToken(false),
            'unipayment_checkout_url' => $this->context->link->getPageLink('order', true),
            'unipayment_offer_types' => ['standard', 'promo'],
            'unipayment_require_egn' => ((int) ($shop['uni_proces'] ?? 0)) === 1,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/product_calculator.tpl');
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        if (!$this->active || !isset($this->context->controller->php_self)) {
            return;
        }

        if ($this->context->controller->php_self === 'index') {
            if ($this->homepageAdvertisingContext() === null) {
                return;
            }
            $this->context->controller->registerStylesheet(
                'module-unipayment-homepage-advertising',
                'modules/' . $this->name . '/views/css/homepage-advertising.css',
                ['media' => 'all', 'priority' => 150]
            );
            $this->context->controller->registerJavascript(
                'module-unipayment-homepage-advertising',
                'modules/' . $this->name . '/views/js/homepage-advertising.js',
                ['position' => 'bottom', 'priority' => 150]
            );

            return;
        }

        if ($this->context->controller->php_self === 'cart') {
            $this->context->controller->registerStylesheet(
                'module-unipayment-product-calculator',
                'modules/' . $this->name . '/views/css/product-calculator.css',
                ['media' => 'all', 'priority' => 150]
            );
            $this->context->controller->registerStylesheet(
                'module-unipayment-cart-calculator',
                'modules/' . $this->name . '/views/css/cart-calculator.css',
                ['media' => 'all', 'priority' => 151]
            );
            $this->context->controller->registerJavascript(
                'module-unipayment-product-calculator',
                'modules/' . $this->name . '/views/js/product-calculator.js',
                [
                    'position' => 'bottom',
                    'priority' => 150,
                    'version' => $this->assetVersion('views/js/product-calculator.js'),
                ]
            );
            $this->context->controller->registerJavascript(
                'module-unipayment-cart-calculator',
                'modules/' . $this->name . '/views/js/cart-calculator.js',
                [
                    'position' => 'bottom',
                    'priority' => 151,
                    'version' => $this->assetVersion('views/js/cart-calculator.js'),
                ]
            );

            return;
        }

        if ($this->context->controller->php_self === 'order-confirmation') {
            $this->context->controller->registerStylesheet(
                'module-unipayment-order-confirmation',
                'modules/' . $this->name . '/views/css/order-confirmation.css',
                ['media' => 'all', 'priority' => 150]
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
                [
                    'position' => 'bottom',
                    'priority' => 150,
                    'version' => $this->assetVersion('views/js/checkout-payment.js'),
                ]
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
            [
                'position' => 'bottom',
                'priority' => 150,
                'version' => $this->assetVersion('views/js/product-calculator.js'),
            ]
        );
    }

    /**
     * Cache-bust front assets without bumping the module business version.
     */
    private function assetVersion(string $relativePath): string
    {
        $path = _PS_MODULE_DIR_ . $this->name . '/' . ltrim($relativePath, '/');
        $mtime = is_file($path) ? (int) filemtime($path) : 0;

        return $this->version . ($mtime > 0 ? '.' . $mtime : '');
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookDisplayFooter($params = []): string
    {
        if (!isset($this->context->controller->php_self) || $this->context->controller->php_self !== 'index') {
            return '';
        }

        $advertising = $this->homepageAdvertisingContext();
        if ($advertising === null) {
            return '';
        }

        $this->context->smarty->assign([
            'unipayment_advertising' => $advertising,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/homepage_advertising.tpl');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function homepageAdvertisingContext(): ?array
    {
        static $resolved = false;
        static $context = null;
        if ($resolved) {
            return $context;
        }
        $resolved = true;
        $context = null;

        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $gate = new PrestaShop\Module\Unipayment\Advertising\HomepageAdvertisingGate();
        $phpSelf = (string) ($this->context->controller->php_self ?? '');
        if (
            !$gate->allowsAssets(
                $phpSelf,
                (bool) $this->active,
                $repository->isEnabled(),
                $repository->isAdvertisingEnabled(),
                $repository->getUnicid()
            )
        ) {
            return null;
        }

        try {
            $shop = $this->createShopConfigurationService()->get();
        } catch (Throwable $exception) {
            return null;
        }

        $context = (new PrestaShop\Module\Unipayment\Advertising\HomepageAdvertisingPresenter($gate))->present(
            $shop,
            $this->context->isMobile(),
            $this->_path . 'views/img/product/uni_logo.svg'
        );

        return $context;
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

        $contextCustomer = $this->context->customer;
        $isLogged = $contextCustomer instanceof Customer && $contextCustomer->isLogged();
        $addresses = $isLogged ? $contextCustomer->getAddresses((int) $this->context->language->id) : [];
        $cart = $this->context->cart;
        $customerPrefill = (new PrestaShop\Module\Unipayment\Product\ProductPopupCustomerPrefill())->present(
            $isLogged,
            $isLogged ? [
                'firstname' => (string) $contextCustomer->firstname,
                'lastname' => (string) $contextCustomer->lastname,
                'email' => (string) $contextCustomer->email,
            ] : [],
            is_array($addresses) ? $addresses : [],
            $cart instanceof Cart ? (int) $cart->id_address_delivery : 0,
            $cart instanceof Cart ? (int) $cart->id_address_invoice : 0
        );

        $this->context->smarty->assign([
            'unipayment_cart_calculator' => $view,
            'unipayment_cart_calculator_json' => json_encode($view, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'unipayment_cart_calculator_url' => $this->context->link->getModuleLink($this->name, 'cartcalculator', ['ajax' => 1], true),
            'unipayment_cart_popup_url' => $this->context->link->getModuleLink($this->name, 'cartpopup', ['ajax' => 1], true),
            'unipayment_popup_token' => Tools::getToken(false),
            'unipayment_popup' => (new PrestaShop\Module\Unipayment\Product\ProductPopupPresenter())->present(
                $shop,
                'add_to_cart',
                $customerPrefill
            ),
            'unipayment_popup_badge_url' => $this->_path . 'views/img/product/uni_mini_logo.png',
            'unipayment_logo_url' => $this->_path . 'views/img/product/uni_logo.svg',
            'unipayment_logo_alternative_url' => $this->_path . 'views/img/product/uni_logo_red.svg',
            'unipayment_offer_types' => ['standard', 'promo'],
            'unipayment_require_egn' => ((int) ($shop['uni_proces'] ?? 0)) === 1,
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
            $preferenceStore = new PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore();
            $preference = $preferenceStore->load(
                $this->context->cookie,
                (int) $cart->id,
                (int) $this->context->customer->id
            );
            $view = (new PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter(
                $calculator,
                new PrestaShop\Module\Unipayment\Cart\CartSchemeResolver($calculator),
                new PrestaShop\Module\Unipayment\Calculator\CurrencyGate(),
                new PrestaShop\Module\Unipayment\Checkout\CartSnapshot(),
                new PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner(_COOKIE_KEY_),
                new PrestaShop\Module\Unipayment\Checkout\ConsentResolver()
            ))->present(true, $shop, $cartContext, (string) $currency->iso_code, $preference);
            if ($preference !== null && empty($view['preselect_payment'])) {
                $preferenceStore->clear($this->context->cookie);
            }
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
            'unipayment_checkout_calculate_url' => $this->context->link->getModuleLink($this->name, 'checkoutcalculate', ['ajax' => 1], true),
        ]);
        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->trans('Купи на изплащане с УниКредит', [], 'Modules.Unipayment.Shop'))
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
    /** @param array<string, mixed> $params */
    public function hookActionEmailSendBefore(array $params): bool
    {
        return PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue::intercept($params);
    }

    /** @param array<string, mixed> $params */
    public function hookSendMailAlterTemplateVars(array $params): void
    {
        if (($params['template'] ?? '') !== 'order_conf') {
            return;
        }

        if (!isset($params['template_vars']) || !is_array($params['template_vars'])) {
            return;
        }

        $templateVars = &$params['template_vars'];
        $leasingHtml = trim((string) ($templateVars['{unipayment_leasing_html}'] ?? ''));
        $leasingTxt = trim((string) ($templateVars['{unipayment_leasing_txt}'] ?? ''));

        if ($leasingHtml !== '') {
            $templateVars['{products}'] = (string) ($templateVars['{products}'] ?? '') . $leasingHtml;
        }

        if ($leasingTxt !== '') {
            $templateVars['{products_txt}'] = (string) ($templateVars['{products_txt}'] ?? '') . $leasingTxt;
        }
    }

    /** @param array<string, mixed> $params */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $leasingRows = (new PrestaShop\Module\Unipayment\Order\OrderLeasingDetailsPresenter())
            ->rowsForOrder($idOrder);
        if ($leasingRows === []) {
            return '';
        }

        $this->context->smarty->assign([
            'unipayment_leasing_rows' => $leasingRows,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order_financing_details.tpl');
    }

    /** @param array<string, mixed> $params */
    public function hookActionOrderGridDefinitionModifier(array $params): void
    {
        $definition = $params['definition'] ?? null;
        if (!$definition instanceof PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinitionInterface) {
            return;
        }

        $columns = $definition->getColumns();

        $column = (new PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn('unipayment_bank_status'))
            ->setName('UniCredit статус')
            ->setOptions([
                'field' => 'unipayment_bank_status',
            ]);

        try {
            $columns->addAfter('osname', $column);
        } catch (Throwable $exception) {
            $columns->add($column);
        }
    }

    /** @param array<string, mixed> $params */
    public function hookActionOrderGridQueryBuilderModifier(array $params): void
    {
        $searchQueryBuilder = $params['search_query_builder'] ?? null;
        if (!$searchQueryBuilder instanceof Doctrine\DBAL\Query\QueryBuilder) {
            return;
        }

        $bankStatusTable = _DB_PREFIX_ . PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository::TABLE;
        $searchQueryBuilder->leftJoin('o', $bankStatusTable, 'unipayment_bs', 'unipayment_bs.id_order = o.id_order');
        $searchQueryBuilder->addSelect("COALESCE(unipayment_bs.status_label, '') AS unipayment_bank_status");
    }
}
