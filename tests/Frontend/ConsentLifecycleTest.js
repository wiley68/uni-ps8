'use strict';

// Executes the production controllers with fake DOM, timers and fetch; no shop/network.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const {webcrypto} = require('node:crypto');
let assertions = 0;
function check(ok, label) { assertions++; assert.ok(ok, label); }
class Node {
    constructor(attrs = {}) {
        this.attrs = attrs; this.dataset = {}; this.events = {}; this.nodes = {}; this.children = [];
        this.nodeType = 1;
        this.hidden = false; this.disabled = false; this.checked = false; this.readOnly = false;
        this.value = ''; this.defaultValue = ''; this.isConnected = true; this.style = { removeProperty() {}, setProperty() {} };
        this.offsetParent = {}; this.classes = new Set();
        this.classList = { add: (...keys) => keys.forEach(k => this.classes.add(k)), remove: (...keys) => keys.forEach(k => this.classes.delete(k)), toggle: (k, on) => on ? this.classes.add(k) : this.classes.delete(k), contains: k => this.classes.has(k) };
    }
    getAttribute(k) { return this.attrs[k] ?? null; }
    setAttribute(k, v) { this.attrs[k] = v; }
    removeAttribute(k) { delete this.attrs[k]; }
    hasAttribute(k) { return k in this.attrs; }
    addEventListener(k, fn) { (this.events[k] ||= []).push(fn); }
    removeEventListener(k, fn) { this.events[k] = (this.events[k] || []).filter(f => f !== fn); }
    emit(k, target = this) {
        const event = { target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, stopImmediatePropagation() {} };
        (this.events[k] || []).forEach(fn => fn(event)); return event;
    }
    querySelector(k) { return this.nodes[k] || null; }
    matches() { return false; }
    querySelectorAll(k) {
        if (k === '[data-unipayment-consent-row]') return this.rows || [];
        if (k === '[data-unipayment-consent-checkbox]') return (this.rows || []).map(r => r.box).filter(Boolean);
        if (k === 'input') return this.inputs || [];
        return [];
    }
    closest(k) { return k === 'form' ? this.form || null : null; }
    appendChild(node) { this.children.push(node); if (node.selected) this.value = node.value; }
    dispatchEvent() {}
    focus() {}
    contains(node) { return node === this || this.children.includes(node); }
}
const contexts = zero => ({ valid: true, interest_state: zero ? 'zero' : 'nonzero', scheme_identity: JSON.stringify([zero ? 'ZERO' : 'STD', 12, 0, 'standard', zero ? 'zero' : 'nonzero']), applicable_ids: zero ? [1,2,3,4] : [1,2,5,6], required_ids: zero ? [2,4] : [2,6] });
const detailSelector = '#product-details[data-product], .js-product-details[data-product]';
const productRootSelector = '[data-unipayment-calculator][data-product-id]';
const quantitySelector = '#quantity_wanted, input[name="qty"], input[name="quantity"]';

