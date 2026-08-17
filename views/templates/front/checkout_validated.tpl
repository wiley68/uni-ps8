<section class="card">
  <div class="card-block">
    <h1>{l s='Financing selection validated' d='Modules.Unipayment.Shop'}</h1>
    <p>{l s='The cart and financing selection were validated server-side. No order or bank application has been created.' d='Modules.Unipayment.Shop'}</p>
    <dl>
      <dt>{l s='KOP' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_validated_request.kop_code|escape:'htmlall':'UTF-8'}</dd>
      <dt>{l s='Months' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_validated_request.months|intval}</dd>
      <dt>{l s='Monthly installment' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_validated_request.monthly_installment|string_format:'%.2f'|escape:'htmlall':'UTF-8'}</dd>
    </dl>
  </div>
</section>
