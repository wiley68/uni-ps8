{if isset($unipayment_cart_calculator) && $unipayment_cart_calculator}
    <section
        class="unipayment-product-calculator unipayment-cart-calculator{if $unipayment_cart_calculator.dark_button} unipayment-product-calculator--dark{/if}{if !$unipayment_cart_calculator.show_installment} unipayment-product-calculator--no-installment{/if}{if !$unipayment_cart_calculator.buttons_in_row} unipayment-product-calculator--stacked{/if}"
        data-unipayment-calculator data-unipayment-cart-calculator data-unipayment-source="cart"
        data-endpoint="{$unipayment_cart_calculator_url|escape:'htmlall':'UTF-8'}"
        data-calculator="{$unipayment_cart_calculator_json|escape:'htmlall':'UTF-8'}"
        data-months-label="{l s='%d months' d='Modules.Unipayment.Shop'}"
        data-month-label="{l s='month' d='Modules.Unipayment.Shop'}"
        data-logo-standard="{$unipayment_logo_url|escape:'htmlall':'UTF-8'}"
        data-logo-alternative="{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}"
        data-popup-endpoint="{$unipayment_cart_popup_url|escape:'htmlall':'UTF-8'}"
        data-popup-token="{$unipayment_popup_token|escape:'htmlall':'UTF-8'}" data-hide-secondary="1"
        data-processing-title="{l s='Processing the request' d='Modules.Unipayment.Shop'}"
        data-processing-message="{l s='Please wait...' d='Modules.Unipayment.Shop'}"
        data-smartucf-error-default="{l s='An error occurred while processing the request.' d='Modules.Unipayment.Shop'}"
        data-smartucf-error-retry="{l s='Please try again later.' d='Modules.Unipayment.Shop'}"
        data-close-label="{l s='Close' d='Modules.Unipayment.Shop'}"
        data-required-field-message="{l s='This field is required.' d='Modules.Unipayment.Shop'}"
        data-invalid-phone-message="{l s='Enter a valid phone number.' d='Modules.Unipayment.Shop'}"
        data-invalid-email-message="{l s='Enter a valid email address.' d='Modules.Unipayment.Shop'}"
        data-invalid-egn-message="{l s='Enter a valid EGN (10 digits; the first 8 must be a YYYYMMDD date).' d='Modules.Unipayment.Shop'}"
        data-invalid-phone2-message="{l s='Enter a valid secondary phone number.' d='Modules.Unipayment.Shop'}"
        data-calculate-failed-message="{l s='Calculation failed. Please try again.' d='Modules.Unipayment.Shop'}"
        data-customer-form-missing-message="{l s='The personal details form failed to load. Please reload the page.' d='Modules.Unipayment.Shop'}"
        data-validation-failed-message="{l s='The details could not be validated.' d='Modules.Unipayment.Shop'}"
        data-consents-required-message="{l s='Please accept all mandatory consents.' d='Modules.Unipayment.Shop'}"
        data-order-number-label="{l s='Order number:' d='Modules.Unipayment.Shop'}"
        data-order-confirmation-message="{l s='Expect confirmation from UniCredit.' d='Modules.Unipayment.Shop'}"
        data-order-success-title="{l s='The application was submitted successfully' d='Modules.Unipayment.Shop'}"
        style="--unipayment-button-width: {$unipayment_cart_calculator.button_width|intval}px; --unipayment-button-height: {$unipayment_cart_calculator.button_height|intval}px;">
        <p class="unipayment-product-calculator__heading" {if $unipayment_cart_calculator.heading === ''} hidden{/if}>
            {$unipayment_cart_calculator.heading|escape:'htmlall':'UTF-8'}</p>
        <div class="unipayment-product-calculator__buttons">
            {foreach from=$unipayment_offer_types item=offer_type}
                <button type="button"
                    class="unipayment-product-calculator__button unipayment-product-calculator__button--{$offer_type|escape:'htmlall':'UTF-8'}"
                    data-unipayment-offer="{$offer_type|escape:'htmlall':'UTF-8'}"
                    {if !isset($unipayment_cart_calculator.offers[$offer_type])} hidden{/if}>
                    <span class="unipayment-product-calculator__button-content">
                        <span
                            class="unipayment-product-calculator__button-title">{l s='Buy on installment' d='Modules.Unipayment.Shop'}</span>
                        <span class="unipayment-product-calculator__button-price"
                            data-unipayment-preferred-price>{if isset($unipayment_cart_calculator.offers[$offer_type])}{$unipayment_cart_calculator.offers[$offer_type].installment_label|escape:'htmlall':'UTF-8'}{/if}</span>
                    </span>
                    {if $offer_type === 'promo'}
                        <span class="unipayment-product-calculator__badge" aria-hidden="true">0%</span>
                    {else}
                        <span class="unipayment-product-calculator__logo">
                            <img src="{if $unipayment_cart_calculator.dark_button}{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}{else}{$unipayment_logo_url|escape:'htmlall':'UTF-8'}{/if}"
                                alt="{l s='UniCredit' d='Modules.Unipayment.Shop'}" data-unipayment-logo>
                        </span>
                    {/if}
                </button>
            {/foreach}
        </div>

        <div class="unipayment-product-calculator__modal" data-unipayment-modal hidden aria-hidden="true">
            <div class="unipayment-product-calculator__overlay" aria-hidden="true"></div>
            <div class="unipayment-product-calculator__modal-scroll">
                <div class="unipayment-product-calculator__dialog" role="dialog" aria-modal="true"
                    aria-labelledby="unipayment-cart-calculator-title">
                    {if $unipayment_popup.banner_url || $unipayment_popup.banner_url_mobile}
                        <div class="unipayment-product-calculator__banner">
                            {if $unipayment_popup.banner_link}<a href="{$unipayment_popup.banner_link|escape:'htmlall':'UTF-8'}"
                                target="_blank" rel="noopener noreferrer">{/if}
                                <picture>
                                    {if $unipayment_popup.banner_url_mobile}
                                        <source media="(max-width: 768px)"
                                        srcset="{$unipayment_popup.banner_url_mobile|escape:'htmlall':'UTF-8'}">{/if}
                                    <img src="{if $unipayment_popup.banner_url}{$unipayment_popup.banner_url|escape:'htmlall':'UTF-8'}{else}{$unipayment_popup.banner_url_mobile|escape:'htmlall':'UTF-8'}{/if}"
                                        alt="{l s='UniCredit Credit purchases' d='Modules.Unipayment.Shop'}">
                                </picture>
                                {if $unipayment_popup.banner_link}
                            </a>{/if}
                        </div>
                    {/if}

                    <div class="unipayment-product-calculator__popup-panel">
                        <div class="unipayment-product-calculator__step unipayment-product-calculator__step--active"
                            data-unipayment-step="1">
                            <h2 id="unipayment-cart-calculator-title" class="unipayment-product-calculator__popup-title">
                                {l s='Choose a leasing scheme' d='Modules.Unipayment.Shop'}</h2>
                            <div class="unipayment-product-calculator__popup-calc">
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='Cart total' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value" data-unipayment-display="price">
                                    </div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <label class="unipayment-product-calculator__popup-label"
                                        for="unipayment-cart-months"><span
                                            class="unipayment-product-calculator__label-desktop">{l s='Number of repayment months' d='Modules.Unipayment.Shop'}</span><span
                                            class="unipayment-product-calculator__label-mobile">{l s='Number of months' d='Modules.Unipayment.Shop'}</span></label>
                                    <div class="unipayment-product-calculator__popup-value"><select
                                            id="unipayment-cart-months" class="unipayment-product-calculator__popup-select"
                                            data-unipayment-schemes></select></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row" data-unipayment-first-row>
                                    <label class="unipayment-product-calculator__popup-label"
                                        for="unipayment-cart-first">{l s='Down payment /EUR/' d='Modules.Unipayment.Shop'}</label>
                                    <div class="unipayment-product-calculator__popup-value"><input
                                            id="unipayment-cart-first" class="unipayment-product-calculator__popup-input"
                                            data-unipayment-first type="text" inputmode="numeric" pattern="[0-9]*"
                                            value="0"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='Total loan amount' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value"
                                        data-unipayment-display="financed_amount"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label"><span
                                            class="unipayment-product-calculator__label-desktop">{l s='Installment amount' d='Modules.Unipayment.Shop'}</span><span
                                            class="unipayment-product-calculator__label-mobile">{l s='Installment' d='Modules.Unipayment.Shop'}</span>
                                    </div>
                                    <div class="unipayment-product-calculator__popup-value"
                                        data-unipayment-display="monthly_installment"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='Total amount due' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value"
                                        data-unipayment-display="total_payable"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='AIR' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value unipayment-product-calculator__popup-value--red"
                                        data-unipayment-display="glp"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='APR' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value unipayment-product-calculator__popup-value--red"
                                        data-unipayment-display="gpr"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row unipayment-product-calculator__popup-row--note"
                                    data-unipayment-popup-error role="alert"></div>
                            </div>

                            <div class="unipayment-product-calculator__popup-actions">
                                <button type="button"
                                    class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary"
                                    data-unipayment-close><span><b>{l s='Cancel' d='Modules.Unipayment.Shop'}</b></span></button>
                                <button type="button" hidden aria-hidden="true" tabindex="-1"
                                    data-unipayment-secondary></button>
                                <button type="button"
                                    class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary"
                                    data-unipayment-apply
                                    disabled><span><b>{l s='Apply' d='Modules.Unipayment.Shop'}</b></span><i
                                        style="background-image:url('{$unipayment_popup_badge_url|escape:'htmlall':'UTF-8'}')"
                                        aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="unipayment-product-calculator__step" data-unipayment-step="2" hidden>
                            <h2 class="unipayment-product-calculator__popup-title">
                                {l s='Enter personal details' d='Modules.Unipayment.Shop'}</h2>
                            <div class="unipayment-product-calculator__customer-form" data-unipayment-customer-form>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-cart-first-name">{l s='First name' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-cart-first-name" name="first_name" type="text"
                                        value="{$unipayment_popup.customer.first_name|escape:'htmlall':'UTF-8'}" required
                                        aria-required="true" autocomplete="given-name">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="first_name" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-cart-last-name">{l s='Last name' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-cart-last-name" name="last_name" type="text"
                                        value="{$unipayment_popup.customer.last_name|escape:'htmlall':'UTF-8'}" required
                                        aria-required="true" autocomplete="family-name">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="last_name" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-cart-address">{l s='Address' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-cart-address" name="address" type="text"
                                        value="{$unipayment_popup.customer.address|escape:'htmlall':'UTF-8'}" required
                                        aria-required="true" autocomplete="street-address">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="address" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-cart-phone">{l s='Mobile phone' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input" id="unipayment-cart-phone"
                                        name="phone" type="tel"
                                        value="{$unipayment_popup.customer.phone|escape:'htmlall':'UTF-8'}" required
                                        aria-required="true" autocomplete="tel" inputmode="tel">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="phone" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-cart-email">{l s='E-Mail' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input" id="unipayment-cart-email"
                                        name="email" type="email"
                                        value="{$unipayment_popup.customer.email|escape:'htmlall':'UTF-8'}" required
                                        aria-required="true" autocomplete="email">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="email" role="alert"></span>
                                </div>
                                {if $unipayment_require_egn}
                                    <div class="unipayment-product-calculator__customer-field">
                                        <label class="unipayment-product-calculator__customer-label"
                                            for="unipayment-cart-egn">{l s='EGN' d='Modules.Unipayment.Shop'}
                                            <span class="unipayment-product-calculator__required"
                                                aria-hidden="true">*</span></label>
                                        <input class="unipayment-product-calculator__customer-input" id="unipayment-cart-egn"
                                            name="egn" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" value=""
                                            required aria-required="true">
                                        <span class="unipayment-product-calculator__field-error"
                                            data-unipayment-field-error="egn" role="alert"></span>
                                    </div>
                                    <div class="unipayment-product-calculator__customer-field">
                                        <label class="unipayment-product-calculator__customer-label"
                                            for="unipayment-cart-phone2">{l s='Secondary phone' d='Modules.Unipayment.Shop'}
                                            <span class="unipayment-product-calculator__required"
                                                aria-hidden="true">*</span></label>
                                        <input class="unipayment-product-calculator__customer-input" id="unipayment-cart-phone2"
                                            name="phone2" type="tel" value="" required aria-required="true" autocomplete="tel"
                                            inputmode="tel">
                                        <span class="unipayment-product-calculator__field-error"
                                            data-unipayment-field-error="phone2" role="alert"></span>
                                    </div>
                                {/if}
                                <span class="unipayment-product-calculator__field-error" data-unipayment-submit-error
                                    role="alert"></span>
                            </div>
                            {if isset($unipayment_popup.consents) && $unipayment_popup.consents}
                                <div class="unipayment-product-calculator__consents" data-unipayment-consents
                                    aria-label="{l s='Consents' d='Modules.Unipayment.Shop'}">
                                    {foreach from=$unipayment_popup.consents item=consent}
                                        <div
                                            class="unipayment-product-calculator__consent{if !$consent.has_checkbox} unipayment-product-calculator__consent--info{/if}">
                                            {if $consent.has_checkbox}
                                                <input type="checkbox" class="unipayment-product-calculator__consent-checkbox"
                                                    id="unipayment-cart-consent-{$consent.id|intval}" name="unipayment_consent[]"
                                                    value="{$consent.id|intval}" data-unipayment-consent-checkbox
                                                    data-unipayment-consent-id="{$consent.id|intval}">
                                                <label class="unipayment-product-calculator__consent-label"
                                                    for="unipayment-cart-consent-{$consent.id|intval}">
                                                    {if $consent.url}
                                                        <a href="{$consent.url|escape:'htmlall':'UTF-8'}" target="_blank"
                                                            rel="noopener noreferrer">{$consent.name|escape:'htmlall':'UTF-8'}</a>
                                                    {else}
                                                        {$consent.name|escape:'htmlall':'UTF-8'}
                                                    {/if}
                                                </label>
                                            {else}
                                                <p class="unipayment-product-calculator__consent-text">
                                                    {if $consent.url}
                                                        <a href="{$consent.url|escape:'htmlall':'UTF-8'}" target="_blank"
                                                            rel="noopener noreferrer">{$consent.name|escape:'htmlall':'UTF-8'}</a>
                                                    {else}
                                                        {$consent.name|escape:'htmlall':'UTF-8'}
                                                    {/if}
                                                </p>
                                            {/if}
                                        </div>
                                    {/foreach}
                                </div>
                            {/if}
                            <div
                                class="unipayment-product-calculator__popup-actions unipayment-product-calculator__popup-actions--step2">
                                <div class="unipayment-product-calculator__popup-actions-group">
                                    <button type="button"
                                        class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary"
                                        data-unipayment-back><span><b>{l s='Back' d='Modules.Unipayment.Shop'}</b></span></button>
                                    <button type="button"
                                        class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary"
                                        data-unipayment-close><span><b>{l s='Cancel' d='Modules.Unipayment.Shop'}</b></span></button>
                                </div>
                                <button type="button"
                                    class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary"
                                    data-unipayment-submit disabled
                                    aria-disabled="true"><span><b>{l s='Submit' d='Modules.Unipayment.Shop'}</b></span><i
                                        style="background-image:url('{$unipayment_popup_badge_url|escape:'htmlall':'UTF-8'}')"
                                        aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <div class="unipayment-product-calculator__step" data-unipayment-step="3" hidden aria-live="polite"
                            aria-label="{l s='Validated application details' d='Modules.Unipayment.Shop'}"></div>
                    </div>
                </div>
            </div>
            <div class="unipayment-product-calculator__processing" data-unipayment-processing hidden>
                <div class="unipayment-product-calculator__processing-panel" role="status" aria-live="polite"
                    aria-busy="true">
                    <span class="unipayment-product-calculator__processing-spinner" aria-hidden="true"></span>
                    <p class="unipayment-product-calculator__processing-text">
                        {l s='Processing the request. Please wait...' d='Modules.Unipayment.Shop'}
                    </p>
                </div>
            </div>
        </div>
    </section>
{/if}