function environment(mode, product = {}) {
    const root = new Node(); const document = new Node(); document.readyState = 'complete'; document.body = new Node();
    const form = new Node(); root.form = form;
    const rows = ['all','all','zero_interest','zero_interest','nonzero_interest','nonzero_interest'].map((scope, i) => {
        const row = new Node({ 'data-unipayment-consent-row': String(i + 1), 'data-unipayment-interest-scope': scope });
        row.box = i % 2 ? new Node() : null;
        if (row.box) { row.box.value = String(i + 1); row.nodes['[data-unipayment-consent-checkbox]'] = row.box; }
        return row;
    }); root.rows = rows;
    const get = key => root.nodes[key] ||= new Node();
    const select = get(mode === 'checkout' ? '[data-unipayment-scheme]' : '[data-unipayment-schemes]');
    const first = get('[data-unipayment-first]'); first.value = '0';
    get('input[name="unipayment_cart_snapshot"]').value = 'initial-signature';
    const modal = get('[data-unipayment-modal]'); modal.hidden = true;
    for (const key of ['[data-unipayment-first-row]','[data-unipayment-checkout-error]','[data-unipayment-scheme-hidden]','[data-unipayment-kop]','[data-unipayment-submit]','[data-unipayment-apply]','[data-unipayment-secondary]','[data-unipayment-popup-error]','[data-unipayment-submit-error]','[data-unipayment-customer-form]','[data-unipayment-step="1"]','[data-unipayment-step="2"]','[data-unipayment-step="3"]']) get(key);
    get('[data-unipayment-display="glp"]'); get('[data-unipayment-display="gpr"]');
    const customer = get('[data-unipayment-customer-form]');
    for (const [name,value] of Object.entries({first_name:'Ivan',last_name:'Ivanov',address:'Sofia 1',phone:'+359888123',email:'ivan@example.test'})) {
        const field = new Node(); field.value = field.defaultValue = value;
        customer.nodes['[name="' + name + '"]'] = field;
        customer.nodes['[data-unipayment-field="' + name + '"]'] = field;
        root.nodes['[data-unipayment-field-error="' + name + '"]'] = new Node();
    }
    const standard = {key:'STD|12',scheme_type:'standard',kop_code:'STD',months:12,filter_id:0,consent_context:contexts(false)};
    const zero = {...standard,key:'ZERO|12',kop_code:'ZERO',consent_context:contexts(true)};
    const config = {schemes:[standard,zero],offers:{standard:{schemes:[standard,zero],preferred_scheme_key:standard.key}},process2:false};
    select.value = standard.key;
    root.attrs['data-config'] = root.attrs['data-calculator'] = JSON.stringify(config);
    root.attrs['data-calculate-endpoint'] = root.attrs['data-popup-endpoint'] = root.attrs['data-endpoint'] = '/fake';
    root.attrs['data-popup-token'] = 'test'; root.attrs['data-product-id'] = String(product.id ?? 42);
    if (product.buttonAction) root.attrs['data-button-action'] = product.buttonAction;
    if (mode === 'cart') root.attrs['data-unipayment-source'] = 'cart';
    const payment = new Node(); payment.checked = true;
    document.nodes['input[name="payment-option"][data-module-name="unipayment"]'] = payment;
    document.nodes['#payment-confirmation button'] = new Node();
    document.nodes['[data-unipayment-cart-calculator]'] = root;
    let details = [];
    function createDetailsBlock() {
        const block = new Node();
        block.parentElement = document.body;
        block.querySelector = key => key.includes('#product-details') ? block.details : null;
        return block;
    }
    let detailsBlock = createDetailsBlock();
    const detailData = (id, attribute) => JSON.stringify({id_product:id,id_product_attribute:attribute,cache_default_attribute:attribute});
    function setDetails(attribute, id = Number(root.attrs['data-product-id'])) {
        if (details[0]) details[0].isConnected = false;
        const detail = new Node({'data-product':detailData(id, attribute)});
        detail.parentElement = detailsBlock;
        detail.matches = key => key.includes('#product-details');
        details = attribute === undefined ? [] : [detail];
        detailsBlock.details = details[0];
        document.nodes['#product-details[data-product]'] = details[0] || null;
        return details[0];
    }
    setDetails(product.attribute);
    const miniatures = (product.miniatures || []).map(value => Object.assign(new Node(), {value: String(value)}));
    document.nodes['input[name="id_product_attribute"]'] = miniatures[0];
    const quantity = new Node(); quantity.value = String(product.quantity ?? 1);
    quantity.matches = selector => selector === quantitySelector;
    document.nodes[quantitySelector] = quantity;
    let attached = true;
    document.querySelectorAll = key => {
        if (key === detailSelector) return details;
        if (key === quantitySelector) return [quantity];
        if (key === productRootSelector) return attached && mode === 'product' ? [root] : [];
        return attached && (key === '[data-unipayment-checkout]' || key === '[data-unipayment-calculator]') ? [root] : [];
    };
    const requests = [], timers = new Map(), bus = {}, observers = [];
    const observations = [];
    function deliverMutation(record) {
        for (const {callback, target, options} of observations) {
            if (!options[record.type]) continue;
            if (record.type === 'attributes' && options.attributeFilter && !options.attributeFilter.includes(record.attributeName)) continue;
            let node = record.target;
            while (options.subtree && node && node !== target) node = node.parentElement;
            if (node === target) callback([record]);
        }
    }
    let timer = 0;
    const jquery = node => ({ on: (event, handler) => node.addEventListener(event.split('.')[0] + ':jquery', handler), off: (event, handler) => node.removeEventListener(event.split('.')[0] + ':jquery', handler) });
    const sandbox = { document, console, URLSearchParams, AbortController, crypto:webcrypto, HTMLElement: Node, CustomEvent: class {},
        MutationObserver: class {
            constructor(fn) { observers.push(fn); this.callback = fn; }
            observe(target, options) { observations.push({callback:this.callback,target,options}); }
        },
        setTimeout: fn => { timers.set(++timer, fn); return timer; }, clearTimeout: id => timers.delete(id),
        getComputedStyle: () => ({visibility:'visible',display:'block'}), matchMedia: () => ({matches:true}),
        jQuery: jquery, prestashop: { on: (key,fn) => (bus[key] ||= []).push(fn) },
        fetch: (url,options) => new Promise((resolve,reject) => requests.push({url,options,resolve(body,status=true) { resolve({ok:status,json:()=>Promise.resolve(body)}); },reject})),
        location: {assign(){}},
    }; sandbox.window = sandbox;
    document.createElement = tag => tag !== 'template' ? new Node() : {
        set innerHTML(html) {
            const states = Array.from(html.matchAll(/data-product='([^']+)'/g), match => new Node({'data-product':match[1]}));
            this.content = {querySelectorAll: key => key === detailSelector ? states : []};
        },
    };
    vm.createContext(sandbox);
    const execute = file => vm.runInContext(fs.readFileSync(path.join(__dirname,'../../views/js',file),'utf8'),sandbox,{filename:file});
    execute('shop-consents.js'); execute(mode === 'checkout' ? 'checkout-payment.js' : 'product-calculator.js');
    if (mode === 'cart') execute('cart-calculator.js');
    function click(selector, attrs = {}) {
        const target = new Node(attrs); target.closest = key => key === selector ? target : null;
        root.emit('click',target);
    }
    function scheme(zero) { select.value = zero ? 'ZERO|12' : 'STD|12'; select.emit('change'); }
    function resolve(request, zero, extra = {}) {
        request.resolve({success:true,calculation:{...config.schemes[zero?1:0],scheme_key:zero?'ZERO|12':'STD|12',first_installment:0,glp_display:'0.00',gpr_display:'0.00',consent_context:contexts(zero),...extra},popup_submission_token:'test-token'});
    }
    return {root,rows,select,first,modal,form,requests,timers,bus,observers,sandbox,config,click,scheme,resolve,get,
        checkIds: (...ids) => rows.forEach((row,i)=>{if(row.box) row.box.checked=ids.includes(i+1);}),
        flushTimers: () => { const current=[...timers.values()]; timers.clear(); current.forEach(fn=>fn()); },
        event: (key, payload) => (bus[key]||[]).forEach(fn=>fn(payload)),
        changeQuantity(value, event = 'input') {
            quantity.value = String(value);
            if (event) document.emit(event, quantity);
        },
        setDetails, observations, deliverMutation,
        convergeDetails(attribute, replaceBlock) {
            if (replaceBlock) {
                const removed = detailsBlock;
                removed.isConnected = false;
                detailsBlock = createDetailsBlock();
                setDetails(attribute);
                deliverMutation({type:'childList',target:document.body,addedNodes:[detailsBlock],removedNodes:[removed]});
            } else {
                const target = details[0];
                target.setAttribute('data-product', detailData(Number(root.attrs['data-product-id']), attribute));
                deliverMutation({type:'attributes',target,attributeName:'data-product'});
            }
        },
        nativeAddButton(button) {
            document.nodes['.product-add-to-cart button[data-button-action="add-to-cart"], .product-add-to-cart button.add-to-cart, button[data-button-action="add-to-cart"]'] = button;
        },
        detach: () => { root.isConnected=false; attached=false; observers.forEach(fn=>fn([])); },
    };
}
async function flush() { for (let i=0;i<12;i++) await Promise.resolve(); }

