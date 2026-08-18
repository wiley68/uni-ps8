'use strict';

var assert = require('assert');
var productCalculator = require('../../views/js/product-calculator.js');
var productAttributeId = productCalculator.productAttributeId;
var buttonInstallmentLabel = productCalculator.buttonInstallmentLabel;

function element(attributes, value) {
  return {
    value: value,
    getAttribute: function (name) { return attributes[name] || null; }
  };
}

function productDocument(dataProduct, hiddenValue) {
  return {
    querySelector: function (selector) {
      if (selector === '#product-details[data-product]') {
        return dataProduct === null ? null : element({ 'data-product': dataProduct });
      }
      if (selector === 'input[name="id_product_attribute"]') {
        return hiddenValue === null ? null : element({}, hiddenValue);
      }
      return null;
    }
  };
}

assert.strictEqual(productAttributeId(productDocument('{"id_product_attribute":42}', '7')), 42);
assert.strictEqual(productAttributeId(productDocument('{"id_product_attribute":0}', null)), 0);
assert.strictEqual(productAttributeId(productDocument('{malformed', null)), 0);
assert.strictEqual(productAttributeId(productDocument(null, '7')), 7);
assert.strictEqual(productAttributeId(productDocument('{malformed', '9')), 9);
assert.strictEqual(buttonInstallmentLabel({
  months: 12,
  monthly_installment: 97.49,
  installment_label: '12 x 97.49 евро'
}), '12 x 97.49 евро');
assert.strictEqual(buttonInstallmentLabel(null), '');

console.log('OK (Phase 6 product combination DOM source and Woo button label)');
