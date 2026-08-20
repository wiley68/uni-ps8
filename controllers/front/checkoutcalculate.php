<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentCalculator;

final class UnipaymentCheckoutCalculateModuleFrontController extends ModuleFrontController
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
            return $this->error(403, 'Invalid checkout calculate request.');
        }

        $schemeKey = trim((string) Tools::getValue('scheme_key', ''));
        $kopCode = trim((string) Tools::getValue('kop_code', ''));
        $firstRaw = Tools::getValue('first_installment', 0);
        if ($schemeKey === '' || $kopCode === '' || !is_numeric($firstRaw)) {
            return $this->error(400, 'Invalid checkout selection.');
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
            $calculation = (new CheckoutPaymentCalculator($calculator, new CartSchemeResolver($calculator)))->calculate(
                $shop,
                (new CartContextFactory())->createForCheckout($this->context->cart),
                (string) $this->context->currency->iso_code,
                [
                    'scheme_key' => $schemeKey,
                    'kop_code' => $kopCode,
                    'first_installment' => $firstRaw,
                ]
            );

            return ['success' => true, 'calculation' => $calculation];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment checkout calculate failed: ' . get_class($exception), 2);

            return $this->error(422, 'The financing selection is unavailable.');
        }
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