function nativeUpdate(id, attribute) {
    return {
        id_product_attribute: attribute,
        product_details: "<div class='js-product-details' data-product='" +
            JSON.stringify({id_product:id, id_product_attribute:attribute}) + "'></div>",
    };
}
function openProduct(e) {
    e.click('[data-unipayment-offer]', {'data-unipayment-offer':'standard'});
}
function refreshTimers(e) {
    e.flushTimers();
    e.flushTimers();
}
function requestFields(request) {
    return request?.options?.body || new URL(request?.url || '/', 'https://example.test').searchParams;
}
function matchesRequest(request, action, selection, scheme) {
    const fields = requestFields(request);
    return !!request && (fields.get('popup_action') || 'GET') === action &&
        ['id_product','id_product_attribute','quantity'].map(key=>fields.get(key)).join('/') === selection &&
        (scheme === undefined || fields.get('scheme_key') === scheme);
}
function nextOffer(e, kop = 'B') {
    const scheme = {...e.config.schemes[0], key:kop+'|12', kop_code:kop};
    return {...e.config, offers:{standard:{schemes:[scheme],preferred_scheme_key:scheme.key}}};
}
function appliedOffer(e) {
    return JSON.parse(e.root.getAttribute('data-calculator')).offers?.standard?.preferred_scheme_key;
}
function changeCombination(e, attribute, observeReplacement = false) {
    e.event('updateProduct', {eventType:'updatedProductCombination'});
    const details = e.setDetails(attribute);
    e.event('updatedProduct', nativeUpdate(42, attribute));
    if (observeReplacement) {
        e.deliverMutation({type:'childList',target:details.parentElement,addedNodes:[details],removedNodes:[]});
    }
}
async function calculateProduct(e) {
    openProduct(e);
    e.resolve(e.requests.at(-1), false);
    await flush();
}
async function startBuy(e) {
    await calculateProduct(e);
    e.click('[data-unipayment-secondary]');
    return e.requests.at(-1);
}
function finishBuy(e, request) {
    request.resolve({
        success:true, checkout_url:'/checkout',
        calculation:{...e.config.schemes[0],scheme_key:'STD|12',first_installment:0,consent_context:contexts(false)},
    });
}

async function preselectOverlap(change, getFirst) {
    const label = change+(getFirst?' GET first':' POST first');
    const e = environment('product', {attribute:11,buttonAction:'buy'});
    const redirects = [];
    e.sandbox.location.assign = url => redirects.push(url);
    const preselect = await startBuy(e);
    check(matchesRequest(preselect, 'preselect', '42/11/1', 'STD|12'), label+' preselect starts for old offer');
    if (change === 'attribute') changeCombination(e,22);
    else if (change === 'quantity') e.changeQuantity(2);
    else e.root.unipaymentRefresh();
    refreshTimers(e);
    const get = e.requests.at(-1);
    const selection = change === 'attribute' ? '42/22/1' : change === 'quantity' ? '42/11/2' : '42/11/1';
    check(matchesRequest(get, 'GET', selection), label+' requests current offer');
    if (getFirst) {
        get.resolve({success:true,calculator:nextOffer(e)}); await flush();
        check(appliedOffer(e) === 'STD|12' && e.requests.length === 3, label+' cannot interrupt Buy');
        finishBuy(e, preselect); await flush();
    } else {
        finishBuy(e, preselect); await flush();
        get.resolve({success:true,calculator:nextOffer(e)}); await flush();
    }
    refreshTimers(e);
    if (change === 'unchanged') {
        check(redirects.length === 1 && redirects[0] === '/checkout' &&
            e.requests.length === 3 && appliedOffer(e) === 'STD|12', label+' redirects once without calculating again');
    } else {
        check(redirects.length === 0 && appliedOffer(e) === 'B|12' && e.requests.length === 4 &&
            matchesRequest(e.requests.at(-1), 'calculate', selection, 'B|12'), label+' retains B and blocks obsolete redirect');
    }
}

