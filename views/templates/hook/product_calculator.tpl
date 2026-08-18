{if isset($unipayment_calculator) && $unipayment_calculator}
<section
  class="unipayment-product-calculator{if $unipayment_calculator.dark_button} unipayment-product-calculator--dark{/if}{if !$unipayment_calculator.show_installment} unipayment-product-calculator--no-installment{/if}{if !$unipayment_calculator.buttons_in_row} unipayment-product-calculator--stacked{/if}"
  data-unipayment-calculator
  data-product-id="{$unipayment_calculator.product_id|intval}"
  data-endpoint="{$unipayment_calculator_url|escape:'htmlall':'UTF-8'}"
  data-calculator="{$unipayment_calculator_json|escape:'htmlall':'UTF-8'}"
  data-months-label="{l s='%d месеца' d='Modules.Unipayment.Shop'}"
  data-month-label="{l s='month' d='Modules.Unipayment.Shop'}"
  data-logo-standard="{$unipayment_logo_url|escape:'htmlall':'UTF-8'}"
  data-logo-alternative="{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}"
  data-popup-endpoint="{$unipayment_popup_url|escape:'htmlall':'UTF-8'}"
  data-popup-token="{$unipayment_popup_token|escape:'htmlall':'UTF-8'}"
  data-button-action="{$unipayment_popup.button_action|escape:'htmlall':'UTF-8'}"
  data-checkout-url="{$unipayment_checkout_url|escape:'htmlall':'UTF-8'}"
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
    <div class="unipayment-product-calculator__overlay" aria-hidden="true"></div>
    <div class="unipayment-product-calculator__modal-scroll">
      <div class="unipayment-product-calculator__dialog" role="dialog" aria-modal="true" aria-labelledby="unipayment-calculator-title-{$unipayment_calculator.product_id|intval}">
        {if $unipayment_popup.banner_url || $unipayment_popup.banner_url_mobile}
          <div class="unipayment-product-calculator__banner">
            {if $unipayment_popup.banner_link}<a href="{$unipayment_popup.banner_link|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{/if}
            <picture>
              {if $unipayment_popup.banner_url_mobile}<source media="(max-width: 768px)" srcset="{$unipayment_popup.banner_url_mobile|escape:'htmlall':'UTF-8'}">{/if}
              <img src="{if $unipayment_popup.banner_url}{$unipayment_popup.banner_url|escape:'htmlall':'UTF-8'}{else}{$unipayment_popup.banner_url_mobile|escape:'htmlall':'UTF-8'}{/if}" alt="{l s='UniCredit purchases on credit' d='Modules.Unipayment.Shop'}">
            </picture>
            {if $unipayment_popup.banner_link}</a>{/if}
          </div>
        {/if}

        <div class="unipayment-product-calculator__popup-panel">
          <div class="unipayment-product-calculator__step unipayment-product-calculator__step--active" data-unipayment-step="1">
            <h2 id="unipayment-calculator-title-{$unipayment_calculator.product_id|intval}" class="unipayment-product-calculator__popup-title">{l s='Избор на схема за лизинг' d='Modules.Unipayment.Shop'}</h2>
            <div class="unipayment-product-calculator__popup-calc">
              <div class="unipayment-product-calculator__popup-row">
                <div class="unipayment-product-calculator__popup-label">{l s='Цена на артикула' d='Modules.Unipayment.Shop'}</div>
                <div class="unipayment-product-calculator__popup-value" data-unipayment-display="price"></div>
              </div>
              <div class="unipayment-product-calculator__popup-row">
                <label class="unipayment-product-calculator__popup-label" for="unipayment-months-{$unipayment_calculator.product_id|intval}"><span class="unipayment-product-calculator__label-desktop">{l s='Брой месеци за погасяване' d='Modules.Unipayment.Shop'}</span><span class="unipayment-product-calculator__label-mobile">{l s='Брой месеци' d='Modules.Unipayment.Shop'}</span></label>
                <div class="unipayment-product-calculator__popup-value"><select id="unipayment-months-{$unipayment_calculator.product_id|intval}" class="unipayment-product-calculator__popup-select" data-unipayment-schemes></select></div>
              </div>
              <div class="unipayment-product-calculator__popup-row" data-unipayment-first-row>
                <label class="unipayment-product-calculator__popup-label" for="unipayment-first-{$unipayment_calculator.product_id|intval}">{l s='Първоначална вноска /евро/' d='Modules.Unipayment.Shop'}</label>
                <div class="unipayment-product-calculator__popup-value"><input id="unipayment-first-{$unipayment_calculator.product_id|intval}" class="unipayment-product-calculator__popup-input" data-unipayment-first type="number" min="0" step="0.01" value="0"></div>
              </div>
              <div class="unipayment-product-calculator__popup-row"><div class="unipayment-product-calculator__popup-label">{l s='Обща сума на заема' d='Modules.Unipayment.Shop'}</div><div class="unipayment-product-calculator__popup-value" data-unipayment-display="financed_amount"></div></div>
              <div class="unipayment-product-calculator__popup-row"><div class="unipayment-product-calculator__popup-label"><span class="unipayment-product-calculator__label-desktop">{l s='Размер на погасителна вноска' d='Modules.Unipayment.Shop'}</span><span class="unipayment-product-calculator__label-mobile">{l s='Погасителна вноска' d='Modules.Unipayment.Shop'}</span></div><div class="unipayment-product-calculator__popup-value" data-unipayment-display="monthly_installment"></div></div>
              <div class="unipayment-product-calculator__popup-row"><div class="unipayment-product-calculator__popup-label">{l s='Обща дължима сума' d='Modules.Unipayment.Shop'}</div><div class="unipayment-product-calculator__popup-value" data-unipayment-display="total_payable"></div></div>
              <div class="unipayment-product-calculator__popup-row"><div class="unipayment-product-calculator__popup-label">{l s='ГЛП' d='Modules.Unipayment.Shop'}</div><div class="unipayment-product-calculator__popup-value unipayment-product-calculator__popup-value--red" data-unipayment-display="glp"></div></div>
              <div class="unipayment-product-calculator__popup-row"><div class="unipayment-product-calculator__popup-label">{l s='ГПР' d='Modules.Unipayment.Shop'}</div><div class="unipayment-product-calculator__popup-value unipayment-product-calculator__popup-value--red" data-unipayment-display="gpr"></div></div>
              <div class="unipayment-product-calculator__popup-row unipayment-product-calculator__popup-row--note" data-unipayment-popup-error role="alert"></div>
            </div>

            <div class="unipayment-product-calculator__popup-actions">
              <button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary" data-unipayment-close><span><b>{l s='Отказ' d='Modules.Unipayment.Shop'}</b></span></button>
              <button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary" data-unipayment-secondary><span><b>{$unipayment_popup.secondary_label|escape:'htmlall':'UTF-8'}</b></span></button>
              <button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary" data-unipayment-apply disabled><span><b>{l s='Кандидатствай' d='Modules.Unipayment.Shop'}</b></span><i style="background-image:url('{$unipayment_popup_badge_url|escape:'htmlall':'UTF-8'}')" aria-hidden="true"></i></button>
            </div>
          </div>

          <div class="unipayment-product-calculator__step" data-unipayment-step="2" hidden aria-live="polite" aria-label="{l s='Application details' d='Modules.Unipayment.Shop'}"></div>
        </div>
      </div>
    </div>
  </div>
</section>
{/if}
