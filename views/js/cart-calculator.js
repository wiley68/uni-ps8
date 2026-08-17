(function () {
  'use strict';
  var selector = '[data-unipayment-cart-calculator]';
  function money(value, currency) {
    try { return new Intl.NumberFormat(document.documentElement.lang || 'bg-BG', { style: 'currency', currency: currency }).format(Number(value) || 0); }
    catch (error) { return (Number(value) || 0).toFixed(2) + ' ' + currency; }
  }
  function setup(root) {
    if (root.dataset.unipaymentReady === '1') return;
    root.dataset.unipaymentReady = '1';
    var config;
    try { config = JSON.parse(root.getAttribute('data-calculator') || '{}'); } catch (error) { return; }
    var modal = root.querySelector('[data-unipayment-cart-modal]');
    var select = root.querySelector('[data-unipayment-cart-schemes]');
    var active = '';
    root.unipaymentCartUpdate = function (next) {
      config = next;
      root.setAttribute('data-calculator', JSON.stringify(next || {}));
      root.hidden = !next;
      root.querySelectorAll('[data-unipayment-cart-offer]').forEach(function (button) {
        var type = button.getAttribute('data-unipayment-cart-offer');
        var offer = next && next.offers ? next.offers[type] : null;
        button.hidden = !offer;
        var price = button.querySelector('[data-unipayment-cart-price]');
        if (price && offer) price.textContent = money(offer.monthly_installment, next.currency_iso) + ' / ' + (root.getAttribute('data-month-label') || 'month');
      });
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('unipayment-modal-open');
    };
    function render() {
      var scheme = config.offers[active].schemes[select.selectedIndex];
      ['first_installment', 'financed_amount', 'monthly_installment', 'total_due'].forEach(function (key) {
        root.querySelector('[data-unipayment-cart-value="' + key + '"]').textContent = money(scheme[key], config.currency_iso);
      });
      root.querySelector('[data-unipayment-cart-value="price"]').textContent = money(config.cart_total, config.currency_iso);
      ['glp', 'gpr'].forEach(function (key) { root.querySelector('[data-unipayment-cart-value="' + key + '"]').textContent = Number(scheme[key]).toFixed(2) + '%'; });
      root.querySelector('[data-unipayment-cart-first]').hidden = !config.show_first_installment && !scheme.first_installment_locked;
    }
    root.addEventListener('click', function (event) {
      var button = event.target.closest('[data-unipayment-cart-offer]');
      if (button) {
        active = button.getAttribute('data-unipayment-cart-offer');
        select.textContent = '';
        config.offers[active].schemes.forEach(function (scheme) {
          var option = document.createElement('option');
          option.textContent = (root.getAttribute('data-months-label') || '%d months').replace('%d', scheme.months);
          option.selected = scheme.months === config.offers[active].months;
          select.appendChild(option);
        });
        render(); modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('unipayment-modal-open');
      }
      if (event.target.closest('[data-unipayment-cart-close]')) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('unipayment-modal-open'); }
    });
    select.addEventListener('change', render);
  }
  function initialize() { document.querySelectorAll(selector).forEach(setup); }
  function refresh() {
    initialize();
    var root = document.querySelector(selector);
    if (!root) return;
    fetch(root.getAttribute('data-endpoint'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) { return response.json(); })
      .then(function (payload) { root.unipaymentCartUpdate(payload.success ? payload.calculator : null); })
      .catch(function () { root.unipaymentCartUpdate(null); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
  if (window.prestashop && typeof window.prestashop.on === 'function') window.prestashop.on('updatedCart', refresh);
}());