const productRegressions = {
    async 'T01 authoritative zero payloads'() {
        const fixture = {id:3, attribute:0, miniatures:[1,16,1,9], buttonAction:'buy'};
        const e = environment('product', fixture);
        e.root.unipaymentRefresh(); refreshTimers(e);
        check(matchesRequest(e.requests[0], 'GET', '3/0/1'), 'miniatures cannot supply the refresh attribute');
        e.requests[0].resolve({success:true,calculator:e.config}); await flush();
        await calculateProduct(e);
        check(matchesRequest(e.requests.at(-1), 'calculate', '3/0/1', 'STD|12'), 'initial calculation sends the explicit zero');
        e.click('[data-unipayment-apply]');
        check(matchesRequest(e.requests.at(-1), 'issue_submission_token', '3/0/1', 'STD|12'), 'token bound to explicit zero');
        e.resolve(e.requests.at(-1), false); await flush();
        e.checkIds(2,6); e.click('[data-unipayment-submit]'); await flush();
        check(matchesRequest(e.requests.at(-1), 'apply', '3/0/1', 'STD|12') &&
            requestFields(e.requests.at(-1)).get('popup_submission_token') === 'test-token', 'apply includes token for zero');
        const buy = environment('product', fixture);
        const preselect = await startBuy(buy);
        check(matchesRequest(preselect, 'preselect', '3/0/1', 'STD|12') &&
            /^[a-f0-9]{32}$/.test(requestFields(preselect).get('preselect_operation_token')), 'Buy binds an operation token to zero');
    },
    async 'T03 immediate hint and conflicting DOM'() {
        const e = environment('product');
        e.event('updatedProduct', nativeUpdate(42,22)); openProduct(e);
        check(matchesRequest(e.requests[0], 'calculate', '42/22/1'), 'verified hint is immediately available without live details');
        refreshTimers(e);
        check(matchesRequest(e.requests.at(-1), 'GET', '42/22/1'), 'verified hint survives deferred refresh');

        const conflict = environment('product', {attribute:11});
        conflict.event('updateProduct', {eventType:'updatedProductCombination'});
        conflict.event('updatedProduct', nativeUpdate(42,22)); openProduct(conflict); refreshTimers(conflict);
        check(conflict.requests.length === 0, 'old live details conflict with the confirmed event and block requests');
        conflict.setDetails(22); conflict.event('updatedProduct', nativeUpdate(42,22)); refreshTimers(conflict);
        check(matchesRequest(conflict.requests.at(-1), 'GET', '42/22/1'), 'coherent details recover the offer');
        conflict.requests.at(-1).resolve({success:true,calculator:nextOffer(conflict)}); await flush();
        check(matchesRequest(conflict.requests.at(-1), 'calculate', '42/22/1', 'B|12'), 'recovery calculates only after B is applied');
    },
    async 'T04 quantity keeps the last verified combination'() {
        const e = environment('product', {attribute:22});
        await calculateProduct(e);
        e.click('[data-unipayment-apply]'); e.resolve(e.requests.at(-1), false); await flush();
        check(e.root.unipaymentPopupSubmissionToken === 'test-token', 'Step 2 starts with a token');
        e.setDetails(undefined); e.changeQuantity(2);
        e.event('updateProduct', {eventType:'updatedProductQuantity'});
        check(e.rows.every(row=>row.hidden) && !e.root.unipaymentPopupSubmissionToken &&
            e.get('[data-unipayment-step="2"]').hidden, 'quantity invalidates Step 2, consent and token');
        e.checkIds(2,6); e.click('[data-unipayment-submit]'); await flush();
        check(e.requests.length === 2, 'no apply after quantity invalidation');
        refreshTimers(e);
        check(matchesRequest(e.requests.at(-1), 'GET', '42/22/2'), 'refresh keeps verified 22 without DOM');
        e.requests.at(-1).resolve({success:true,calculator:nextOffer(e)}); await flush();
        check(matchesRequest(e.requests.at(-1), 'calculate', '42/22/2', 'B|12'), 'new quantity uses the refreshed offer');
    },
    async 'T05 rapid changes, stale offers and event ownership'() {
        const e = environment('product', {attribute:11});
        openProduct(e);
        changeCombination(e, 22); refreshTimers(e);
        const get22 = e.requests.at(-1);
        check(matchesRequest(get22, 'GET', '42/22/1'), 'native refresh uses 22');
        changeCombination(e, 33); refreshTimers(e);
        const get33 = e.requests.at(-1);
        check(matchesRequest(get33, 'GET', '42/33/1') && get22.options.signal.aborted, '33 supersedes the GET for 22');
        get33.resolve({success:true,calculator:nextOffer(e,'C')}); await flush();
        get22.resolve({success:true,calculator:nextOffer(e)}); await flush();
        check(appliedOffer(e) === 'C|12' && matchesRequest(e.requests.at(-1), 'calculate', '42/33/1', 'C|12'), 'late offer B cannot replace C');
        e.event('updatedProduct', nativeUpdate(99,88));
        e.event('updateProduct', {eventType:'updatedProductCombination',is_quick_view:true});
        e.event('updatedProduct', {...nativeUpdate(42,77),is_quick_view:true});
        e.event('updatedProduct', {id_product_attribute:66});
        e.event('updatedProduct', {...nativeUpdate(42,55),id_product_attribute:'55junk'});
        refreshTimers(e);
        check(e.requests.slice(3).every(request=>requestFields(request).get('id_product_attribute') === '33'), 'unrelated events cannot replace verified 33');
        e.event('updateProduct', {eventType:'updatedProductCombination'});
        e.setDetails(undefined); e.event('updatedProduct', nativeUpdate(42,0)); refreshTimers(e);
        check(matchesRequest(e.requests.at(-1), 'GET', '42/0/1'), 'verified native zero replaces positive 33');
    },
    async 'T06 silent changes guard preselect, token and apply'() {
        for (const action of ['secondary','apply','submit']) {
            const e = environment('product', {attribute:11,buttonAction:'buy'});
            await calculateProduct(e);
            if (action === 'submit') {
                e.click('[data-unipayment-apply]'); e.resolve(e.requests.at(-1), false); await flush();
            }
            const before = e.requests.length;
            e.setDetails(22); e.changeQuantity(2, null);
            e.checkIds(2,6); e.click('[data-unipayment-'+action+']'); await flush();
            check(e.requests.length === before && !e.root.unipaymentPopupSubmissionToken, action+' cannot send the old finance context');
            refreshTimers(e);
            check(matchesRequest(e.requests.at(-1), 'GET', '42/22/2'), action+' recovers by refreshing live selection');
            e.requests.at(-1).resolve({success:true,calculator:nextOffer(e)}); await flush();
            check(matchesRequest(e.requests.at(-1), 'calculate', '42/22/2', 'B|12'), action+' requires a fresh calculation');
            e.resolve(e.requests.at(-1), false, {scheme_key:'B|12',kop_code:'B'}); await flush();
            e.click('[data-unipayment-apply]');
            check(matchesRequest(e.requests.at(-1), 'issue_submission_token', '42/22/2', 'B|12'), action+' accepts the refreshed offer for token issuance');
        }
    },
    async 'T06 initial offer is limited to the rendered quantity'() {
        const e = environment('product', {quantity:2});
        e.event('updatedProduct', nativeUpdate(42,22)); openProduct(e);
        check(e.requests.length === 0, 'first opening at quantity 2 cannot use the server-rendered unit offer');
        refreshTimers(e);
        check(matchesRequest(e.requests.at(-1),'GET','42/22/2'), 'first opening refreshes verified 22 and quantity 2');
        e.requests.at(-1).resolve({success:true,calculator:nextOffer(e)}); await flush();
        check(matchesRequest(e.requests.at(-1),'calculate','42/22/2','B|12'), 'calculation waits for the offer for two units');
    },
    async 'T07 late calculate and token cannot restore context'() {
        for (const action of ['calculate','issue_submission_token']) {
            const e = environment('product', {attribute:11});
            openProduct(e);
            if (action === 'issue_submission_token') {
                e.resolve(e.requests.at(-1), false); await flush(); e.click('[data-unipayment-apply]');
            }
            const old = e.requests.at(-1);
            check(matchesRequest(old, action, '42/11/1'), 'old '+action+' is outstanding');
            e.setDetails(22); e.changeQuantity(2, null);
            e.resolve(old, false); await flush();
            check(!e.root.unipaymentPopupSubmissionToken && e.rows.every(row=>row.hidden) &&
                e.get('[data-unipayment-step="2"]').hidden && e.get('[data-unipayment-apply]').disabled,
                'late '+action+' cannot restore consents, token or Step 2 after silent DOM change');
            refreshTimers(e);
            check(matchesRequest(e.requests.at(-1), 'GET', '42/22/2'), 'late '+action+' recovers with current offer refresh');
        }
    },
    async 'T07 selection changes before apply dispatch'() {
        const e = environment('product', {attribute:11});
        await calculateProduct(e);
        e.click('[data-unipayment-apply]'); e.resolve(e.requests.at(-1), false); await flush();
        e.checkIds(2,6); e.click('[data-unipayment-submit]');
        e.setDetails(22); e.changeQuantity(2, null);
        await flush(); refreshTimers(e);
        check(!e.requests.some(request=>requestFields(request).get('popup_action') === 'apply'), 'pre-dispatch selection change prevents apply');
        check(matchesRequest(e.requests.at(-1), 'GET', '42/22/2') &&
            e.get('[data-unipayment-step="2"]').hidden && !e.root.unipaymentPopupSubmissionToken, 'apply recovers via Step 1 without the old token');
    },
    async 'T09 duplicate quantity notifications do not strand the offer'() {
        const e = environment('product', {attribute:11,buttonAction:'buy'});
        const preselect = await startBuy(e);
        e.changeQuantity(2, 'input'); refreshTimers(e);
        const firstGet = e.requests.at(-1);
        e.changeQuantity(2, 'change'); refreshTimers(e);
        const currentGet = e.requests.at(-1);
        check(matchesRequest(currentGet, 'GET', '42/11/2'), 'duplicate notification never calculates quantity 2 with scheme A');
        finishBuy(e, preselect); await flush();
        if (firstGet !== currentGet) firstGet.resolve({success:true,calculator:nextOffer(e,'STALE')});
        currentGet.resolve({success:true,calculator:nextOffer(e)}); await flush(); refreshTimers(e);
        check(appliedOffer(e) === 'B|12' && matchesRequest(e.requests.at(-1), 'calculate', '42/11/2', 'B|12'), 'B installs for quantity 2');
        check(!e.requests.some(request=>matchesRequest(request,'calculate','42/11/2','STD|12')), 'old A is never calculated for quantity 2');
    },
    async 'T10 unavailable offer state and same-context recovery'() {
        const e = environment('product', {attribute:11,buttonAction:'buy'});
        const preselect = await startBuy(e);
        e.changeQuantity(2); refreshTimers(e);
        e.requests.at(-1).resolve({success:true,calculator:null}); await flush();
        finishBuy(e, preselect); await flush();
        check(e.modal.hidden && e.root.hidden && appliedOffer(e) === undefined &&
            e.get('[data-unipayment-apply]').disabled, 'unavailable offer clears and closes the popup, including during Buy');
        const count = e.requests.length;
        openProduct(e); refreshTimers(e);
        check(e.requests.length === count, 'unavailable offer has no stale calculation or automatic retry');
        e.changeQuantity(2, 'change'); refreshTimers(e);
        check(matchesRequest(e.requests.at(-1), 'GET', '42/11/2') && e.requests.length === count+1, 'same context can explicitly refresh again');
        e.requests.at(-1).resolve({success:true,calculator:nextOffer(e)}); await flush();
        check(!e.root.hidden && e.modal.hidden && appliedOffer(e) === 'B|12', 'available offer restores launcher with no stale popup state');
        openProduct(e);
        check(matchesRequest(e.requests.at(-1), 'calculate', '42/11/2', 'B|12'), 'reopened popup uses only the recovered offer');
    },
    async 'T11 native Add to Cart still uses the theme button'() {
        const e = environment('product', {attribute:11,buttonAction:'add_to_cart'});
        const button = new Node();
        let clicks = 0;
        button.click = () => { clicks++; };
        e.nativeAddButton(button);
        await calculateProduct(e);
        e.click('[data-unipayment-secondary]');
        check(clicks === 1 && e.modal.hidden && e.requests.length === 1, 'native Add to Cart clicks once and does not preselect');
    },
};

