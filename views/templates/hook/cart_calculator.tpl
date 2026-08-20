{if isset($unipayment_cart_calculator) && $unipayment_cart_calculator}
    <section
        class="unipayment-product-calculator unipayment-cart-calculator{if $unipayment_cart_calculator.dark_button} unipayment-product-calculator--dark{/if}{if !$unipayment_cart_calculator.show_installment} unipayment-product-calculator--no-installment{/if}{if !$unipayment_cart_calculator.buttons_in_row} unipayment-product-calculator--stacked{/if}"
        data-unipayment-cart-calculator data-endpoint="{$unipayment_cart_calculator_url|escape:'htmlall':'UTF-8'}"
        data-calculator="{$unipayment_cart_calculator_json|escape:'htmlall':'UTF-8'}"
        data-months-label="{l s='%d months' d='Modules.Unipayment.Shop'}"
        data-month-label="{l s='month' d='Modules.Unipayment.Shop'}"
        data-logo-standard="{$unipayment_logo_url|escape:'htmlall':'UTF-8'}"
        data-logo-alternative="{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}"
        style="--unipayment-button-width: {$unipayment_cart_calculator.button_width|intval}px; --unipayment-button-height: {$unipayment_cart_calculator.button_height|intval}px;">
        <p class="unipayment-product-calculator__heading" {if $unipayment_cart_calculator.heading === ''} hidden{/if}>
            {$unipayment_cart_calculator.heading|escape:'htmlall':'UTF-8'}</p>
        <div class="unipayment-product-calculator__buttons">
            {foreach from=$unipayment_offer_types item=offer_type}
                <button type="button"
                    class="unipayment-product-calculator__button unipayment-product-calculator__button--{$offer_type|escape:'htmlall':'UTF-8'}"
                    data-unipayment-cart-offer="{$offer_type|escape:'htmlall':'UTF-8'}"
                    {if !isset($unipayment_cart_calculator.offers[$offer_type])} hidden{/if}>
                    <span class="unipayment-product-calculator__button-content">
                        <span
                            class="unipayment-product-calculator__button-title">{l s='Купи на изплащане' d='Modules.Unipayment.Shop'}</span>
                        <span class="unipayment-product-calculator__button-price"
                            data-unipayment-cart-price>{if isset($unipayment_cart_calculator.offers[$offer_type])}{$unipayment_cart_calculator.offers[$offer_type].installment_label|escape:'htmlall':'UTF-8'}{/if}</span>
                    </span>
                    {if $offer_type === 'promo'}
                        <span class="unipayment-product-calculator__badge" aria-hidden="true">0%</span>
                    {else}
                        <span class="unipayment-product-calculator__logo">
                            <img src="{if $unipayment_cart_calculator.dark_button}{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}{else}{$unipayment_logo_url|escape:'htmlall':'UTF-8'}{/if}"
                                alt="{l s='UniCredit' d='Modules.Unipayment.Shop'}" data-unipayment-logo>
                        </span>
                    {/if}
                </button>
            {/foreach}
        </div>
        <div class="unipayment-cart-calculator__modal" data-unipayment-cart-modal hidden aria-hidden="true">
            <button type="button" class="unipayment-cart-calculator__overlay" data-unipayment-cart-close
                aria-label="{l s='Close' d='Modules.Unipayment.Shop'}"></button>
            <div class="unipayment-cart-calculator__dialog" role="dialog" aria-modal="true">
                <button type="button" class="unipayment-cart-calculator__close" data-unipayment-cart-close
                    aria-label="{l s='Close' d='Modules.Unipayment.Shop'}">&times;</button>
                <h2>{l s='Choose an installment plan for your cart' d='Modules.Unipayment.Shop'}</h2>
                <label>{l s='Repayment period' d='Modules.Unipayment.Shop'}<select
                        data-unipayment-cart-schemes></select></label>
                <dl class="unipayment-cart-calculator__summary">
                    <div>
                        <dt>{l s='Cart total' d='Modules.Unipayment.Shop'}</dt>
                        <dd data-unipayment-cart-value="price"></dd>
                    </div>
                    <div data-unipayment-cart-first>
                        <dt>{l s='First installment' d='Modules.Unipayment.Shop'}</dt>
                        <dd data-unipayment-cart-value="first_installment"></dd>
                    </div>
                    <div>
                        <dt>{l s='Financed amount' d='Modules.Unipayment.Shop'}</dt>
                        <dd data-unipayment-cart-value="financed_amount"></dd>
                    </div>
                    <div>
                        <dt>{l s='Monthly installment' d='Modules.Unipayment.Shop'}</dt>
                        <dd data-unipayment-cart-value="monthly_installment"></dd>
                    </div>
                    <div>
                        <dt>{l s='Total amount due' d='Modules.Unipayment.Shop'}</dt>
                        <dd data-unipayment-cart-value="total_due"></dd>
                    </div>
                    <div>
                        <dt>{l s='Annual interest rate' d='Modules.Unipayment.Shop'}</dt>
                        <dd data-unipayment-cart-value="glp"></dd>
                    </div>
                    <div>
                        <dt>{l s='Annual percentage rate' d='Modules.Unipayment.Shop'}</dt>
                        <dd data-unipayment-cart-value="gpr"></dd>
                    </div>
                </dl>
                <p>{l s='The selected plan will be validated again before purchase.' d='Modules.Unipayment.Shop'}</p>
            </div>
        </div>
    </section>
{/if}
