<section class="card">
  <div class="card-block">
    <h1>{l s='Поръчката за финансиране е създадена' d='Modules.Unipayment.Shop'}</h1>
    <p>{l s='Поръчката е създадена и регистрирана за финансиране с УниКредит. Все още не е стартирана банкова заявка.' d='Modules.Unipayment.Shop'}</p>
    <dl>
      <dt>{l s='Референция на поръчката' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_order_result.order_reference|escape:'htmlall':'UTF-8'}</dd>
      <dt>{l s='ID на поръчката' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_order_result.id_order|intval}</dd>
    </dl>
  </div>
</section>