for (const change of ['attribute','quantity','unchanged']) {
    for (const getFirst of [true,false]) {
        const name = 'T08 '+change+(getFirst?' GET first':' POST first');
        productRegressions[name] = () => preselectOverlap(change, getFirst);
    }
}

async function applyObservedOffer(e, attribute, kop) {
    const refresh = e.requests.at(-1);
    check(matchesRequest(refresh,'GET','42/'+attribute+'/1'), 'refresh uses the coherent attribute '+attribute);
    refresh.resolve({success:true,calculator:nextOffer(e,kop)}); await flush();
    const calculation = e.requests.at(-1);
    check(appliedOffer(e) === kop+'|12' && matchesRequest(calculation,'calculate','42/'+attribute+'/1',kop+'|12'),
        'calculation uses the installed offer '+kop);
    const count = e.requests.length;
    e.convergeDetails(attribute,false); refreshTimers(e);
    check(e.requests.length === count, 'equivalent detail state does not restart a pending calculation');
    e.resolve(calculation,false,{scheme_key:kop+'|12',kop_code:kop}); await flush();
    check(!e.get('[data-unipayment-apply]').disabled && !e.get('[data-unipayment-secondary]').disabled &&
        !e.get('[data-unipayment-popup-error]').textContent, 'calculation restores the popup actions');
}

