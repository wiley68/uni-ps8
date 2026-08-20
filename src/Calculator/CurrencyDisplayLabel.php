<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/**
 * Translates display currency suffixes (EUR / BGN) via Modules.Unipayment.Shop.
 *
 * ISO codes remain the English source strings for the PrestaShop catalog; merchants
 * can translate e.g. EUR → евро and BGN → лв. without changing business ISO logic.
 *
 * Catalog registration of the source strings lives on Unipayment::getDisplayCurrencyLabel().
 */
final class CurrencyDisplayLabel
{
    private const DOMAIN = 'Modules.Unipayment.Shop';

    public function forIso(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        if ($iso !== 'EUR' && $iso !== 'BGN') {
            return $iso;
        }

        $translator = $this->translator();
        if ($translator === null) {
            return $iso;
        }

        if ($iso === 'EUR') {
            return (string) $translator->trans('EUR', [], self::DOMAIN);
        }

        return (string) $translator->trans('BGN', [], self::DOMAIN);
    }

    /**
     * @return object|null Translator with a trans() method, or null outside PS context
     */
    private function translator()
    {
        if (!class_exists(\Context::class)) {
            return null;
        }

        $context = \Context::getContext();
        if ($context === null || !method_exists($context, 'getTranslator')) {
            return null;
        }

        $translator = $context->getTranslator();
        if ($translator === null || !method_exists($translator, 'trans')) {
            return null;
        }

        return $translator;
    }
}
