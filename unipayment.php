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
        if ($repository->install()) {
            return true;
        }

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

        $configurationRemoved = $repository->uninstall();
        $moduleUninstalled = parent::uninstall();

        return $configurationRemoved && $moduleUninstalled;
    }

    public function getContent(): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $tokens = new PrestaShop\Module\Unipayment\Security\TokenRepository();
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
            'unipayment_cache_status' => $this->trans(
                'No Control Panel configuration has been cached yet.',
                [],
                'Modules.Unipayment.Admin'
            ),
            'unipayment_last_refresh' => $this->trans('Never', [], 'Modules.Unipayment.Admin'),
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
                $client->refreshToken();
                $client->getShop();

                return $this->displayConfirmation(
                    $this->trans('The token was refreshed and the shop data was retrieved successfully.', [], 'Modules.Unipayment.Admin')
                );
            }

            $client->login();
            $client->getShop();

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
}
