<div class="panel">
  <div class="panel-heading"><i class="icon-cogs"></i> {l s='System settings' d='Modules.Unipayment.Admin'}</div>
  {if !$unipayment_secret_readable}<div class="alert alert-danger">{l s='The stored secret could not be read. Please enter it again.' d='Modules.Unipayment.Admin'}</div>{/if}

  <form id="unipayment-settings-form" action="{$unipayment_form_action|escape:'htmlall':'UTF-8'}" method="post" class="form-horizontal">
    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_ENABLED_on">{l s='UniCredit purchases on credit' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="UNIPAYMENT_ENABLED" id="UNIPAYMENT_ENABLED_on" value="1"{if $unipayment_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ENABLED_on">{l s='Yes' d='Admin.Global'}</label>
          <input type="radio" name="UNIPAYMENT_ENABLED" id="UNIPAYMENT_ENABLED_off" value="0"{if !$unipayment_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ENABLED_off">{l s='No' d='Admin.Global'}</label><a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='Allows your customers to purchase products on credit with UniCredit.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3 required" for="UNIPAYMENT_UNICID">{l s='Unique shop identifier' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <input type="text" name="UNIPAYMENT_UNICID" id="UNIPAYMENT_UNICID" value="{$unipayment_unicid|escape:'htmlall':'UTF-8'}" maxlength="36" required>
        <p class="help-block">{l s='Your unique shop identifier in the UniCredit system.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3 required" for="UNIPAYMENT_SECRET">{l s='Shop secret' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <input type="password" name="UNIPAYMENT_SECRET" id="UNIPAYMENT_SECRET" value="" maxlength="64" autocomplete="new-password"{if !$unipayment_has_secret} required{/if}>
        <p class="help-block">{l s='Your shop secret in the UniCredit system.' d='Modules.Unipayment.Admin'} {if $unipayment_has_secret}{l s='Leave empty to keep the current secret.' d='Modules.Unipayment.Admin'}{/if}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_ADVERTISING_ENABLED_on">{l s='Display advertising' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="UNIPAYMENT_ADVERTISING_ENABLED" id="UNIPAYMENT_ADVERTISING_ENABLED_on" value="1"{if $unipayment_advertising_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ADVERTISING_ENABLED_on">{l s='Yes' d='Admin.Global'}</label>
          <input type="radio" name="UNIPAYMENT_ADVERTISING_ENABLED" id="UNIPAYMENT_ADVERTISING_ENABLED_off" value="0"{if !$unipayment_advertising_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ADVERTISING_ENABLED_off">{l s='No' d='Admin.Global'}</label><a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='You can enable or disable advertising on the shop homepage.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_DEBUG_ENABLED_on">{l s='Debug mode' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="UNIPAYMENT_DEBUG_ENABLED" id="UNIPAYMENT_DEBUG_ENABLED_on" value="1"{if $unipayment_debug_enabled} checked="checked"{/if}><label for="UNIPAYMENT_DEBUG_ENABLED_on">{l s='Yes' d='Admin.Global'}</label>
          <input type="radio" name="UNIPAYMENT_DEBUG_ENABLED" id="UNIPAYMENT_DEBUG_ENABLED_off" value="0"{if !$unipayment_debug_enabled} checked="checked"{/if}><label for="UNIPAYMENT_DEBUG_ENABLED_off">{l s='No' d='Admin.Global'}</label><a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='Select this option if you want to enable debug mode.' d='Modules.Unipayment.Admin'}</p>
        <p class="help-block">{l s='When enabled, the SmartUCF request and response for order creation are stored in a database journal (retained for 3 months).' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_PRODUCT_BUTTON_ACTION">{l s='Buy button (Add to cart / Buy)' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <select name="UNIPAYMENT_PRODUCT_BUTTON_ACTION" id="UNIPAYMENT_PRODUCT_BUTTON_ACTION">
          <option value="add_to_cart"{if $unipayment_product_button_action === 'add_to_cart'} selected="selected"{/if}>{l s='Add to cart' d='Modules.Unipayment.Admin'}</option>
          <option value="buy"{if $unipayment_product_button_action === 'buy'} selected="selected"{/if}>{l s='Buy' d='Modules.Unipayment.Admin'}</option>
        </select>
        <p class="help-block">{l s='Behaviour of the secondary button in the module popup on the product page. "Add to cart" adds the product to the cart. "Buy" redirects to checkout with UniCredit installment payment preselected.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_BUTTON_TOP_SPACING">{l s='Spacing above the button' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <input type="number" class="fixed-width-sm" name="UNIPAYMENT_BUTTON_TOP_SPACING" id="UNIPAYMENT_BUTTON_TOP_SPACING" value="{$unipayment_button_top_spacing|escape:'htmlall':'UTF-8'}" min="0" max="200" step="1">
        <p class="help-block">{l s='Spacing above the button in px.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>
  </form>

  <div class="panel-footer" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <button type="submit" name="submitUnipaymentConfiguration" form="unipayment-settings-form" class="btn btn-primary"><i class="process-icon-save"></i> {l s='Save settings' d='Modules.Unipayment.Admin'}</button>
    <form action="{$unipayment_form_action|escape:'htmlall':'UTF-8'}" method="post" style="margin:0;"><button type="submit" name="submitUnipaymentRefresh" class="btn btn-default"><i class="icon-refresh"></i> {l s='Refresh bank data' d='Modules.Unipayment.Admin'}</button></form>
    <form action="{$unipayment_form_action|escape:'htmlall':'UTF-8'}" method="post" style="margin:0;"><button type="submit" name="submitUnipaymentDownloadJournal" class="btn btn-default"><i class="icon-download"></i> {l s='Download operations journal' d='Modules.Unipayment.Admin'}</button></form>
  </div>
</div>
