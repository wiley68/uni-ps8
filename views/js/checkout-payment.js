(function () {
  'use strict';

  function money(value, currency) {
    try {
      return new Intl.NumberFormat(document.documentElement.lang || 'bg-BG', {
        style: 'currency', currency: currency, minimumFractionDigits: 2
      }).format(Number(value) || 0);
    } catch (error) {
      return (Number(value) || 0).toFixed(2) + ' ' + currency;
    }
  }

  function setup(root) {
    if (root.dataset.unipaymentReady === '1') return;
    var config;
    try { config = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (error) { return; }
    root.dataset.unipaymentReady = '1';
    var select = root.querySelector('[data-unipayment-scheme]');
    var kop = root.querySelector('[data-unipayment-kop]');
    var first = root.querySelector('[data-unipayment-first]');
    var firstRow = root.querySelector('[data-unipayment-first-row]');

    function render() {
      var scheme = config.schemes[select.selectedIndex];
      if (!scheme) return;
      kop.value = scheme.kop_code;
      first.readOnly = !!scheme.first_installment_locked;
      if (scheme.first_installment_locked) first.value = Number(scheme.first_installment).toFixed(2);
      else if (!first.value) first.value = '0';
      firstRow.hidden = !config.show_first_installment && !scheme.first_installment_locked;
      root.querySelector('[data-value="price"]').textContent = money(config.cart_total, config.currency_iso);
      ['financed_amount', 'monthly_installment', 'total_payable'].forEach(function (key) {
        root.querySelector('[data-value="' + key + '"]').textContent = money(scheme[key], config.currency_iso);
      });
      ['glp', 'gpr'].forEach(function (key) {
        root.querySelector('[data-value="' + key + '"]').textContent = Number(scheme[key] || 0).toFixed(2) + '%';
      });
    }
    select.addEventListener('change', render);
    render();
  }

  function initialize() { document.querySelectorAll('[data-unipayment-checkout]').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
  if (window.prestashop && typeof window.prestashop.on === 'function') window.prestashop.on('updatedDeliveryForm', initialize);
}());
