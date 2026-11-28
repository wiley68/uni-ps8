'use strict';

var assert = require('assert');
var productCalculator = require('../../views/js/product-calculator.js');
var productAttributeId = productCalculator.productAttributeId;
var buttonInstallmentLabel = productCalculator.buttonInstallmentLabel;

var detailsSelector = '#product-details[data-product], .js-product-details[data-product]';
var resolverCases = 0;
function state(attribute, product = 3) {
  return JSON.stringify({ id_product: product, id_product_attribute: attribute });
}
function expectAttribute(label, expected, fixture = {}, hint) {
  var field = value => value === undefined ? null : { value };
  var forms = (fixture.forms || []).map(([product, attribute]) => ({
    id: 'add-to-cart-or-refresh',
    elements: { namedItem: name => field(name === 'id_product' ? product : attribute) }
  }));
  var root = {
    getAttribute: () => '3',
    closest: name => name === 'form' && fixture.enclosed ? forms[0] : null
  };
  var detail = (json, scope) => ({
    getAttribute: () => json,
    closest: selector => scope && selector.split(', ').includes(scope) ? {} : null
  });
  var classic = fixture.classic === undefined ? null : detail(fixture.classic);
  var details = (fixture.foreign || []).map(json => detail(json));
  for (var [json, scope] of fixture.unrelated || []) details.push(detail(json, scope));
  if (classic) details.push(classic);
  if (fixture.hummingbird !== undefined) details.push(detail(fixture.hummingbird));
  var doc = {
    querySelectorAll: name => name === detailsSelector ? details : name === 'form#add-to-cart-or-refresh' ? forms : [],
    querySelector: name => name === '#product-details[data-product]' ? classic : field(fixture.miniature)
  };
  assert.strictEqual(productAttributeId(doc, root, hint), expected, label);
  resolverCases++;
}

expectAttribute('Classic zero wins over all unrelated inputs', 0, {classic:state(0), miniature:'1'});
expectAttribute('Hummingbird zero is authoritative', 0, {hummingbird:state('0'), miniature:'1'});
expectAttribute('Classic positive wins over a form', 42, {classic:state(42), forms:[['3','7']], enclosed:true});
expectAttribute('Hummingbird positive and preceding foreign details', 55, {hummingbird:state('55'), foreign:[state(1, 99)]});
expectAttribute('ownerless state is ignored', 7, {classic:'{"id_product_attribute":22}', forms:[['3','7']]});
expectAttribute('foreign state and form are ignored', 0, {classic:state(22, 99), forms:[['99','7']], miniature:'1'});
expectAttribute('malformed JSON falls back to main form', 9, {classic:'{malformed', forms:[['3','9']], enclosed:true});
expectAttribute('enclosing main form wins over an earlier miniature', 7, {forms:[['3','7']], enclosed:true, miniature:'1'});
expectAttribute('adjacent main form accepts explicit zero', 0, {forms:[['3','0']], miniature:'1'});
expectAttribute('adjacent main form accepts verified positive', 7, {forms:[['3','7']], miniature:'1'});
expectAttribute('ambiguous adjacent forms are ignored', 0, {forms:[['3','7'],['99','9']], miniature:'1'});
expectAttribute('no reliable source falls back to zero', 0);
expectAttribute('miniature alone cannot supply an attribute', 0, {miniature:'1'});
expectAttribute('foreign native hint is ignored', 0, {}, {productId:99, attributeId:22});
expectAttribute('verified zero is a valid hint', 0, {}, {productId:3, attributeId:0});
expectAttribute('known conflicting live state blocks verified hint', null, {classic:state(11)}, {productId:3, attributeId:22});
expectAttribute('disagreeing live details block a request', null, {classic:state(11), hummingbird:state(22)});
for (var invalid of [null, undefined, -1, '-1', 1.5, '1.5', '', '12junk', '1e2', true, Number.MAX_SAFE_INTEGER + 1]) {
  expectAttribute('reject invalid theme attribute ' + invalid, 7, {classic:state(invalid), forms:[['3','7']]});
  expectAttribute('reject invalid form attribute ' + invalid, 0, {forms:[['3',invalid]], miniature:'1'});
}
expectAttribute('maximum safe integer is valid', Number.MAX_SAFE_INTEGER, {classic:state(String(Number.MAX_SAFE_INTEGER))});
expectAttribute('same-product quick-view and miniature state cannot conflict with main details', 22, {
  classic:state(22), unrelated:[[state(11), '.quickview'], [state(33), '.product-miniature']]
});

