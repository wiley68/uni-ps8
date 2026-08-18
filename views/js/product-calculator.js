(function () {
  'use strict';

  var selector = '[data-unipayment-calculator]';
  var domRefreshTimer = null;

  function parseConfig(root) {
    try { return JSON.parse(root.getAttribute('data-calculator') || '{}'); } catch (error) { return null; }
  }

  function productAttributeId(doc) {
    var productDetails = doc.querySelector('#product-details[data-product]');
    if (productDetails) {
      try {
        var productState = JSON.parse(productDetails.getAttribute('data-product') || '{}');
        var stateAttributeId = parseInt(productState.id_product_attribute, 10) || 0;
        if (stateAttributeId > 0) return stateAttributeId;
      } catch (error) {
        // Fall back to the theme's hidden field when the PrestaShop state is unavailable.
      }
    }
    var field = doc.querySelector('input[name="id_product_attribute"]');
    return field ? Math.max(0, parseInt(field.value, 10) || 0) : 0;
  }

  function buttonInstallmentLabel(offer) {
    return offer && typeof offer.installment_label === 'string' ? offer.installment_label : '';
  }

  if (typeof module === 'object' && module.exports) {
    module.exports.productAttributeId = productAttributeId;
    module.exports.buttonInstallmentLabel = buttonInstallmentLabel;
    return;
  }

  function quantity() {
    var field = document.querySelector('#quantity_wanted, input[name="qty"], input[name="quantity"]');
    return field ? Math.max(1, parseInt(field.value, 10) || 1) : 1;
  }

  function applyVisualConfig(root, config) {
    var available = config || {};
    root.classList.toggle('unipayment-product-calculator--dark', !!available.dark_button);
    root.classList.toggle('unipayment-product-calculator--no-installment', !available.show_installment);
    root.classList.toggle('unipayment-product-calculator--stacked', available.buttons_in_row === false);
    root.style.setProperty('--unipayment-button-width', (parseInt(available.button_width, 10) || 290) + 'px');
    root.style.setProperty('--unipayment-button-height', (parseInt(available.button_height, 10) || 56) + 'px');
    var logo = root.querySelector('[data-unipayment-logo]');
    if (logo) logo.src = root.getAttribute(available.dark_button ? 'data-logo-alternative' : 'data-logo-standard') || logo.src;
  }

  function setup(root) {
    if (root.dataset.unipaymentReady === '1') return;
    root.dataset.unipaymentReady = '1';
    var config = parseConfig(root);
    var modal = root.querySelector('[data-unipayment-modal]');
    var select = root.querySelector('[data-unipayment-schemes]');
    var first = root.querySelector('[data-unipayment-first]');
    var firstRow = root.querySelector('[data-unipayment-first-row]');
    var step1 = root.querySelector('[data-unipayment-step="1"]');
    var step2 = root.querySelector('[data-unipayment-step="2"]');
    var applyButton = root.querySelector('[data-unipayment-apply]');
    var secondaryButton = root.querySelector('[data-unipayment-secondary]');
    var errorBox = root.querySelector('[data-unipayment-popup-error]');
    var activeType = '';
    var lastCalculation = null;
    var lastOpenTrigger = null;
    var calculateTimer = null;
    var calculateRequest = null;
    var calculateSequence = 0;
    var refreshTimer = null;
    var refreshRequest = null;
    var refreshSequence = 0;
    var lastRequestKey = '';
    var redirectPending = false;

    function setStep(number) {
      step1.hidden = number !== 1;
      step1.classList.toggle('unipayment-product-calculator__step--active', number === 1);
      step2.hidden = number !== 2;
      step2.classList.toggle('unipayment-product-calculator__step--active', number === 2);
    }

    function resetPopup() {
      window.clearTimeout(calculateTimer);
      calculateTimer = null;
      if (calculateRequest && typeof calculateRequest.abort === 'function') calculateRequest.abort();
      calculateRequest = null;
      calculateSequence += 1;
      lastCalculation = null;
      redirectPending = false;
      activeType = '';
      first.value = '0';
      first.readOnly = false;
      firstRow.hidden = true;
      select.textContent = '';
      errorBox.textContent = '';
      applyButton.disabled = true;
      secondaryButton.disabled = true;
      setStep(1);
    }

    function close() {
      if (redirectPending) return;
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('unipayment-modal-open');
      resetPopup();
      if (lastOpenTrigger && document.body.contains(lastOpenTrigger)) lastOpenTrigger.focus();
      lastOpenTrigger = null;
    }

    function optionText(scheme) {
      var text = (root.getAttribute('data-months-label') || '%d месеца').replace('%d', scheme.months);
      return text + (scheme.description ? ' - ' + scheme.description : '');
    }

    function rebuildSchemes(type) {
      var offer = config && config.offers ? config.offers[type] : null;
      select.textContent = '';
      if (!offer || !offer.schemes || !offer.schemes.length) return false;
      offer.schemes.forEach(function (scheme) {
        var option = document.createElement('option');
        option.value = scheme.key || ((scheme.scheme_type === 'promo' ? 'p:' : '') + scheme.months + ':' + scheme.filter_id);
        option.textContent = optionText(scheme) + '\u00a0\u00a0\u00a0';
        option.selected = option.value === offer.preferred_scheme_key;
        select.appendChild(option);
      });
      select.disabled = false;
      return true;
    }

    function selectedScheme() {
      var offer = config && config.offers ? config.offers[activeType] : null;
      if (!offer || !offer.schemes) return null;
      var key = select.value;
      return offer.schemes.filter(function (scheme) {
        var schemeKey = scheme.key || ((scheme.scheme_type === 'promo' ? 'p:' : '') + scheme.months + ':' + scheme.filter_id);
        return schemeKey === key;
      })[0] || null;
    }

    function displayAmount(target, display) {
      var element = root.querySelector('[data-unipayment-display="' + target + '"]');
      if (element) element.textContent = display ? display.primary + (display.dual && display.secondary ? ' (' + display.secondary + ')' : '') : '';
    }

    function applyCalculation(calculation) {
      lastCalculation = calculation;
      displayAmount('price', calculation.price_display);
      displayAmount('financed_amount', calculation.financed_amount_display);
      displayAmount('monthly_installment', calculation.monthly_installment_display);
      displayAmount('total_payable', calculation.total_payable_display);
      root.querySelector('[data-unipayment-display="glp"]').textContent = calculation.glp_display + '%';
      root.querySelector('[data-unipayment-display="gpr"]').textContent = calculation.gpr_display + '%';
      first.value = Number(calculation.first_installment || 0).toFixed(2);
      first.readOnly = !!calculation.first_installment_locked;
      firstRow.hidden = !calculation.show_first_installment && !calculation.first_installment_locked;
      errorBox.textContent = '';
      applyButton.disabled = false;
      secondaryButton.disabled = false;
    }

    function calculationPayload(action) {
      var scheme = selectedScheme();
      if (!scheme) return null;
      var payload = new URLSearchParams();
      payload.set('token', root.getAttribute('data-popup-token') || '');
      payload.set('popup_action', action || 'calculate');
      payload.set('id_product', root.getAttribute('data-product-id') || '0');
      payload.set('id_product_attribute', String(productAttributeId(document)));
      payload.set('quantity', String(quantity()));
      payload.set('scheme_type', scheme.scheme_type || activeType);
      payload.set('months', String(scheme.months));
      payload.set('filter_id', String(scheme.filter_id || 0));
      payload.set('first_installment', first.value || '0');
      return payload;
    }

    function requestCalculation(action) {
      var payload = calculationPayload(action);
      var endpoint = root.getAttribute('data-popup-endpoint');
      if (!payload || !endpoint) return Promise.reject(new Error('selection'));
      if (calculateRequest && typeof calculateRequest.abort === 'function') calculateRequest.abort();
      calculateRequest = typeof AbortController === 'function' ? new AbortController() : null;
      var sequence = ++calculateSequence;
      applyButton.disabled = true;
      secondaryButton.disabled = true;
      var options = {
        method: 'POST', credentials: 'same-origin', body: payload,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }
      };
      if (calculateRequest) options.signal = calculateRequest.signal;
      return fetch(endpoint, options).then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok || !body.success) throw new Error(body.message || 'calculation');
          if (sequence !== calculateSequence) throw new Error('stale');
          applyCalculation(body.calculation);
          return body;
        });
      }).catch(function (error) {
        if (sequence === calculateSequence && error.name !== 'AbortError' && error.message !== 'stale') errorBox.textContent = 'Неуспешно изчисление. Моля, опитайте отново.';
        throw error;
      });
    }

    function calculateNow() { requestCalculation('calculate').catch(function () {}); }

    function open(type, trigger) {
      resetPopup();
      activeType = type;
      lastOpenTrigger = trigger || null;
      if (!rebuildSchemes(type)) return;
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('unipayment-modal-open');
      calculateNow();
      select.focus();
    }

    function nativeAddButton() {
      return document.querySelector('.product-add-to-cart button[data-button-action="add-to-cart"], .product-add-to-cart button.add-to-cart, button[data-button-action="add-to-cart"]');
    }

    function addToCart(redirectUrl) {
      var button = nativeAddButton();
      if (!button || button.disabled || button.classList.contains('disabled')) {
        errorBox.textContent = 'Продуктът не може да бъде добавен в количката.';
        redirectPending = false;
        return;
      }
      if (redirectUrl && window.prestashop && typeof window.prestashop.on === 'function') {
        redirectPending = true;
        window.prestashop.on('updatedCart', function () {
          if (redirectPending) window.location.assign(redirectUrl);
        });
      } else {
        close();
      }
      button.click();
    }

    function handleSecondary() {
      if (!lastCalculation || redirectPending) return;
      if (root.getAttribute('data-button-action') !== 'buy') {
        addToCart('');
        return;
      }
      redirectPending = true;
      requestCalculation('preselect').then(function (body) {
        applyButton.disabled = true;
        secondaryButton.disabled = true;
        addToCart(body.checkout_url || root.getAttribute('data-checkout-url'));
      }).catch(function () { redirectPending = false; });
    }

    function transitionToStep2() {
      if (!lastCalculation) return;
      var state = {
        productId: parseInt(root.getAttribute('data-product-id'), 10) || 0,
        productAttributeId: productAttributeId(document), quantity: quantity(), type: activeType,
        schemeType: lastCalculation.scheme_type, kopCode: lastCalculation.kop_code,
        months: lastCalculation.months, filterId: lastCalculation.filter_id,
        firstInstallment: lastCalculation.first_installment, calculation: lastCalculation
      };
      root.unipaymentSelectedFinancing = state;
      root.dispatchEvent(new CustomEvent('unipayment:schemeSelected', { bubbles: true, detail: state }));
      setStep(2);
    }

    root.addEventListener('click', function (event) {
      var offerButton = event.target.closest('[data-unipayment-offer]');
      if (offerButton) open(offerButton.getAttribute('data-unipayment-offer'), offerButton);
      if (event.target.closest('[data-unipayment-close]')) close();
      if (event.target.closest('[data-unipayment-secondary]')) handleSecondary();
      if (event.target.closest('[data-unipayment-apply]')) transitionToStep2();
    });
    select.addEventListener('change', function () { first.value = '0'; first.readOnly = false; calculateNow(); });
    first.addEventListener('input', function () {
      if (first.readOnly) return;
      lastCalculation = null;
      applyButton.disabled = true;
      secondaryButton.disabled = true;
      window.clearTimeout(calculateTimer);
      calculateTimer = window.setTimeout(calculateNow, 400);
    });
    first.addEventListener('change', function () {
      if (first.readOnly) return;
      window.clearTimeout(calculateTimer);
      calculateNow();
    });
    root.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) close(); });

    root.unipaymentUpdate = function (next) {
      config = next;
      root.setAttribute('data-calculator', JSON.stringify(next || {}));
      root.hidden = !next;
      if (next) applyVisualConfig(root, next);
      root.querySelectorAll('[data-unipayment-offer]').forEach(function (button) {
        var type = button.getAttribute('data-unipayment-offer');
        var offer = next && next.offers ? next.offers[type] : null;
        button.hidden = !offer;
        var price = button.querySelector('[data-unipayment-preferred-price]');
        if (price && offer) price.textContent = buttonInstallmentLabel(offer);
      });
      if (!next || (activeType && (!next.offers || !next.offers[activeType]))) close();
      else if (!modal.hidden && activeType) { first.value = '0'; rebuildSchemes(activeType); calculateNow(); }
    };

    root.unipaymentInvalidatePopup = function () {
      if (modal.hidden) return;
      lastCalculation = null;
      applyButton.disabled = true;
      secondaryButton.disabled = true;
    };

    root.unipaymentRefresh = function () {
      window.clearTimeout(refreshTimer);
      refreshTimer = window.setTimeout(function () {
        var endpoint = root.getAttribute('data-endpoint');
        var productId = parseInt(root.getAttribute('data-product-id'), 10) || 0;
        if (!endpoint || !productId) return;
        var currentAttributeId = productAttributeId(document);
        var currentQuantity = quantity();
        var requestKey = productId + ':' + currentAttributeId + ':' + currentQuantity;
        if (requestKey === lastRequestKey) return;
        lastRequestKey = requestKey;
        if (refreshRequest && typeof refreshRequest.abort === 'function') refreshRequest.abort();
        refreshRequest = typeof AbortController === 'function' ? new AbortController() : null;
        var sequence = ++refreshSequence;
        var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'id_product=' + encodeURIComponent(productId) +
          '&id_product_attribute=' + encodeURIComponent(currentAttributeId) + '&quantity=' + encodeURIComponent(currentQuantity);
        var options = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (refreshRequest) options.signal = refreshRequest.signal;
        fetch(url, options).then(function (response) {
          if (!response.ok) throw new Error('calculator'); return response.json();
        }).then(function (payload) {
          if (sequence === refreshSequence) root.unipaymentUpdate(payload.success ? payload.calculator : null);
        }).catch(function (error) {
          if (sequence === refreshSequence && (!error || error.name !== 'AbortError')) { lastRequestKey = ''; root.unipaymentUpdate(null); }
        });
      }, 80);
    };
  }

  function initialize() { document.querySelectorAll(selector).forEach(setup); }
  function refresh() {
    document.querySelectorAll(selector).forEach(function (root) {
      setup(root);
      root.unipaymentInvalidatePopup();
      root.unipaymentRefresh();
    });
  }
  function scheduleDomRefresh() { window.clearTimeout(domRefreshTimer); domRefreshTimer = window.setTimeout(refresh, 0); }
  function mutationNeedsRefresh(mutation) {
    var target = mutation.target && mutation.target.nodeType === 1 ? mutation.target : mutation.target.parentElement;
    return !target || !target.closest(selector);
  }
  function initializeProductObservers() {
    var productActions = document.querySelector('.product-actions');
    if (!productActions || typeof MutationObserver !== 'function') return;
    var observer = new MutationObserver(function (mutations) { if (mutations.some(mutationNeedsRefresh)) scheduleDomRefresh(); });
    observer.observe(productActions, { childList: true, subtree: true });
  }
  function start() { initialize(); initializeProductObservers(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  if (window.prestashop && typeof window.prestashop.on === 'function') window.prestashop.on('updatedProduct', scheduleDomRefresh);
  document.addEventListener('input', function (event) {
    if (event.target && event.target.matches('#quantity_wanted, input[name="qty"], input[name="quantity"]')) refresh();
  });
  document.addEventListener('change', function (event) {
    if (event.target && event.target.matches('#quantity_wanted, input[name="qty"], input[name="quantity"]')) refresh();
  });
}());
