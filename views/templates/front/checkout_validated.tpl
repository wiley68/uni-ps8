<section class="card">
  <div class="card-block">
    <h1>{l s='Financing order created' d='Modules.Unipayment.Shop'}</h1>
    <p>{l s='Your order was created and registered for UniCredit financing. No bank application has been started yet.' d='Modules.Unipayment.Shop'}</p>
    <dl>
      <dt>{l s='Order reference' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_order_result.order_reference|escape:'htmlall':'UTF-8'}</dd>
      <dt>{l s='Order ID' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_order_result.id_order|intval}</dd>
    </dl>
  </div>
</section>