function nativeHint(attribute, states, extra = {}) {
  var doc = {createElement: () => ({
    content: {querySelectorAll: () => states.map(json => ({getAttribute: () => json}))}
  })};
  return productCalculator.productUpdateHint(doc, {
    id_product_attribute: attribute,
    product_details: '<div data-product></div>',
    ...extra
  });
}
var hintCases = [
  [22, [state(22)], {}, {productId:3, attributeId:22}],
  ['0', [state(0)], {}, {productId:3, attributeId:0}],
  [22, [state(undefined)], {}, {productId:3, attributeId:22}],
  [22, [state(11)], {}, null],
  [22, [state('broken')], {}, null],
  [22, [state(22), state(22, 99)], {}, null],
  [22, ['{malformed'], {}, null],
  [22, ['{"id_product_attribute":22}'], {}, null],
  [22, [state(22)], {is_quick_view:true}, null],
  [22, [], {product_details:undefined}, null],
  ['22junk', [state(22)], {}, null],
];
for (var [attribute, states, extra, expected] of hintCases) {
  assert.deepStrictEqual(nativeHint(attribute, states, extra), expected);
}
assert.strictEqual(buttonInstallmentLabel({
  months: 12,
  monthly_installment: 97.49,
  installment_label: '12 x 97.49 евро'
}), '12 x 97.49 евро');
assert.strictEqual(buttonInstallmentLabel(null), '');

var popupCalculationIdentity = productCalculator.popupCalculationIdentity;
var popupCalculationSchemeFields = productCalculator.popupCalculationSchemeFields;
var secondaryActionUsesNativeAddToCart = productCalculator.secondaryActionUsesNativeAddToCart;
var resolveCheckoutRedirectUrl = productCalculator.resolveCheckoutRedirectUrl;
var previous = { scheme_key: 'standard|STD|12|0', scheme_type: 'standard', kop_code: 'STD', months: 12, filter_id: 0 };
var selected = { key: 'standard|STD|24|0', scheme_type: 'standard', kop_code: 'STD', months: 24, filter_id: 0 };
var calculateIdentity = popupCalculationIdentity('calculate', selected, previous);
var applyIdentity = popupCalculationIdentity('apply', selected, previous);
assert.strictEqual(popupCalculationSchemeFields(calculateIdentity, selected, 'standard').months, 24);
assert.strictEqual(popupCalculationSchemeFields(calculateIdentity, selected, 'standard').scheme_key, 'standard|STD|24|0');
assert.strictEqual(popupCalculationSchemeFields(applyIdentity, selected, 'standard').months, 12);
assert.strictEqual(popupCalculationSchemeFields(applyIdentity, selected, 'standard').scheme_key, 'standard|STD|12|0');

assert.strictEqual(secondaryActionUsesNativeAddToCart('add_to_cart'), true);
assert.strictEqual(secondaryActionUsesNativeAddToCart('buy'), false);
assert.strictEqual(
  resolveCheckoutRedirectUrl({ checkout_url: 'https://example.test/order' }, 'https://fallback.test/order'),
  'https://example.test/order'
);
assert.strictEqual(resolveCheckoutRedirectUrl({}, 'https://fallback.test/order'), 'https://fallback.test/order');

var createPreselectOperationToken = productCalculator.createPreselectOperationToken;
var attachPreselectOperationToken = productCalculator.attachPreselectOperationToken;
assert.match(createPreselectOperationToken(), /^[a-f0-9]{32}$/);
var preselectPayload = new URLSearchParams();
attachPreselectOperationToken(preselectPayload, 'preselect', 'abc123def4567890abc123def4567890');
assert.strictEqual(preselectPayload.get('preselect_operation_token'), 'abc123def4567890abc123def4567890');
attachPreselectOperationToken(preselectPayload, 'calculate', 'abc123def4567890abc123def4567890');
assert.strictEqual(preselectPayload.get('preselect_operation_token'), 'abc123def4567890abc123def4567890');
preselectPayload = new URLSearchParams();
attachPreselectOperationToken(preselectPayload, 'calculate', 'ignored');
assert.strictEqual(preselectPayload.get('preselect_operation_token'), null);

console.log('OK product calculator: ' + resolverCases + ' DOM resolver cases, ' + hintCases.length + ' native hint cases and 14 existing helper assertions');
