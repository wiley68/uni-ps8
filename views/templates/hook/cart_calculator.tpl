{if isset($unipayment_cart_calculator) && $unipayment_cart_calculator}
<section class="unipayment-cart-calculator{if $unipayment_cart_calculator.dark_button} unipayment-cart-calculator--dark{/if}{if !$unipayment_cart_calculator.buttons_in_row} unipayment-cart-calculator--stacked{/if}"
  data-unipayment-cart-calculator data-endpoint="{$unipayment_cart_calculator_url|escape:'htmlall':'UTF-8'}"
  data-calculator="{$unipayment_cart_calculator_json|escape:'htmlall':'UTF-8'}"
  data-months-label="{l s='%d months' d='Modules.Unipayment.Shop'}"
  data-month-label="{l s='month' d='Modules.Unipayment.Shop'}"
  style="--unipayment-button-width: {$unipayment_cart_calculator.button_width|intval}px; --unipayment-button-height: {$unipayment_cart_calculator.button_height|intval}px;">
  <h2>{l s='Installment options for your cart' d='Modules.Unipayment.Shop'}</h2>
  <div class="unipayment-cart-calculator__buttons">
    {foreach from=$unipayment_offer_types item=offer_type}
      <button type="button" class="unipayment-cart-calculator__button" data-unipayment-cart-offer="{$offer_type|escape:'htmlall':'UTF-8'}"{if !isset($unipayment_cart_calculator.offers[$offer_type])} hidden{/if}>
        <strong>{if $offer_type === 'promo'}{l s='Promo installments' d='Modules.Unipayment.Shop'}{else}{l s='Buy on installments' d='Modules.Unipayment.Shop'}{/if}</strong>
        {if $unipayment_cart_calculator.show_installment}<span data-unipayment-cart-price>{if isset($unipayment_cart_calculator.offers[$offer_type])}{$unipayment_cart_calculator.offers[$offer_type].monthly_installment|string_format:'%.2f'|escape:'htmlall':'UTF-8'} {$unipayment_cart_calculator.currency_iso|escape:'htmlall':'UTF-8'} / {l s='month' d='Modules.Unipayment.Shop'}{/if}</span>{/if}
      </button>
    {/foreach}
  </div>
  <div class="unipayment-cart-calculator__modal" data-unipayment-cart-modal hidden aria-hidden="true">
    <button type="button" class="unipayment-cart-calculator__overlay" data-unipayment-cart-close aria-label="{l s='Close' d='Modules.Unipayment.Shop'}"></button>
    <div class="unipayment-cart-calculator__dialog" role="dialog" aria-modal="true">
      <button type="button" class="unipayment-cart-calculator__close" data-unipayment-cart-close aria-label="{l s='Close' d='Modules.Unipayment.Shop'}">&times;</button>
      <h2>{l s='Choose an installment plan for your cart' d='Modules.Unipayment.Shop'}</h2>
      <label>{l s='Repayment period' d='Modules.Unipayment.Shop'}<select data-unipayment-cart-schemes></select></label>
      <dl class="unipayment-cart-calculator__summary">
        <div><dt>{l s='Cart total' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-cart-value="price"></dd></div>
        <div data-unipayment-cart-first><dt>{l s='First installment' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-cart-value="first_installment"></dd></div>
        <div><dt>{l s='Financed amount' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-cart-value="financed_amount"></dd></div>
        <div><dt>{l s='Monthly installment' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-cart-value="monthly_installment"></dd></div>
        <div><dt>{l s='Total amount due' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-cart-value="total_due"></dd></div>
        <div><dt>{l s='Annual interest rate' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-cart-value="glp"></dd></div>
        <div><dt>{l s='Annual percentage rate' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-cart-value="gpr"></dd></div>
      </dl>
      <p>{l s='The selected plan will be validated again before purchase.' d='Modules.Unipayment.Shop'}</p>
    </div>
  </div>
</section>
{/if}
