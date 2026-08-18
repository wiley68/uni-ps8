{if isset($unipayment_calculator) && $unipayment_calculator}
<section
  class="unipayment-product-calculator{if $unipayment_calculator.dark_button} unipayment-product-calculator--dark{/if}{if !$unipayment_calculator.show_installment} unipayment-product-calculator--no-installment{/if}{if !$unipayment_calculator.buttons_in_row} unipayment-product-calculator--stacked{/if}"
  data-unipayment-calculator
  data-product-id="{$unipayment_calculator.product_id|intval}"
  data-endpoint="{$unipayment_calculator_url|escape:'htmlall':'UTF-8'}"
  data-calculator="{$unipayment_calculator_json|escape:'htmlall':'UTF-8'}"
  data-months-label="{l s='%d months' d='Modules.Unipayment.Shop'}"
  data-month-label="{l s='month' d='Modules.Unipayment.Shop'}"
  data-logo-standard="{$unipayment_logo_url|escape:'htmlall':'UTF-8'}"
  data-logo-alternative="{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}"
  style="margin-top: {$unipayment_button_top_spacing|intval}px; --unipayment-button-width: {$unipayment_calculator.button_width|intval}px; --unipayment-button-height: {$unipayment_calculator.button_height|intval}px;"
>
  {if $unipayment_calculator.heading !== ''}<p class="unipayment-product-calculator__heading">{$unipayment_calculator.heading|escape:'htmlall':'UTF-8'}</p>{/if}
  <div class="unipayment-product-calculator__buttons">
    {foreach from=$unipayment_offer_types item=offer_type}
      <button type="button" class="unipayment-product-calculator__button unipayment-product-calculator__button--{$offer_type|escape:'htmlall':'UTF-8'}" data-unipayment-offer="{$offer_type|escape:'htmlall':'UTF-8'}"{if !isset($unipayment_calculator.offers[$offer_type])} hidden{/if}>
        <span class="unipayment-product-calculator__button-content">
          <span class="unipayment-product-calculator__button-title">{l s='Купи на изплащане' d='Modules.Unipayment.Shop'}</span>
          <span class="unipayment-product-calculator__button-price" data-unipayment-preferred-price>{if isset($unipayment_calculator.offers[$offer_type])}{$unipayment_calculator.offers[$offer_type].installment_label|escape:'htmlall':'UTF-8'}{/if}</span>
        </span>
        {if $offer_type === 'promo'}
          <span class="unipayment-product-calculator__badge" aria-hidden="true">0%</span>
        {else}
          <span class="unipayment-product-calculator__logo">
            <img src="{if $unipayment_calculator.dark_button}{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}{else}{$unipayment_logo_url|escape:'htmlall':'UTF-8'}{/if}" alt="{l s='UniCredit' d='Modules.Unipayment.Shop'}" data-unipayment-logo>
          </span>
        {/if}
      </button>
    {/foreach}
  </div>

  <div class="unipayment-product-calculator__modal" data-unipayment-modal hidden aria-hidden="true">
    <button type="button" class="unipayment-product-calculator__overlay" data-unipayment-close aria-label="{l s='Close' d='Modules.Unipayment.Shop'}"></button>
    <div class="unipayment-product-calculator__dialog" role="dialog" aria-modal="true" aria-labelledby="unipayment-calculator-title-{$unipayment_calculator.product_id|intval}">
      <button type="button" class="unipayment-product-calculator__close" data-unipayment-close aria-label="{l s='Close' d='Modules.Unipayment.Shop'}">&times;</button>
      <h2 id="unipayment-calculator-title-{$unipayment_calculator.product_id|intval}">{l s='Choose an installment plan' d='Modules.Unipayment.Shop'}</h2>
      <label for="unipayment-months-{$unipayment_calculator.product_id|intval}">{l s='Repayment period' d='Modules.Unipayment.Shop'}</label>
      <select id="unipayment-months-{$unipayment_calculator.product_id|intval}" data-unipayment-schemes></select>
      <dl class="unipayment-product-calculator__summary">
        <div><dt>{l s='Product price' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-value="price"></dd></div>
        <div data-unipayment-first-row><dt>{l s='First installment' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-value="first_installment"></dd></div>
        <div><dt>{l s='Financed amount' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-value="financed_amount"></dd></div>
        <div><dt>{l s='Monthly installment' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-value="monthly_installment"></dd></div>
        <div><dt>{l s='Total amount due' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-value="total_due"></dd></div>
        <div><dt>{l s='Annual interest rate' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-value="glp"></dd></div>
        <div><dt>{l s='Annual percentage rate' d='Modules.Unipayment.Shop'}</dt><dd data-unipayment-value="gpr"></dd></div>
      </dl>
      <p class="unipayment-product-calculator__notice">{l s='The selected plan will be validated again before purchase.' d='Modules.Unipayment.Shop'}</p>
      <button type="button" class="btn btn-primary unipayment-product-calculator__select" data-unipayment-select>{l s='Select this plan' d='Modules.Unipayment.Shop'}</button>
    </div>
  </div>
</section>
{/if}
