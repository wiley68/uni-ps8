'use strict';

var assert = require('assert');
var productAttributeId = require('../../views/js/product-calculator.js').productAttributeId;

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

console.log('OK (Phase 6 product combination DOM source)');
