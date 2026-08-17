<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;

final class CheckoutPaymentPresenter
{
    /** @var Calculator */
    private $calculator;
    /** @var CartSchemeResolver */
    private $cartResolver;
    /** @var CurrencyGate */
    private $currencyGate;
    /** @var CartSnapshot */
    private $snapshot;
    /** @var CartSnapshotSigner */
    private $signer;
    /** @var ConsentResolver */
    private $consents;

    public function __construct(Calculator $calculator, CartSchemeResolver $cartResolver, CurrencyGate $currencyGate, CartSnapshot $snapshot, CartSnapshotSigner $signer, ConsentResolver $consents)
    {
        $this->calculator = $calculator;
        $this->cartResolver = $cartResolver;
        $this->currencyGate = $currencyGate;
        $this->snapshot = $snapshot;
        $this->signer = $signer;
        $this->consents = $consents;
    }

    /** @param array<string, mixed> $shop @return array<string, mixed>|null */
    public function present(bool $operational, array $shop, CartContext $cart, string $currencyIso): ?array
    {
        if (!$operational || !$this->currencyGate->supports($shop, $currencyIso)) {
            return null;
        }
        $resolution = $this->cartResolver->resolve($shop, $cart);
        $schemes = [];
        foreach ($this->cartResolver->unifiedSchemes($resolution) as $scheme) {
            try {
                $result = $this->calculator->calculateScheme($shop, $cart->total, $scheme);
            } catch (UnavailableSchemeException $exception) {
                continue;
            }
            $schemes[] = [
                'key' => SchemeSelection::key($scheme->type, $scheme->months, $scheme->filterId),
                'scheme_type' => $scheme->type,
                'kop_code' => $scheme->kopCode,
                'months' => $scheme->months,
                'filter_id' => $scheme->filterId,
                'first_installment' => $result->firstInstallment->amount,
                'first_installment_locked' => $result->firstInstallment->locked,
                'financed_amount' => $result->financedAmount,
                'monthly_installment' => $result->monthlyInstallment,
                'total_payable' => $result->totalPayable,
                'glp' => $result->glp,
                'gpr' => $result->gpr,
            ];
        }
        if ($schemes === []) {
            return null;
        }
        $defaultKey = $schemes[0]['key'];
        $preferred = $resolution->standardOffer ?? $resolution->promoOffer;
        if ($preferred !== null) {
            foreach ($schemes as $scheme) {
                if ($scheme['months'] === $preferred->months && $scheme['kop_code'] === $preferred->kopCode) {
                    $defaultKey = $scheme['key'];
                    break;
                }
            }
        }
        $fingerprint = $this->snapshot->fingerprint($cart, $currencyIso);

        return [
            'cart_total' => $cart->total,
            'currency_iso' => strtoupper($currencyIso),
            'schemes' => $schemes,
            'default_scheme_key' => $defaultKey,
            'cart_snapshot' => $this->signer->sign($fingerprint),
            'show_first_installment' => in_array($shop['uni_first_vnoska'] ?? 0, [1, '1', true, 'yes', 'on'], true),
            'process2' => (int) ($shop['uni_proces'] ?? 0) === 1,
            'consents' => $this->consents->normalize($shop),
        ];
    }
}
