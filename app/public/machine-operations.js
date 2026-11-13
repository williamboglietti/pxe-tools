// Refresh eligibility around existing forms. Field values, validation state and focus stay local.
(() => {
  'use strict';
  const previousDisabled = new WeakMap();
  let state = null;
  let signature = null;
  const header = () => document.getElementById('machine-operation-header');
  const forms = () => [...document.querySelectorAll('[data-operation-kind]')];
  const wizard = document.getElementById('install-wizard-form');
  if (wizard) wizard.dataset.operationKind = 'install';

  function permission(form) {
    return state?.rules?.[form.dataset.operationKind] || {allowed: false, reason: 'État des opérations indisponible. Actualisez le suivi avant de lancer cette action.'};
  }
  function setControls(form, allowed) {
    form.querySelectorAll('button[type="submit"],input[type="submit"],button:not([type])').forEach(button => {
      if (!allowed) {
        if (!previousDisabled.has(button)) previousDisabled.set(button, button.dataset.operationLocked === '1' ? false : button.disabled);
        button.dataset.operationLocked = '1';
        if (!button.disabled) button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
        button.setAttribute('title', permission(form).reason);
      } else if (button.dataset.operationLocked === '1') {
        button.disabled = previousDisabled.get(button) ?? false;
        previousDisabled.delete(button);
        delete button.dataset.operationLocked;
        button.removeAttribute('aria-disabled');
        button.removeAttribute('title');
      }
    });
  }
  function noticeFor(form) {
    let notice = form.querySelector('[data-operation-notice]');
    if (!notice) {
      notice = document.createElement('p');
      notice.dataset.operationNotice = '';
      notice.className = 'field-hint';
      notice.setAttribute('role', 'status');
      notice.setAttribute('tabindex', '-1');
      form.prepend(notice);
    }
    return notice;
  }
  function sync() {
    const current = header();
    if (!current) return;
    let next;
    try { next = JSON.parse(current.dataset.operationState); } catch (_) { return; }
    const changed = signature !== null && signature !== current.dataset.sig;
    state = next;
    signature = current.dataset.sig;
    document.querySelectorAll('[data-operation-reason]').forEach(element => {
      const rule = state.rules[element.dataset.operationReason];
      element.textContent = rule?.reason || '';
      element.hidden = !element.textContent;
    });
    document.querySelectorAll('[data-operation-rescue]').forEach(element => {
      element.textContent = state.rescue_mode ? 'Mode récupération' : 'Normal';
    });
    forms().forEach(form => {
      const rule = permission(form);
      setControls(form, rule.allowed);
      const notice = noticeFor(form);
      if (!rule.allowed) {
        notice.textContent = rule.reason + ' Vos valeurs restent conservées. Le serveur contrôle aussi chaque lancement.';
        notice.hidden = false;
      } else if (changed || notice.dataset.wasBlocked === '1') {
        notice.textContent = 'L’état du suivi a changé. Vos valeurs sont conservées ; vérifiez-les avant le lancement. Les actions autorisées sont à jour.';
        notice.hidden = false;
      }
      if (!notice.textContent) notice.hidden = true;
      notice.dataset.wasBlocked = rule.allowed ? '0' : '1';
    });
  }
  document.addEventListener('htmx:configRequest', event => {
    if (event.detail.elt?.id === 'machine-operation-header') event.detail.parameters.sig = header()?.dataset.sig || '';
  });
  ['htmx:afterSwap', 'htmx:afterSettle', 'htmx:afterRequest'].forEach(name => {
    document.addEventListener(name, event => {
      if (event.detail.target?.id === 'machine-operation-header' || event.detail.elt?.id === 'machine-operation-header') sync();
    });
  });
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.dataset?.operationKind || permission(form).allowed) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    sync();
    noticeFor(form).focus();
  }, true);
  // An asynchronous wizard review must not re-enable launch after an operation started elsewhere.
  new MutationObserver(records => {
    if (!state || !records.some(record => record.target.matches('button[type="submit"],input[type="submit"],button:not([type])'))) return;
    forms().forEach(form => { if (!permission(form).allowed) setControls(form, false); });
  }).observe(document.body, {subtree: true, attributes: true, attributeFilter: ['disabled']});
  sync();
})();
