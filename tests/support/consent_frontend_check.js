'use strict';
// Execute production functions in isolated VM contexts; no packages, sockets or browser globals.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const popup = fs.readFileSync(root + '/upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.js', 'utf8');
const checkout = fs.readFileSync(root + '/upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.js', 'utf8');
let passes = 0;
function check(ok, name) { assert.ok(ok, name); passes++; console.log('PASS ' + name); }
// Functions are taken verbatim from production, never duplicated as test implementations.
function declaration(source, name) {
  const start = source.indexOf('function ' + name + '(');
  assert.ok(start >= 0, name);
  let end = source.indexOf('\n    function ', start + 10);
  // common helper ends at two-space indent; functions inside it are intentionally included.
  if (name === 'consentForm') end = source.indexOf('\n  function initRoot', start);
  const tail = source.indexOf('\n    $root.', start);
  if (tail >= 0 && (end < 0 || tail < end)) end = tail;
  assert.ok(end > start, name + ' end');
  return source.slice(start, end).trim();
}
function collection(nodes) {
  return {
    length: nodes.length,
    each(fn) { nodes.forEach((n, i) => fn.call(n, i, n)); return this; },
    attr(name, value) { if (value === undefined) return nodes[0] && nodes[0][name]; nodes.forEach(n => n[name] = value); return this; },
    prop(name, value) { if (value === undefined) return nodes[0] && nodes[0][name]; nodes.forEach(n => n[name] = value); return this; },
    is(selector) { return selector === ':checked' && nodes.some(n => n.checked); },
    find() { return collection(nodes.flatMap(n => n.box ? [n.box] : [])); }
  };
}
function form() {
  const rows = [1, 2, 3].map(id => ({'data-mtuc-consent-id': String(id), 'data-mtuc-consent-scope': id === 1 ? 'all' : 'zero_interest', box: id === 3 ? null : {value: id, checked: false, disabled: false}}));
  const hash = {'data-mtuc-consent-config': 'config'};
  const host = {find(selector) {
    if (selector === '[data-mtuc-consents]') return collection([hash]);
    if (selector === '[data-mtuc-consent-id]' || selector === '[data-mtuc-consent-scope]') return collection(rows);
    const value = /value="(\d+)"/.exec(selector);
    return collection(rows.filter(r => r.box && (!value || r.box.value === +value[1])).map(r => r.box));
  }};
  const $ = n => collection([n]);
  $.each = (xs, fn) => xs.forEach((x, i) => fn(i, x)); $.inArray = (x, xs) => xs.indexOf(x);
  return {rows, host, $};
}
for (const [label, source] of [['popup', popup], ['checkout', checkout]]) {
  const f = form(); const sandbox = {console, $: f.$}; vm.createContext(sandbox);
  vm.runInContext(declaration(source, 'consentForm') + ';globalThis.policy = consentForm;', sandbox);
  const p = label === 'popup' ? sandbox.policy(f.$, f.host) : sandbox.policy(f.host);
  const ctx = {valid: true, kop_code: 'A', months: 12, config_hash: 'config', applicable_ids: [1, 2, 3], mandatory_ids: [1, 2]};
  check(p.apply(ctx, 'standard|A|12') && !p.accepted(), label + ' initial required consent gate');
  f.rows[0].box.checked = f.rows[1].box.checked = true;
  check(p.accepted(), label + ' checked IDs authorize current form');
  p.invalidate('standard|A|12'); check(!p.accepted(), label + ' first installment pending blocks');
  p.apply(ctx, 'standard|A|12'); check(p.accepted(), label + ' same selection successful recalculation preserves acceptance');
  p.invalidate('standard|A|24');
  check(f.rows[0].box.checked && !f.rows[1].box.checked, label + ' scope reset on months change preserves all');
  p.apply({...ctx, applicable_ids: [1], mandatory_ids: [1]}, 'standard|A|24');
  check(f.rows[1].hidden && f.rows[1].box.disabled && !f.rows[1].box.checked, label + ' hidden checkbox disabled and cleared');
  check(p.apply({...ctx, applicable_ids: [3], mandatory_ids: []}, 'informational') && p.accepted(), label + ' informational-only no checkbox gate');
  check(p.apply({...ctx, applicable_ids: [], mandatory_ids: []}, 'empty') && p.accepted(), label + ' empty no checkbox gate');
  check(!p.apply({...ctx, mandatory_ids: [99]}, 'stale') && !p.accepted(), label + ' missing mandatory HTML requires refresh');
  check(!p.apply({...ctx, config_hash: 'new'}, 'changed'), label + ' changed definitions require refresh');
  const second = label === 'popup' ? sandbox.policy(f.$, form().host) : sandbox.policy(form().host);
  check(!second.accepted(), label + ' replacement form receives no acceptance state');
}
// Popup H1/stale callbacks: actual scheduler and request callback code, controllable timers/ports.
const timers = new Map(); let timerId = 0; const calls = []; const displays = [];
const scope = {console, window: {clearTimeout(id) { timers.delete(id); }, setTimeout(fn) { timers.set(++timerId, fn); return timerId; }},
  firstInstallmentTimer: null, calcTimer: null, sequence: 0, pendingCalculation: true, selectedSchemeKey: 'standard|A|12',
  entryPoint: 'cart', bootstrap: {}, attached: true, policyApplies: 0,
  $root: {attr(name) { return name === 'data-route-recalculate' ? 'recalculate' : ''; }},
  $modal: {attr() { return false; }, find() { return {val() { return '10'; }}; }},
  isTerminalSubmitLocked() { return false; },
  mounted() { return scope.attached; }, invalidateConsent() { scope.pendingCalculation = true; scope.sequence++; },
  setProcessing() {}, setPopupError() {}, t() { return ''; }, updateSubmitState() {},
  fillDisplays(calc) { displays.push(calc.key); },
  consent: {apply(_ctx, key) { scope.policyApplies++; return key === scope.selectedSchemeKey; }, ready() { return !scope.pendingCalculation; }},
  postJson(_route, data, cb) { calls.push({data, cb}); }};
