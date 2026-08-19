<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-header-title">{l s='UniCredit leasing details' d='Modules.Unipayment.Admin'}</h3>
    </div>
    <div class="card-body">
        {if isset($unipayment_bank_status_label) && $unipayment_bank_status_label !== ''}
            <h4 class="mb-2">{l s='Bank status' d='Modules.Unipayment.Admin'}</h4>
            <dl class="row mb-3">
                <dt class="col-sm-4">{l s='Status' d='Modules.Unipayment.Admin'}</dt>
                <dd class="col-sm-8">{$unipayment_bank_status_label|escape:'htmlall':'UTF-8'}</dd>
                <dt class="col-sm-4">{l s='Status ID' d='Modules.Unipayment.Admin'}</dt>
                <dd class="col-sm-8">{$unipayment_bank_status_id|escape:'htmlall':'UTF-8'}</dd>
                <dt class="col-sm-4">{l s='Updated at' d='Modules.Unipayment.Admin'}</dt>
                <dd class="col-sm-8">{$unipayment_bank_status_updated_at|escape:'htmlall':'UTF-8'}</dd>
            </dl>
        {/if}

        {if isset($unipayment_leasing_rows) && $unipayment_leasing_rows|@count > 0}
            <h4 class="mb-2">{l s='Leasing terms' d='Modules.Unipayment.Admin'}</h4>
            <table class="table table-sm table-bordered mb-3">
                <tbody>
                    {foreach from=$unipayment_leasing_rows key=label item=value}
                        <tr>
                            <th scope="row" style="width: 35%;">{$label|escape:'htmlall':'UTF-8'}</th>
                            <td>{$value|escape:'htmlall':'UTF-8'}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}

    </div>
</div>
