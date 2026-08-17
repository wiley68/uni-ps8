{if isset($unipayment_bank_status_id)}
    <div class="card mt-2">
        <div class="card-header">
            <h3 class="card-header-title">{l s='UniCredit bank status' d='Modules.Unipayment.Admin'}</h3>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">{l s='Status' d='Modules.Unipayment.Admin'}</dt>
                <dd class="col-sm-9">{$unipayment_bank_status_label|escape:'htmlall':'UTF-8'}</dd>
                <dt class="col-sm-3">{l s='Status ID' d='Modules.Unipayment.Admin'}</dt>
                <dd class="col-sm-9">{$unipayment_bank_status_id|escape:'htmlall':'UTF-8'}</dd>
                <dt class="col-sm-3">{l s='Updated at' d='Modules.Unipayment.Admin'}</dt>
                <dd class="col-sm-9">{$unipayment_bank_status_updated_at|escape:'htmlall':'UTF-8'}</dd>
            </dl>
        </div>
    </div>
{/if}