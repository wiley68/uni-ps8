<div class="panel">
  <div class="panel-heading">
    <i class="icon-cogs"></i>
    {l s='Module configuration' d='Modules.Unipayment.Admin'}
  </div>

  {if !$unipayment_secret_readable}
    <div class="alert alert-danger">
      {l s='The stored secret cannot be decrypted. Enter the secret again before using the connection.' d='Modules.Unipayment.Admin'}
    </div>
  {/if}

  <form action="{$unipayment_form_action|escape:'htmlall':'UTF-8'}" method="post" class="form-horizontal">
    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_ENABLED">
        {l s='Enable credit purchases' d='Modules.Unipayment.Admin'}
      </label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="UNIPAYMENT_ENABLED" id="UNIPAYMENT_ENABLED_on" value="1"{if $unipayment_enabled} checked="checked"{/if}>
          <label for="UNIPAYMENT_ENABLED_on">{l s='Yes' d='Admin.Global'}</label>
          <input type="radio" name="UNIPAYMENT_ENABLED" id="UNIPAYMENT_ENABLED_off" value="0"{if !$unipayment_enabled} checked="checked"{/if}>
          <label for="UNIPAYMENT_ENABLED_off">{l s='No' d='Admin.Global'}</label>
          <a class="slide-button btn"></a>
        </span>
        <p class="help-block">
          {l s='Controls the UniCredit functionality without disabling the PrestaShop module.' d='Modules.Unipayment.Admin'}
        </p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3 required" for="UNIPAYMENT_UNICID">UNICID</label>
      <div class="col-lg-9">
        <input
          type="text"
          name="UNIPAYMENT_UNICID"
          id="UNIPAYMENT_UNICID"
          value="{$unipayment_unicid|escape:'htmlall':'UTF-8'}"
          maxlength="36"
          required
        >
        <p class="help-block">
          {l s='Unique shop identifier provided by the Control Panel.' d='Modules.Unipayment.Admin'}
        </p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3 required" for="UNIPAYMENT_SECRET">
        {l s='Secret' d='Modules.Unipayment.Admin'}
      </label>
      <div class="col-lg-9">
        <input
          type="password"
          name="UNIPAYMENT_SECRET"
          id="UNIPAYMENT_SECRET"
          value=""
          maxlength="64"
          autocomplete="new-password"
          {if !$unipayment_has_secret}required{/if}
        >
        <p class="help-block">
          {if $unipayment_has_secret}
            {l s='A secret is stored. Leave this field empty to keep it unchanged.' d='Modules.Unipayment.Admin'}
          {else}
            {l s='Secret provided by the Control Panel.' d='Modules.Unipayment.Admin'}
          {/if}
        </p>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="submitUnipaymentConfiguration" class="btn btn-default pull-right">
        <i class="process-icon-save"></i>
        {l s='Save' d='Admin.Actions'}
      </button>
    </div>
  </form>
</div>

<div class="panel">
  <div class="panel-heading">
    <i class="icon-info-circle"></i>
    {l s='Control Panel status' d='Modules.Unipayment.Admin'}
  </div>

  <dl class="dl-horizontal">
    <dt>{l s='Connection status' d='Modules.Unipayment.Admin'}</dt>
    <dd>{$unipayment_connection_status|escape:'htmlall':'UTF-8'}</dd>

    <dt>{l s='Cache status' d='Modules.Unipayment.Admin'}</dt>
    <dd>{$unipayment_cache_status|escape:'htmlall':'UTF-8'}</dd>

    <dt>{l s='Last successful refresh' d='Modules.Unipayment.Admin'}</dt>
    <dd>{$unipayment_last_refresh|escape:'htmlall':'UTF-8'}</dd>
  </dl>

  <form action="{$unipayment_form_action|escape:'htmlall':'UTF-8'}" method="post">
    <button type="submit" name="submitUnipaymentRefresh" class="btn btn-default">
      <i class="icon-refresh"></i>
      {l s='Refresh configuration' d='Modules.Unipayment.Admin'}
    </button>
    <p class="help-block">
      {l s='Manual refresh is not active until the Control Panel connection is available.' d='Modules.Unipayment.Admin'}
    </p>
  </form>
</div>
