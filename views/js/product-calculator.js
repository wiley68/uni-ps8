(function () {
  'use strict';

  var selector = '[data-unipayment-calculator]';

  function parseConfig(root) {
    try {
      return JSON.parse(root.getAttribute('data-calculator') || '{}');
    } catch (error) {
      return null;
    }
  }

  function formatAmount(value, currency) {
    try {
      return new Intl.NumberFormat(document.documentElement.lang || 'bg-BG', {
        style: 'currency', currency: currency, minimumFractionDigits: 2
      }).format(Number(value) || 0);
    } catch (error) {
      return (Number(value) || 0).toFixed(2) + ' ' + currency;
    }
  }

  function attributeId() {
    var field = document.querySelector('input[name="id_product_attribute"]');
    return field ? Math.max(0, parseInt(field.value, 10) || 0) : 0;
  }

  function setup(root) {
    if (root.dataset.unipaymentReady === '1') return;
    root.dataset.unipaymentReady = '1';
    var config = parseConfig(root);
    var modal = root.querySelector('[data-unipayment-modal]');
    var select = root.querySelector('[data-unipayment-schemes]');
    var activeType = '';

    function renderScheme() {
      var offer = config && config.offers ? config.offers[activeType] : null;
      var scheme = offer && offer.schemes ? offer.schemes[select.selectedIndex] : null;
      if (!scheme) return;
      ['price', 'first_installment', 'financed_amount', 'monthly_installment', 'total_due'].forEach(function (key) {
        var target = root.querySelector('[data-unipayment-value="' + key + '"]');
        if (target) target.textContent = formatAmount(key === 'price' ? config.price : scheme[key], config.currency_iso);
      });
      ['glp', 'gpr'].forEach(function (key) {
        var target = root.querySelector('[data-unipayment-value="' + key + '"]');
        if (target) target.textContent = (Number(scheme[key]) || 0).toFixed(2) + '%';
      });
      var firstRow = root.querySelector('[data-unipayment-first-row]');
      if (firstRow) firstRow.hidden = !config.show_first_installment && !scheme.first_installment_locked;
    }

    function open(type, trigger) {
      var offer = config && config.offers ? config.offers[type] : null;
      if (!offer || !offer.schemes || !offer.schemes.length) return;
      activeType = type;
      select.textContent = '';
      offer.schemes.forEach(function (scheme) {
        var option = document.createElement('option');
        option.value = scheme.months + ':' + scheme.filter_id;
        option.textContent = (root.getAttribute('data-months-label') || '%d months').replace('%d', scheme.months);
        option.selected = scheme.months === offer.months;
        select.appendChild(option);
      });
      renderScheme();
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.dataset.returnFocus = trigger ? '1' : '';
      document.body.classList.add('unipayment-modal-open');
      select.focus();
    }

    function close() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('unipayment-modal-open');
    }

    root.addEventListener('click', function (event) {
      var offerButton = event.target.closest('[data-unipayment-offer]');
      if (offerButton) open(offerButton.getAttribute('data-unipayment-offer'), offerButton);
      if (event.target.closest('[data-unipayment-close]')) close();
      if (event.target.closest('[data-unipayment-select]')) {
        var offer = config.offers[activeType];
        var scheme = offer.schemes[select.selectedIndex];
        root.dispatchEvent(new CustomEvent('unipayment:schemeSelected', { bubbles: true, detail: {
          productId: config.product_id, productAttributeId: attributeId(), type: activeType,
          months: scheme.months, filterId: scheme.filter_id
        }}));
        close();
      }
    });
    select.addEventListener('change', renderScheme);
    root.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) close(); });

    root.unipaymentUpdate = function (next) {
      config = next;
      root.setAttribute('data-calculator', JSON.stringify(next || {}));
      root.hidden = !next;
      root.querySelectorAll('[data-unipayment-offer]').forEach(function (button) {
        var type = button.getAttribute('data-unipayment-offer');
        var offer = next && next.offers ? next.offers[type] : null;
        button.hidden = !offer;
        var price = button.querySelector('[data-unipayment-preferred-price]');
        if (price && offer) price.textContent = formatAmount(offer.monthly_installment, next.currency_iso) + ' / ' + (root.getAttribute('data-month-label') || 'month');
      });
      close();
    };
  }

  function initialize() {
    document.querySelectorAll(selector).forEach(setup);
  }

  function refresh() {
    document.querySelectorAll(selector).forEach(function (root) {
      setup(root);
      var endpoint = root.getAttribute('data-endpoint');
      var productId = parseInt(root.getAttribute('data-product-id'), 10) || 0;
      if (!endpoint || !productId) return;
      var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') +
        'id_product=' + encodeURIComponent(productId) + '&id_product_attribute=' + encodeURIComponent(attributeId());
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) { if (!response.ok) throw new Error('calculator'); return response.json(); })
        .then(function (payload) { root.unipaymentUpdate(payload.success ? payload.calculator : null); })
        .catch(function () { root.unipaymentUpdate(null); });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
  if (window.prestashop && typeof window.prestashop.on === 'function') window.prestashop.on('updatedProduct', refresh);
}());
