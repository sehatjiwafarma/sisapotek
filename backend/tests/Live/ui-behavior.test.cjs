// Lightweight DOM doubles exercise interaction contracts without installing dependencies.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const vm = require('node:vm');

class UIEvent {
  constructor(type, options = {}) { this.type = type; Object.assign(this, options); this.defaultPrevented = false; }
  preventDefault() { this.defaultPrevented = true; }
  stopImmediatePropagation() { this.stopped = true; }
}
class Element {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase(); this.value = ''; this.textContent = ''; this.children = []; this.dataset = {}; this.attributes = {}; this.listeners = {}; this.hidden = false; this.disabled = false; this.isConnected = true; this.style = {};
    const classes = new Set(); this.classList = {add: c => classes.add(c), contains: c => classes.has(c), toggle: (c,on) => on ? classes.add(c) : classes.delete(c)};
  }
  addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
  set method(value) { this.setAttribute('method',value); }
  get method() { return this.getAttribute('method') || 'get'; }
  dispatchEvent(event) { event.target ||= this; for (const fn of this.listeners[event.type] || []) { fn(event); if (event.stopped) break; } return !event.defaultPrevented; }
  setAttribute(key,value) { this.attributes[key] = String(value); }
  getAttribute(key) { return this.attributes[key] ?? null; }
  hasAttribute(key) { return key in this.attributes; }
  removeAttribute(key) { delete this.attributes[key]; }
  setCustomValidity(message) { this.validationMessage = message; }
  append(...nodes) { this.children.push(...nodes); }
  after(node) { this.nextElementSibling = node; }
  replaceChildren(...nodes) { this.children = nodes; }
  closest() { return null; }
  focus() { this.focused = true; }
  setSelectionRange() {}
  scrollIntoView() {}
  showPopover() { this.open = true; }
  hidePopover() { this.open = false; }
  contains(node) { return this.children.includes(node); }
  getBoundingClientRect() { return {left:20,top:20,bottom:60,width:300}; }
}
function environment() {
  const document = new Element(); document.body = new Element('body'); document.querySelector = () => null; document.querySelectorAll = () => [];
  document.createElement = tag => new Element(tag); document.createTextNode = text => ({textContent:text});
  const window = new Element(); const timers = new Map(); let sequence = 0;
  const context = {document, window, HTMLElement:Element, HTMLFormElement:Element, Event:UIEvent, URL, location:{pathname:'/reports',href:'http://localhost/reports',search:''}, sessionStorage:{setItem(){},getItem(){return null;},removeItem(){}},setTimeout:fn=>{timers.set(++sequence,fn);return sequence;},clearTimeout:id=>timers.delete(id),queueMicrotask:fn=>fn(),innerWidth:1000,innerHeight:800};
  return {document,window,context,flush(){const work=[...timers.values()];timers.clear();work.forEach(fn=>fn());},run(file){vm.runInNewContext(readFileSync(resolve(__dirname,'../../public/js',file),'utf8'),context);}};
}
function filters(range = false) {
  const env = environment(), form = new Element('form'), search = new Element('input'), button = new Element('button'), dropdown = new Element('select'), from = new Element('input'), to = new Element('input');
  search.name = 'q'; from.value = '2026-11-01'; to.value = '2026-11-11'; form.dataset.today = '2026-11-11';
  const presets = ['today','week','month'].map(key => { const node=new Element('button'); node.dataset.datePreset=key; return node; });
  form.querySelector = selector => selector.includes('input[type="search"]') ? search : selector.includes('from') && range ? from : selector.includes('to') && range ? to : null;
  form.querySelectorAll = selector => selector === '[data-date-preset]' ? presets : selector === 'select' ? [dropdown] : selector.includes('type="date"') ? (range ? [from,to] : []) : selector.includes('button') ? [button] : [];
  form.reportValidity = form.checkValidity = () => !to.validationMessage;
  let submissions = 0; form.requestSubmit = () => { if(form.reportValidity() && form.dispatchEvent(new UIEvent('submit'))) submissions++; };
  env.document.querySelectorAll = () => [form]; env.run('filters.js');
  return {...env,form,search,button,dropdown,from,to,presets,count:()=>submissions};
}
test('legacy keyword input debounces and keeps Reset/submit fallback coherent', () => {
  const ui = filters(); assert.equal(ui.button.hidden,true);
  ui.search.value = 'paracetamol'; ui.search.dispatchEvent(new UIEvent('input')); assert.equal(ui.count(),0); ui.flush(); assert.equal(ui.count(),1);
  ui.window.dispatchEvent(new UIEvent('pageshow')); ui.search.value = ''; ui.search.dispatchEvent(new UIEvent('input')); ui.flush(); assert.equal(ui.count(),2);
});
test('IME composition waits until composition ends and debounce cancels previous timer', () => {
  const ui = filters(); ui.search.dispatchEvent(new UIEvent('compositionstart')); ui.search.dispatchEvent(new UIEvent('input')); ui.flush(); assert.equal(ui.count(),0);
  ui.search.dispatchEvent(new UIEvent('compositionend')); ui.search.dispatchEvent(new UIEvent('input')); ui.flush(); assert.equal(ui.count(),1);
});
test('date range waits for explicit apply, rejects reversed range, then accepts correction', () => {
  const ui = filters(true); assert.equal(ui.button.hidden,false);
  ui.from.value = '2026-12-01'; ui.from.dispatchEvent(new UIEvent('change')); assert.equal(ui.count(),0); assert.ok(ui.to.validationMessage);
  ui.form.requestSubmit(); assert.equal(ui.count(),0);
  ui.to.value = '2026-12-31'; ui.to.dispatchEvent(new UIEvent('change')); assert.equal(ui.count(),0); ui.form.requestSubmit(); assert.equal(ui.count(),1);
});
test('a cancelled filter submit remains retryable (opname unsaved guard)', () => {
  const ui = filters(); ui.form.listeners.submit.unshift(event => event.preventDefault()); ui.search.dispatchEvent(new UIEvent('input')); ui.flush(); assert.equal(ui.count(),0);
  ui.form.listeners.submit.shift(); ui.search.dispatchEvent(new UIEvent('input')); ui.flush(); assert.equal(ui.count(),1);
});
test('quick date presets use the pharmacy date and submit a complete range once', () => {
  for (const [index,start] of [[0,'2026-11-11'],[1,'2026-11-05'],[2,'2026-11-01']]) {
    const ui=filters(true); ui.presets[index].dispatchEvent(new UIEvent('click'));
    assert.equal(ui.from.value,start); assert.equal(ui.to.value,'2026-11-11'); assert.equal(ui.count(),1);
  }
});
test('filter keeps its native submit button when enhancement is unsupported', () => {
  const env=environment(), form=new Element('form'), button=new Element('button'); env.document.querySelectorAll=()=>[form];
  form.querySelectorAll=()=>[button]; env.run('filters.js'); assert.equal(button.hidden,false);
});
function payment() {
  const env = environment(), form = new Element('form'); form.dataset.total = '1234.56';
  const method = new Element('select'), amount = new Element('input'), reference = new Element('input'), change = new Element(), shortage = new Element(), note = new Element(); method.value='cash'; amount.value='2000.00';
  form.querySelector = selector => ({'[name="payment_method"]':method,'[name="amount_received"]':amount,'[name="payment_reference"]':reference,'[data-payment-change]':change,'[data-payment-shortfall]':shortage,'[data-payment-reference-note]':note}[selector]);
  env.document.querySelectorAll = () => [form]; env.run('sale-payment.js'); return {...env,form,method,amount,reference,change,shortage};
}
test('payment calculates exact cents and exposes shortfall before submit', () => {
  const ui = payment(); assert.equal(ui.change.textContent,'Rp 765,44'); assert.equal(ui.shortage.hidden,true);
  ui.amount.value='1000.01'; ui.amount.dispatchEvent(new UIEvent('input')); assert.equal(ui.shortage.hidden,false); assert.match(ui.shortage.textContent,/234,55/); assert.ok(ui.amount.validationMessage);
  ui.amount.value='1234.56'; ui.amount.dispatchEvent(new UIEvent('input')); assert.equal(ui.amount.validationMessage,''); assert.equal(ui.change.textContent,'Rp 0,00');
});
test('non-cash fixes total and requires reference; switching back restores entered cash', () => {
  const ui = payment(); ui.method.value='transfer'; ui.method.dispatchEvent(new UIEvent('change'));
  assert.equal(ui.amount.value,'1234.56'); assert.equal(ui.amount.readOnly,true); assert.equal(ui.reference.required,true);
  ui.method.value='cash'; ui.method.dispatchEvent(new UIEvent('change')); assert.equal(ui.amount.value,'2000.00'); assert.equal(ui.amount.readOnly,false); assert.equal(ui.reference.required,false);
});
function searchable(required) {
  const env = environment(), select = new Element('select'); select.required = required;
  select.options = [{value:'',text:'Semua produk',disabled:false},{value:'A',text:'Produk A',disabled:false},{value:'B',text:'Produk B',disabled:true}];
  select.value='A'; Object.defineProperty(select,'selectedOptions',{get:()=>select.options.filter(option=>option.value===select.value)});
  env.document.querySelectorAll = () => [select]; env.run('search-select.js'); select.nextElementSibling.dispatchEvent(new UIEvent('click'));
  const panel = env.document.body.children[0]; return {select,list:panel.children[1],search:panel.children[0]};
}
test('optional searchable dropdown can return to All products and excludes disabled options', () => {
  const ui = searchable(false); assert.deepEqual(ui.list.children.map(node=>node.textContent),['Semua produk','Produk A']);
  ui.list.children[0].dispatchEvent(new UIEvent('click')); assert.equal(ui.select.value,'');
});
test('required searchable dropdown does not offer empty placeholder as a valid choice', () => {
  const ui = searchable(true); assert.deepEqual(ui.list.children.map(node=>node.textContent),['Produk A']);
});
test('ArrowUp from a newly opened dropdown highlights the last available option', () => {
  const ui = searchable(false); ui.search.dispatchEvent(new UIEvent('keydown',{key:'ArrowUp'}));
  assert.equal(ui.search.getAttribute('aria-activedescendant'),ui.list.children.at(-1).id);
});
test('unsupported popover browsers retain a usable native dropdown', () => {
  const env = environment(), select = new Element('select'); env.document.querySelectorAll=()=>[select];
  const method=Element.prototype.showPopover;
  try { delete Element.prototype.showPopover; env.run('search-select.js'); assert.equal(select.nextElementSibling,undefined); assert.equal(select.classList.contains('search-choice-native'),false); }
  finally { Element.prototype.showPopover=method; }
});
test('unsaved form guard warns on unload, prevents duplicate submits and recovers on Back', async () => {
  const env = environment(), form = new Element('form'), button = new Element('button'); form.method='post'; button.textContent='Simpan'; form.querySelector=()=>null; form.querySelectorAll=()=>[button];
  env.run('forms.js'); env.window.AppForms.mark(form); assert.equal(env.window.AppForms.hasChanges(),true);
  const unload = new UIEvent('beforeunload'); env.window.dispatchEvent(unload); assert.equal(unload.defaultPrevented,true);
  env.document.dispatchEvent(new UIEvent('submit',{target:form,submitter:button})); assert.equal(env.window.AppForms.hasChanges(),false); assert.equal(form.getAttribute('aria-busy'),'true');
  const repeat = new UIEvent('submit',{target:form,submitter:button}); env.document.dispatchEvent(repeat); assert.equal(repeat.defaultPrevented,true);
  env.flush(); assert.equal(button.disabled,true); env.window.dispatchEvent(new UIEvent('pageshow')); assert.equal(button.disabled,false); assert.equal(button.textContent,'Simpan'); assert.equal(env.window.AppForms.hasChanges(),true);
});
test('invalid or confirmation-cancelled submit does not clear dirty state or mark busy', () => {
  const env=environment(), form=new Element('form'); form.method='post'; form.querySelector=()=>null; env.run('forms.js'); env.window.AppForms.mark(form);
  const event=new UIEvent('submit',{target:form}); event.preventDefault(); env.document.dispatchEvent(event); assert.equal(form.getAttribute('aria-busy'),null); assert.equal(env.window.AppForms.hasChanges(),true);
});
test('in-app navigation asks before discarding changes; cancel retains the draft', async () => {
  const env=environment(), form=new Element('form'), link=new Element('a'); form.method='post'; form.querySelector=()=>null;
  link.href='http://localhost/sales'; link.closest=()=>link;
  let allow=false, destination=null; env.window.AppConfirm={open:async()=>allow}; env.context.location.assign=value=>{destination=value;};
  env.run('forms.js'); env.window.AppForms.mark(form);
  const click=()=>env.document.dispatchEvent(new UIEvent('click',{target:link,button:0}));
  click(); await new Promise(setImmediate); assert.equal(destination,null); assert.equal(env.window.AppForms.hasChanges(),true);
  allow=true; click(); await new Promise(setImmediate); assert.equal(destination,'http://localhost/sales'); assert.equal(env.window.AppForms.hasChanges(),false);
});
test('submitting another form preserves dirty changes when the user cancels', async () => {
  const env=environment(), draft=new Element('form'), action=new Element('form'); draft.method=action.method='post'; draft.querySelector=()=>null;
  env.window.AppConfirm={open:async()=>false}; env.run('forms.js'); env.window.AppForms.mark(draft);
  const submit=new UIEvent('submit',{target:action}); env.document.dispatchEvent(submit); await new Promise(setImmediate);
  assert.equal(submit.defaultPrevented,true); assert.equal(action.getAttribute('aria-busy'),null); assert.equal(env.window.AppForms.hasChanges(),true);
});
test('a payment field named method cannot shadow the form method used by dirty guards', () => {
  const env=environment(), form=new Element('form'); form.setAttribute('method','post'); form.querySelector=()=>null;
  Object.defineProperty(form,'method',{value:new Element('select')}); env.run('forms.js'); env.window.AppForms.mark(form);
  assert.equal(env.window.AppForms.hasChanges(),true);
});