vm.createContext(scope);
vm.runInContext(declaration(popup, 'scheduleRecalculate') + '\n' + declaration(popup, 'runRecalculate'), scope);
scope.scheduleRecalculate(false); check(timers.size === 1 && scope.pendingCalculation, 'popup pending debounce invalidates immediately');
scope.scheduleRecalculate(true); check(timers.size === 0 && calls.length === 1, 'popup immediate calculation cancels obsolete first timer');
const applyStart = popup.indexOf('      apply: function () {');
const applyEnd = popup.indexOf('\n      recalculate:', applyStart);
vm.runInContext('globalThis.applyPopup = ' + popup.slice(applyStart, applyEnd).trim().replace(/^apply: /, '').replace(/,$/, '') + ';', scope);
scope.isTerminalSubmitLocked = () => false; scope.setStep = step => {scope.step = step;};
scope.applyPopup(); check(scope.step === undefined, 'Apply blocked while calculation pending');
scope.scheduleRecalculate(true); check(calls.length === 2, 'new generation starts');
calls[0].cb(null, {success:true, sequence:calls[0].data.sequence, calculation:{key:'standard|A|12',consent_context:{}}});
check(displays.length === 0 && scope.pendingCalculation, 'old echoed sequence cannot restore stale popup context');
calls[1].cb(null, {success:true, sequence:calls[1].data.sequence, calculation:{key:'standard|A|12',consent_context:{}}});
check(displays.length === 1 && !scope.pendingCalculation, 'current success establishes popup context');
scope.applyPopup(); check(scope.step === 2 && timers.size === 0, 'Apply succeeds once current context is ready with no obsolete timer');
scope.scheduleRecalculate(true); scope.attached = false;
calls[2].cb(null, {success:true, calculation:{key:'standard|A|12',consent_context:{}}});
check(displays.length === 1, 'detached modal callback cannot restore context');
scope.attached = true; scope.scheduleRecalculate(true); calls[3].cb(true, null);
check(scope.pendingCalculation, 'calculation error never restores eligibility');
// Checkout production scheduler: scheme change cancels first timer, latest success and error guards.
const requests = []; const checkoutScope = {console, firstTimer: 99, sequence:0, calcBusy:false, selectedSchemeKey:'standard|A|12', attached:true,
  clearTimeout(id) { checkoutScope.cleared = id; },
  state:{recalculate_url:'calc',csrf_token:'csrf',i18n:{}}, firstInput() {return {value:'10'};},
  invalidateConsent() {checkoutScope.sequence++; checkoutScope.calcBusy=true; checkoutScope.lastCalculation=null;},
  mounted() {return checkoutScope.attached;}, updateConfirmState() {},
  $root:{find() {return {text() {}};}},
  consent:{apply(){return true;}}, renderCalculation(c) {checkoutScope.lastCalculation=c;},
  $:{ajax(options){requests.push(options);}}};
