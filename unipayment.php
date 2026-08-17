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
        if ($repository->install()
            && $cache->install()
            && $debugLog->install()
            && $bankStatus->install()
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayShoppingCart')
            && $this->registerHook('actionFrontControllerSetMedia')
        ) {
            return true;
        }

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

        $bankStatusRemoved = $bankStatus->uninstall();
        $debugLogRemoved = $debugLog->uninstall();
        $cacheRemoved = $cache->uninstall();
        $configurationRemoved = $repository->uninstall();
        $moduleUninstalled = parent::uninstall();

        return $bankStatusRemoved
            && $debugLogRemoved
            && $cacheRemoved
            && $configurationRemoved
            && $moduleUninstalled;
    }

    public function getContent(): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $tokens = new PrestaShop\Module\Unipayment\Security\TokenRepository();
        $cacheService = $this->createShopConfigurationService();
        $output = '';

        if (Tools::isSubmit('submitUnipaymentConfiguration')) {
            $output .= $this->handleConfigurationSubmit($repository);
        }

        if (Tools::isSubmit('submitUnipaymentRefresh')) {
            $output .= $this->handleControlPanelAction('refresh');
        }

        if (Tools::isSubmit('submitUnipaymentConnect')) {
            $output .= $this->handleControlPanelAction('connect');
        }

        if (Tools::isSubmit('submitUnipaymentLogout')) {
            $output .= $this->handleControlPanelAction('logout');
        }

        $hasCredentials = $repository->getUnicid() !== '' && $repository->hasSecret();
        $configurationSubmitted = Tools::isSubmit('submitUnipaymentConfiguration');

        $cacheMetadata = $cacheService->getMetadata();
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
            'unipayment_has_secret' => $repository->hasSecret(),
            'unipayment_secret_readable' => $repository->isSecretReadable(),
            'unipayment_connection_status' => $tokens->hasToken()
                ? sprintf(
                    $this->trans('Authenticated; token expires at %s.', [], 'Modules.Unipayment.Admin'),
                    date('Y-m-d H:i:s', $tokens->getExpiresAt())
                )
                : ($hasCredentials
                    ? $this->trans('Credentials saved; not authenticated.', [], 'Modules.Unipayment.Admin')
                    : $this->trans('Credentials incomplete.', [], 'Modules.Unipayment.Admin')),
            'unipayment_cache_status' => $cacheMetadata === null
                ? $this->trans('No Control Panel configuration has been cached yet.', [], 'Modules.Unipayment.Admin')
                : ($cacheMetadata['is_fresh']
                    ? sprintf(
                        $this->trans('Fresh; expires at %s UTC.', [], 'Modules.Unipayment.Admin'),
                        $cacheMetadata['expires_at']
                    )
                    : sprintf(
                        $this->trans('Expired at %s UTC.', [], 'Modules.Unipayment.Admin'),
                        $cacheMetadata['expires_at']
                    )),
            'unipayment_last_refresh' => $cacheMetadata === null
                ? $this->trans('Never', [], 'Modules.Unipayment.Admin')
                : $cacheMetadata['fetched_at'] . ' UTC',
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
        $errors = $validator->validate($unicid, $secret, $repository->hasSecret());

        if ($errors !== []) {
            return $this->displayError(array_map(function (string $error): string {
                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_UNICID_REQUIRED) {
                    return $this->trans('UNICID is required.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_UNICID_INVALID) {
                    return $this->trans('UNICID must be a valid UUID with no more than 36 characters.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_SECRET_REQUIRED) {
                    return $this->trans('Secret is required.', [], 'Modules.Unipayment.Admin');
                }

                return $this->trans('Secret cannot exceed 64 characters.', [], 'Modules.Unipayment.Admin');
            }, $errors));
        }

        $credentialsChanged = $repository->getUnicid() !== $unicid || $secret !== '';
        $saved = $repository->save(
            (bool) Tools::getValue('UNIPAYMENT_ENABLED', false),
            $unicid,
            $secret !== '' ? $secret : null
        );

        if (!$saved) {
            return $this->displayError(
                $this->trans('The module configuration could not be saved.', [], 'Modules.Unipayment.Admin')
            );
        }

        if ($credentialsChanged) {
            $tokens->invalidate();
            (new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache())->clear();
        }

        return $this->displayConfirmation(
            $this->trans('Settings updated successfully.', [], 'Modules.Unipayment.Admin')
        );
    }

    private function handleControlPanelAction(string $action): string
    {
        $client = $this->createControlPanelClient();

        try {
            if ($action === 'logout') {
                $client->logout();

                return $this->displayConfirmation(
                    $this->trans('The Control Panel session was closed.', [], 'Modules.Unipayment.Admin')
                );
            }

            if ($action === 'refresh') {
                $this->createShopConfigurationService($client)->get(true);

                return $this->displayConfirmation(
                    $this->trans('The shop configuration cache was refreshed successfully.', [], 'Modules.Unipayment.Admin')
                );
            }

            $client->login();
            $this->createShopConfigurationService($client)->get(true);

            return $this->displayConfirmation(
                $this->trans('Connection to the Control Panel was successful.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\TimeoutException $exception) {
            return $this->displayError(
                $this->trans('The Control Panel request timed out.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\ConnectionException $exception) {
            return $this->displayError(
                $this->trans('Could not connect to the Control Panel.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException $exception) {
            return $this->displayError(
                $this->trans('The Control Panel rejected the shop credentials.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException $exception) {
            return $this->displayError(
                $this->trans('The Control Panel returned an unreadable response.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException $exception) {
            return $this->displayError(
                $this->trans('The Control Panel returned an invalid response.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\HttpException $exception) {
            return $this->displayError(sprintf(
                $this->trans('The Control Panel returned HTTP status %d.', [], 'Modules.Unipayment.Admin'),
                $exception->getStatusCode()
            ));
        }
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