async function recoverAfterDetailsChange(e, attribute, replaceBlock, kop) {
    const before = e.requests.length;
    e.event('updateProduct',{eventType:'updatedProductCombination'});
    e.event('updatedProduct',nativeUpdate(42,attribute)); refreshTimers(e);
    check(e.requests.length === before && e.get('[data-unipayment-apply]').disabled, 'conflicting DOM blocks the old offer');
    e.convergeDetails(attribute,replaceBlock); refreshTimers(e);
    check(e.requests.length === before+1, 'DOM convergence alone starts one refresh');
    await applyObservedOffer(e,attribute,kop);
    refreshTimers(e);
    check(e.requests.length === before+2, 'requests stop after applying and calculating the current offer');
}

async function verifyProductObserver() {
    for (const replaceBlock of [false,true]) {
        const e = environment('product',{attribute:11});
        await calculateProduct(e);
        changeCombination(e,22,true); refreshTimers(e);
        await applyObservedOffer(e,22,'B');
        await recoverAfterDetailsChange(e,33,replaceBlock,'C');
        await recoverAfterDetailsChange(e,44,!replaceBlock,'D');
        check(e.observations.length === 1 && e.observations[0].target === e.sandbox.document.body,
            'one stable observation survives repeating detail replacements');
    }
    const e = environment('product',{attribute:11});
    await calculateProduct(e);
    const body = e.sandbox.document.body;
    for (const scope of ['.product-miniature','.quickview','[data-unipayment-calculator]']) {
        const node = new Node();
        node.parentElement = body;
        node.closest = selector => selector.split(', ').includes(scope) ? node : null;
        node.matches = selector => selector.includes('#product-details');
        e.deliverMutation({type:'attributes',target:node,attributeName:'data-product'});
        e.deliverMutation({type:'childList',target:node,addedNodes:[new Node()],removedNodes:[]});
    }
    e.deliverMutation({type:'childList',target:body,addedNodes:[new Node()],removedNodes:[]});
    refreshTimers(e);
    check(e.requests.length === 1 && appliedOffer(e) === 'STD|12', 'isolated mutations leave the applied offer and request count intact');
}

async function openWithVerifiedOffer() {
    const e = environment('product');
    e.event('updateProduct',{eventType:'updatedProductCombination'});
    e.event('updatedProduct',nativeUpdate(42,22)); refreshTimers(e);
    const offer = e.requests.at(-1);
    check(matchesRequest(offer,'GET','42/22/1'), 'verified hint refreshes the offer while details are absent');
    offer.resolve({success:true,calculator:nextOffer(e,'B')}); await flush();
    openProduct(e);
    return e;
}

function insertVerifiedDetails(e) {
    const details = e.setDetails(22);
    e.deliverMutation({type:'childList',target:details.parentElement,addedNodes:[details],removedNodes:[]});
    refreshTimers(e);
}

async function verifyPendingCalculation() {
    for (const completed of [false,true]) {
        const e = await openWithVerifiedOffer();
        const original = e.requests.at(-1);
        const finish = async () => {
            e.resolve(original,false,{scheme_key:'B|12',kop_code:'B'}); await flush();
        };
        check(appliedOffer(e) === 'B|12' && matchesRequest(original,'calculate','42/22/1','B|12'), 'one calculation starts with the applied offer');
        if (completed) { await finish(); e.checkIds(2,6); }
        insertVerifiedDetails(e);
        check(e.requests.length === 2 && !original.options.signal.aborted, 'equivalent details keep the original request');
        if (!completed) await finish();
        check(!e.get('[data-unipayment-apply]').disabled && !e.get('[data-unipayment-secondary]').disabled, 'the original response enables both actions');
        if (completed) check(e.rows[5].box.checked && !e.rows[5].hidden, 'equivalent details preserve the completed consent state');
    }

    const changed = await openWithVerifiedOffer();
    const old = changed.requests.at(-1);
    changed.first.value = '25';
    insertVerifiedDetails(changed);
    const current = changed.requests.at(-1);
    check(old.options.signal.aborted && changed.requests.length === 3 &&
        matchesRequest(current,'calculate','42/22/1','B|12') && current.options.body.get('first_installment') === '25',
        'refresh recalculates when a financial input differs for the same product selection');

    for (const failure of ['http','abort']) {
        const e = await openWithVerifiedOffer();
        const failed = e.requests.at(-1);
        if (failure === 'abort') failed.reject(new DOMException('Aborted','AbortError'));
        else failed.resolve({success:false},false);
        await flush(); refreshTimers(e);
        check(e.requests.length === 2, 'failure does not start a retry loop');
        insertVerifiedDetails(e);
        check(e.requests.length === 3 && matchesRequest(e.requests.at(-1),'calculate','42/22/1','B|12'),
            'an observer refresh can recover after '+failure+' with the same payload');
    }
}

