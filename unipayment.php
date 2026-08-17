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
    public function __construct()
    {
        $this->name = 'unipayment';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
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
            'Foundation for UniCredit purchases on credit.',
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
        return parent::install();
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return parent::uninstall();
    }
}
