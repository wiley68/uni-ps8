<form method="post" action="{$unipayment_checkout_action|escape:'htmlall':'UTF-8'}">
<div class="unipayment-checkout" data-unipayment-checkout data-config="{$unipayment_checkout_json|escape:'htmlall':'UTF-8'}">
  <input type="hidden" name="unipayment_checkout_submit" value="1">
  <input type="hidden" name="unipayment_checkout_token" value="{$unipayment_checkout_token|escape:'htmlall':'UTF-8'}">
  <input type="hidden" name="unipayment_cart_snapshot" value="{$unipayment_checkout.cart_snapshot|escape:'htmlall':'UTF-8'}">
  <input type="hidden" name="unipayment_kop_code" data-unipayment-kop value="">
  <p>{l s='Choose the repayment period and review the server-calculated financing details.' d='Modules.Unipayment.Shop'}</p>
  <label>{l s='Repayment period' d='Modules.Unipayment.Shop'}
    <select name="unipayment_scheme_key" data-unipayment-scheme required>
      {foreach from=$unipayment_checkout.schemes item=scheme}
        <option value="{$scheme.key|escape:'htmlall':'UTF-8'}"{if $scheme.key === $unipayment_checkout.default_scheme_key} selected{/if}>{$scheme.months|intval} {l s='months' d='Modules.Unipayment.Shop'}{if $scheme.scheme_type === 'promo'} — {l s='promo' d='Modules.Unipayment.Shop'}{/if}</option>
      {/foreach}
    </select>
  </label>
  <label data-unipayment-first-row>{l s='First installment' d='Modules.Unipayment.Shop'}
    <input type="number" name="unipayment_first_installment" data-unipayment-first min="0" step="0.01" value="0">
  </label>
  <dl class="unipayment-checkout__summary">
    <div><dt>{l s='Cart total' d='Modules.Unipayment.Shop'}</dt><dd data-value="price"></dd></div>
    <div><dt>{l s='Financed amount' d='Modules.Unipayment.Shop'}</dt><dd data-value="financed_amount"></dd></div>
    <div><dt>{l s='Monthly installment' d='Modules.Unipayment.Shop'}</dt><dd data-value="monthly_installment"></dd></div>
    <div><dt>{l s='Total amount due' d='Modules.Unipayment.Shop'}</dt><dd data-value="total_payable"></dd></div>
    <div><dt>{l s='GLP' d='Modules.Unipayment.Shop'}</dt><dd data-value="glp"></dd></div>
    <div><dt>{l s='GPR' d='Modules.Unipayment.Shop'}</dt><dd data-value="gpr"></dd></div>
  </dl>
  {if $unipayment_checkout.process2}
    <label>{l s='EGN' d='Modules.Unipayment.Shop'} <input type="text" name="unipayment_egn" maxlength="10" pattern="[0-9]{10}" required></label>
    <label>{l s='Secondary phone' d='Modules.Unipayment.Shop'} <input type="tel" name="unipayment_phone2" required></label>
  {/if}
  {if $unipayment_checkout.consents}
    <div class="unipayment-checkout__consents">
      {foreach from=$unipayment_checkout.consents item=consent}
        {if $consent.mandatory}<label><input type="checkbox" name="unipayment_consent[]" value="{$consent.id|intval}" required> {/if}
        {if $consent.url}<a href="{$consent.url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$consent.name|escape:'htmlall':'UTF-8'}</a>{else}{$consent.name|escape:'htmlall':'UTF-8'}{/if}
        {if $consent.mandatory}</label>{/if}
      {/foreach}
    </div>
  {/if}
  <p class="unipayment-checkout__notice">{l s='All financing values will be recalculated and validated after submission.' d='Modules.Unipayment.Shop'}</p>
</div>
</form>
