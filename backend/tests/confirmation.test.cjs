// Dependency-free interaction tests for the shared dialog controller.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element extends EventTarget {
  constructor() { super(); this.dataset = {}; this.isConnected = true; this.textContent = ''; }
  focus() { document.activeElement = this; }
}
class Form extends Element {
  hasAttribute(name) { return name === 'data-confirm'; }
  querySelector() { return null; }
  requestSubmit(submitter) {
    const event = new Event('submit', { cancelable: true });
    Object.defineProperties(event, { target: { value: this }, submitter: { value: submitter } });
    document.dispatchEvent(event);
    if (!event.defaultPrevented) this.submitted = submitter;
  }
}
const document = new EventTarget();
const nodes = Object.fromEntries(['#app-confirm-title', '#app-confirm-message', '[data-dialog-cancel]', '[data-dialog-accept]'].map(key => [key, new Element()]));
const dialog = new Element();
dialog.querySelector = key => nodes[key];
dialog.showModal = () => { dialog.open = true; };
dialog.close = () => { dialog.open = false; queueMicrotask(() => dialog.dispatchEvent(new Event('close'))); };
document.getElementById = () => dialog;
const window = {};
vm.runInNewContext(fs.readFileSync('public/js/confirmation.js', 'utf8'), { document, window, HTMLFormElement: Form });
const tick = () => new Promise(resolve => setImmediate(resolve));
const click = key => nodes[key].dispatchEvent(new Event('click'));
(async () => {
  const origin = new Element(); origin.focus();
  const first = window.AppConfirm.open({ message: '<img onerror=bad()>', variant: 'danger' });
  assert.equal(nodes['#app-confirm-message'].textContent, '<img onerror=bad()>');
  assert.equal(dialog.dataset.variant, 'danger');
  assert.equal(document.activeElement, nodes['[data-dialog-cancel]']);
  const second = window.AppConfirm.open({ message: 'Second' });
  dialog.dispatchEvent(new Event('cancel', { cancelable: true }));
  assert.equal(await first, false);
  assert.equal(nodes['#app-confirm-message'].textContent, 'Second');
  click('[data-dialog-accept]');
  assert.equal(await second, true);
  assert.equal(document.activeElement, origin);
  const form = new Form(); form.dataset.confirm = 'Remove?';
  const button = new Element(); button.form = form; button.classList = { contains: () => true }; button.textContent = 'Remove';
  form.requestSubmit(button);
  form.requestSubmit(button); // Repeated submission must not queue another confirmation.
  assert.equal(form.submitted, undefined);
  assert.equal(dialog.dataset.variant, 'danger');
  click('[data-dialog-cancel]'); await tick();
  assert.equal(form.submitted, undefined);
  assert.equal(dialog.open, false);
  form.requestSubmit(button);
  click('[data-dialog-accept]'); await tick();
  assert.equal(form.submitted, button); // Original submitter and its overrides survive.
  assert.equal(dialog.open, false);
  const removed = new Form(); removed.dataset.confirm = 'Continue?';
  removed.requestSubmit(); removed.isConnected = false;
  click('[data-dialog-accept]'); await tick();
  assert.equal(removed.submitted, undefined);
  console.log('PASS: cancellation, acceptance, safe text, focus, queue, duplicate submits, original submitter, detached form.');
})().catch(error => { console.error(error); process.exitCode = 1; });
