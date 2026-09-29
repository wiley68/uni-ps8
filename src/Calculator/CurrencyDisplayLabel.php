<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class CurrencyDisplayLabel
{
    private const DOMAIN = 'Modules.Unipayment.Shop';

    public function forAmount(): string
    {
        if (!class_exists(\Context::class)) {
            return 'евро';
        }

        $context = \Context::getContext();
        if ($context === null || !method_exists($context, 'getTranslator')) {
            return 'евро';
        }

        $translator = $context->getTranslator();

        return $translator !== null && method_exists($translator, 'trans')
            ? (string) $translator->trans('евро', [], self::DOMAIN)
            : 'евро';
    }
}