vm.createContext(checkoutScope); vm.runInContext(declaration(checkout, 'recalculate'), checkoutScope);
checkoutScope.recalculate({key:'standard|A|12'},0);
check(checkoutScope.firstTimer === null && checkoutScope.cleared === 99, 'checkout scheme recalc cancels pending first timer');
checkoutScope.recalculate({key:'standard|A|12'},0); requests[0].success({success:true,calculation:{key:'standard|A|12'}});
check(!checkoutScope.lastCalculation, 'checkout stale success ignored');
requests[1].success({success:true,calculation:{key:'standard|A|12'}});requests[1].complete();
check(!!checkoutScope.lastCalculation && !checkoutScope.calcBusy, 'checkout latest successful context');
checkoutScope.recalculate({key:'standard|A|12'},0);requests[2].error();requests[2].complete();
check(!checkoutScope.lastCalculation, 'checkout failed recalculation discards old success');
checkoutScope.attached=false;requests[2].success({success:true,calculation:{key:'standard|A|12'}});
check(!checkoutScope.lastCalculation, 'checkout detached callback ignored');
// Direct confirmation handlers independently enforce readiness and double-submit guards.
const confirms = [];
const confirmScope = {console, confirmBusy:false, calcBusy:true, redirectTerminal:false, lastCalculation:{}, window:{},
  mounted(){return true;}, selectedScheme(){return {key:'standard|A|12'};},
  consent:{ready(){return true;},hash(){return 'config';}}, consentsAccepted(){return true;},process2Ok(){return true;},
  setProcessing(){},state:{confirm_url:'confirm',csrf_token:'csrf',i18n:{}},
  $root:{find(){return {text(){},val(){return '';}};}}, resolveSubmitFirstInstallment(){return 0;},collectConsentIds(){return [1];},
  $:{each(xs,fn){xs.forEach((x,i)=>fn(i,x));},ajax(options){confirms.push(options);}}};
vm.createContext(confirmScope);vm.runInContext(declaration(checkout,'confirmOrder'),confirmScope);
confirmScope.confirmOrder();check(confirms.length===0,'direct checkout confirm refuses pending context');
confirmScope.calcBusy=false;confirmScope.confirmOrder();confirmScope.confirmOrder();
check(confirms.length===1,'checkout duplicate click emits only one confirmation');
check(confirms[0].data.consent_config_hash==='config' && confirms[0].data.consent[0]===1,'checkout transports acceptance and context hash only');
const popupRequests=[];
const formPort={length:1,attr(){return '1';},serializeArray(){return [{name:'consent[]',value:'1'}];}};
const popupScope={console,terminalSubmitInFlight:false,ready:false,entryPoint:'cart',selectedSchemeKey:'standard|A|12',bootstrap:{},
  isTerminalSubmitLocked(){return popupScope.terminalSubmitInFlight;},updateSubmitState(){return popupScope.ready;},clearAllFieldErrors(){},
  mapClientFieldErrors(){return {};},getStep2FieldErrors(){return {};},showFieldErrors(){},focusFirstInvalidField(){},setProcessing(){},t(){return '';},
  consent:{hash(){return 'config';}},$root:{attr(){return ''; }},$modal:{find(selector){return selector==='[data-mtuc-form]'?formPort:{text(){},val(){return '0';}};}},
  $:{each(xs,fn){xs.forEach((x,i)=>fn(i,x));},isArray:Array.isArray},postJson(route,data,callback){popupRequests.push({route,data,callback});}};
const submitStart=popup.indexOf('      submit: function () {');const submitEnd=popup.indexOf('\n      scheduleCalculate:',submitStart);
vm.createContext(popupScope);vm.runInContext('globalThis.submitPopup = '+popup.slice(submitStart,submitEnd).trim().replace(/^submit: /,'').replace(/,$/,'')+';',popupScope);
popupScope.submitPopup();check(popupRequests.length===0,'direct popup submit refuses pending/error readiness');
popupScope.ready=true;popupScope.submitPopup();popupScope.submitPopup();
check(popupRequests.length===1,'popup duplicate click emits only one submission');
check(popupRequests[0].data.consent_config_hash==='config' && popupRequests[0].data.consent[0]==='1','popup form acceptance transport is independent of checkout');
console.log('CONSENT FRONTEND: ' + passes + ' PASS (production functions; isolated DOM/timer ports)');
