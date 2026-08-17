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

        return $repository->uninstall() && parent::uninstall();
    }

    public function getContent(): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $output = '';

        if (Tools::isSubmit('submitUnipaymentConfiguration')) {
            $output .= $this->handleConfigurationSubmit($repository);
        }

        if (Tools::isSubmit('submitUnipaymentRefresh')) {
            $output .= $this->displayWarning(
                $this->trans(
                    'The manual refresh will become available after the Control Panel connection is implemented.',
                    [],
                    'Modules.Unipayment.Admin'
                )
            );
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
            'unipayment_connection_status' => $hasCredentials
                ? $this->trans('Credentials saved; connection not checked yet.', [], 'Modules.Unipayment.Admin')
                : $this->trans('Credentials incomplete.', [], 'Modules.Unipayment.Admin'),
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

        return $this->displayConfirmation(
            $this->trans('Settings updated successfully.', [], 'Modules.Unipayment.Admin')
        );
    }
}