(async () => {
    for (const mode of ['product','cart','checkout']) {
        const e=environment(mode);
        if (mode !== 'checkout') { e.click('[data-unipayment-offer]', {'data-unipayment-offer':'standard'}); e.resolve(e.requests.at(-1),false); await flush(); }
        check(e.rows[2].hidden && e.rows[3].box.disabled && !e.rows[4].hidden, mode+' nonzero visibility');
        e.checkIds(2,6);
        e.scheme(true);
        check(e.rows.every(r=>r.hidden) && e.rows[1].box.checked && !e.rows[5].box.checked,mode+' immediate scheme invalidation/scoped reset/all retention');
        e.flushTimers(); e.resolve(e.requests.at(-1),true); await flush();
        check(!e.rows[2].hidden && e.rows[4].hidden && !e.rows[3].box.checked,mode+' zero needs reacceptance');
        e.checkIds(2,4);
        e.scheme(false); e.flushTimers(); const old=e.requests.at(-1);
        e.scheme(true); e.flushTimers(); const current=e.requests.at(-1);
        e.resolve(current,true); await flush(); e.resolve(old,false); await flush();
        check(!e.rows[2].hidden && e.rows[4].hidden,mode+' old success ignored');
        e.checkIds(2,4);
        e.first.value='10'; e.first.emit('input');
        check(e.rows[3].box.checked && e.rows[3].box.disabled && e.rows.every(r=>r.hidden),mode+' first installment retains checked while pending');
        e.flushTimers(); e.resolve(e.requests.at(-1),true); await flush();
        check(e.rows[3].box.checked && !e.rows[3].box.disabled,mode+' same scheme restores acceptance');
        // A previously valid in-flight response arriving during the debounce must stay stale.
        e.first.emit('change'); const inFlight=e.requests.at(-1);
        e.first.value='20'; e.first.emit('input'); e.resolve(inFlight,true); await flush();
        check(e.rows.every(r=>r.hidden),mode+' input invalidates before debounce');
        e.flushTimers(); e.requests.at(-1).reject(new Error('network')); await flush();
        check(e.rows.every(r=>r.hidden) && e.rows[1].box.disabled,mode+' current error stays blocked');
        e.first.emit('change'); const afterError=e.requests.at(-1); e.resolve(afterError,true); await flush(); e.checkIds(2,4);
        if (mode==='cart') {
            e.event('updateCart');
            check(e.rows.every(r=>r.hidden),'cart update invalidates before refresh');
            e.event('updatedCart'); const oldRefresh=e.requests.at(-1);
            e.event('updatedCart'); const newRefresh=e.requests.at(-1);
            newRefresh.resolve({success:true,calculator:e.config}); await flush();
            e.resolve(e.requests.at(-1),false); await flush();
            oldRefresh.resolve({success:true,calculator:null}); await flush();
            check(!e.modal.hidden && !e.rows[4].hidden,'cart late refresh cannot close newer popup');
            e.scheme(true); e.resolve(e.requests.at(-1),true); await flush(); e.checkIds(2,4);
        }
        if (mode==='checkout') {
            e.event('updateCart');
            check(e.form.emit('submit:jquery').defaultPrevented,'jQuery submit blocked before cart refresh resolves');
            e.event('updatedCart'); e.resolve(e.requests.at(-1),true); await flush();
            check(e.rows[3].box.checked && !e.rows[3].box.disabled,'checkout refresh revalidates same form');
            e.first.emit('change'); const previous=e.requests.at(-1);
            e.scheme(false); e.flushTimers(); const latest=e.requests.at(-1);
            e.resolve(latest,false,{cart_snapshot:'fresh-signature'}); await flush(); previous.reject(new Error('late')); await flush();
            check(!e.rows[4].hidden && e.rows[2].hidden,'checkout old error ignored');
            check(e.get('input[name="unipayment_cart_snapshot"]').value==='fresh-signature','checkout refresh uses the current signed cart snapshot');
            e.checkIds(2,6);
            check(!e.form.emit('submit:jquery').defaultPrevented,'actual jQuery submit succeeds with applicable acceptance');
        } else {
            e.click('[data-unipayment-submit]'); await flush();
            const applied=e.requests.at(-1);
            if (mode==='product') { e.resolve(applied,true); await flush(); }
            const body=e.requests.at(-1).options.body;
            check(JSON.stringify(body.getAll('unipayment_consent[]'))==='["2","4"]',mode+' sends only applicable accepted IDs');
        }
        e.first.emit('change'); const detached=e.requests.at(-1);
        e.detach(); e.resolve(detached,true); await flush();
        if (mode==='checkout') check(e.rows.every(r=>r.hidden),'removed checkout disposed');
        else check(e.get('[data-unipayment-apply]').disabled,'removed popup ignores response');
    }
    const e=environment('product');
    e.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
    const closed=e.requests.at(-1); e.click('[data-unipayment-close]'); e.resolve(closed,false); await flush();
    check(e.modal.hidden && e.rows.every(r=>r.hidden),'closed popup ignores in-flight calculation');
    const c=environment('checkout'); c.checkIds(2,6); c.first.emit('change');
    c.resolve(c.requests.at(-1),false,{consent_context:{...contexts(false),required_ids:[2,6,99]}}); await flush();
    check(c.rows.every(r=>r.hidden) && c.form.emit('submit:jquery').defaultPrevented,'new mandatory ID absent from HTML blocks');
    const helper=c.sandbox.createUnipaymentShopConsents(c.root);
    let generation=helper.begin('same'); helper.apply(generation,contexts(false)); c.checkIds(2,6);
    generation=helper.begin('same'); helper.apply(generation,{...contexts(false),scheme_identity:'changed KOP'});
    check(c.rows[1].box.checked && !c.rows[5].box.checked,'server identity change clears scoped acceptance under same select key');
    generation=helper.begin('same');
    check(!helper.apply(generation,{...contexts(false),valid:false}) && !helper.ready() && helper.acceptedIds().length===0,'unknown context blocks serialization');
    generation=helper.begin('same'); helper.destroy();
    check(!helper.apply(generation,contexts(false)),'destroyed helper ignores late context');
    const emptyRoot=new Node();
    const emptyHelper=c.sandbox.createUnipaymentShopConsents(emptyRoot);
    generation=emptyHelper.begin('empty');
    check(emptyHelper.apply(generation,{valid:true,interest_state:'unknown',scheme_identity:'empty',applicable_ids:[],required_ids:[]}) && emptyHelper.ready(),'empty consent list adds no requirement');
    const replacement=environment('checkout');
    check(!replacement.rows[1].box.checked && !replacement.rows[5].box.checked,'new checkout form has no transferred acceptance');
    // H1: an immediate refresh calculation must cancel the first-installment debounce.
    for (const mode of ['product', 'cart']) {
        const race=environment(mode);
        const redirects=[];
        race.sandbox.location.assign=url=>redirects.push(url);
        race.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        race.resolve(race.requests.at(-1),false); await flush();
        race.first.value='20'; race.first.emit('input');
        check(race.timers.size===1,mode+' H1 starts with pending debounce');
        const obsoleteTimer=[...race.timers.values()][0];
        if (mode==='cart') {
            race.event('updatedCart');
            race.requests.at(-1).resolve({success:true,calculator:race.config}); await flush();
        } else race.root.unipaymentUpdate(race.config);
        race.resolve(race.requests.at(-1),false); await flush();
        check(race.timers.size===0,mode+' H1 immediate calculation cancels old debounce');
        race.checkIds(2,6);
        race.click('[data-unipayment-apply]');
        if (mode==='product') { race.resolve(race.requests.at(-1),false); await flush(); }
        race.click('[data-unipayment-submit]'); race.click('[data-unipayment-submit]'); await flush();
        const apply=race.requests.at(-1);
        check(apply.options.body.get('popup_action')==='apply',mode+' H1 sends apply');
        const count=race.requests.length;
        // Also force a callback already dequeued before clearTimeout could cancel it.
        obsoleteTimer(); race.flushTimers(); await flush();
        check(race.requests.length===count,mode+' H1 obsolete timer starts no calculate');
        // Real context changes during apply invalidate previews, not the sent operation.
        race.first.value='50'; race.first.emit('input'); race.first.emit('change');
        race.scheme(true);
        race.root.unipaymentInvalidatePopup();
        race.root.unipaymentUpdate(null);
        race.root.unipaymentRefresh();
        race.click('[data-unipayment-submit]');
        race.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        race.flushTimers(); await flush();
        check(race.requests.length===count,mode+' H1 context change starts neither preview nor duplicate apply');
        check(!race.modal.hidden && race.root.hidden!==true,mode+' active apply keeps processing UI visible');
        if (mode==='cart') {
            race.event('updateCart'); race.event('updatedCart');
            race.requests.at(-1).resolve({success:true,calculator:null}); await flush();
            check(race.requests.length===count+1,'cart refresh during apply starts no follow-up calculate');
            check(!race.modal.hidden && race.root.hidden!==true,'cart refresh response preserves active submission UI');
        }
        check(apply.options.body.get('kop_code')==='STD',mode+' sent operation retains original selection');
        apply.resolve({success:true,step:'order_created',order:{id_order:123},redirect_url:'https://example.test/confirmation'}); await flush();
        check(race.root.unipaymentOrderResult?.id_order===123,mode+' H1 successful apply result retained');
        check(redirects.length===1,mode+' H1 successful apply redirects exactly once');
        check(race.requests.filter(r=>r.options?.body?.get('popup_action')==='apply').length===1,mode+' H1 only one apply');
    }
    // An operation result remains authoritative after context changes, without an automatic retry.
    for (const outcome of ['confirmation','error','destroyed']) {
        const e=environment('cart'); const redirects=[];
        e.sandbox.location.assign=url=>redirects.push(url);
        e.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        e.resolve(e.requests.at(-1),false); await flush(); e.checkIds(2,6);
        e.click('[data-unipayment-apply]'); e.click('[data-unipayment-submit]'); await flush();
        const sent=e.requests.at(-1);
        e.first.value='40'; e.first.emit('input');
        if (outcome==='destroyed') e.detach();
        if (outcome==='error') sent.resolve({success:false,message:'Rejected',errors:{consents:'Rejected'}},false);
        else sent.resolve({success:true,step:'order_created',order:{id_order:456},...(outcome==='destroyed'?{redirect_url:'https://example.test/old'}:{})});
        await flush();
        if (outcome==='confirmation') check(e.root.unipaymentOrderResult?.id_order===456 && !e.get('[data-unipayment-step="3"]').hidden,'changed context still receives native popup confirmation');
        if (outcome==='error') {
            check(!e.get('[data-unipayment-step="2"]').hidden && e.get('[data-unipayment-submit]').disabled,'changed context plus apply error requires fresh review');
            check(e.get('[data-unipayment-submit-error]').textContent.includes('Обновете'),'changed context error requests page refresh');
        }
        if (outcome==='destroyed') check(!e.root.unipaymentOrderResult && redirects.length===0,'destroyed popup ignores old apply success');
        check(e.requests.filter(r=>r.options?.body?.get('popup_action')==='apply').length===1,outcome+' never resubmits automatically');
    }
    // Selection changes before the request is sent must still cancel submission.
    for (const mode of ['product','cart']) {
        const pending=environment(mode);
        pending.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        pending.resolve(pending.requests.at(-1),false); await flush(); pending.checkIds(2,6);
        pending.click('[data-unipayment-apply]');
        if (mode==='product') { pending.resolve(pending.requests.at(-1),false); await flush(); }
        pending.click('[data-unipayment-submit]');
        pending.first.value='30'; pending.first.emit('input'); await flush();
        check(!pending.requests.some(r=>r.options?.body?.get('popup_action')==='apply'),mode+' changed selection before fetch cancels apply');
        pending.click('[data-unipayment-close]'); pending.flushTimers();
        check(pending.modal.hidden,mode+' cancelled preparation permits close');
    }
    console.log('OK consent frontend '+assertions+' assertions');
    const beforeProduct = assertions;
    const failures = [];
    for (const [name, run] of Object.entries(productRegressions)) {
        try { await run(); }
        catch (error) { failures.push(name+': '+error.message); }
    }
    assert.deepEqual(failures, [], failures.join('\n'));
    console.log('OK product lifecycle '+Object.keys(productRegressions).length+' scenarios, '+(assertions-beforeProduct)+' assertions');
    const beforeObserver = assertions;
    await verifyProductObserver();
    console.log('OK product details observer '+(assertions-beforeObserver)+' assertions');
    const beforePending = assertions;
    await verifyPendingCalculation();
    console.log('OK product pending calculation '+(assertions-beforePending)+' assertions');
})().catch(error=>{console.error(error);process.exitCode=1;});
