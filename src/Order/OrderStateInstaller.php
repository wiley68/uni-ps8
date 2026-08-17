<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderStateInstaller
{
    public const AWAITING = 'UNIPAYMENT_OS_AWAITING';
    public const FAILED = 'UNIPAYMENT_OS_FAILED';

    public function install(): bool
    {
        return $this->create(self::AWAITING, 'Awaiting UniCredit financing', '#4169E1')
            && $this->create(self::FAILED, 'UniCredit submission failed', '#DC3545');
    }

    public function uninstall(): bool
    {
        $result = true;
        foreach ([self::AWAITING, self::FAILED] as $key) {
            $id = (int) \Configuration::get($key);
            $used = $id > 0 && (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o LEFT JOIN `' . _DB_PREFIX_ . 'order_history` h ON h.`id_order`=o.`id_order` WHERE o.`current_state`=' . $id . ' OR h.`id_order_state`=' . $id);
            if ($used) {
                continue;
            }
            if ($id > 0) {
                $state = new \OrderState($id);
                if (\Validate::isLoadedObject($state)) $result = $state->delete() && $result;
            }
            $result = \Configuration::deleteByName($key) && $result;
        }
        return $result;
    }

    private function create(string $key, string $name, string $color): bool
    {
        if ((int) \Configuration::get($key) > 0) return true;
        $state = new \OrderState();
        $state->name = [];
        foreach (\Language::getLanguages(false) as $language) $state->name[(int)$language['id_lang']] = $name;
        $state->color = $color;
        $state->send_email = false;
        $state->module_name = 'unipayment';
        $state->unremovable = false;
        $state->hidden = false;
        $state->logable = false;
        $state->paid = false;
        return $state->add() && \Configuration::updateValue($key, (int) $state->id);
    }
}
