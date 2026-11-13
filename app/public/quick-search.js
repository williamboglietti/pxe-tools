(() => {
  'use strict';
  const dialog = document.getElementById('quick-search');
  const input = document.getElementById('quick-search-input');
  if (!dialog || !input) return;
  let returnFocus = null;
  let previousOverflow = '';
  const entries = [...dialog.querySelectorAll('[data-search-entry]')];
  const normal = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
  function filter() {
    const query = normal(input.value.trim());
    entries.forEach(entry => { entry.hidden = query !== '' && !normal(entry.dataset.searchEntry).includes(query); });
  }
  function open() {
    if (dialog.open) return;
    returnFocus = document.activeElement;
    if (document.getElementById('app-sidebar')?.classList.contains('is-open')) {
      returnFocus = document.querySelector('[data-sidebar-open]');
    }
    document.dispatchEvent(new Event('pxe:search-open'));
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    input.value = ''; filter();
    dialog.showModal(); input.focus();
  }
  function close() { if (dialog.open) dialog.close(); }
  document.addEventListener('click', event => { if (event.target.closest('[data-search-open]')) open(); if (event.target.closest('[data-search-close]')) close(); });
  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !event.altKey) { event.preventDefault(); if (dialog.open) close(); else open(); }
  });
  input.addEventListener('input', filter);
  input.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown') { event.preventDefault(); entries.find(entry => !entry.hidden)?.focus(); }
  });
  dialog.addEventListener('click', event => {
    if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close(); }
  });
  dialog.addEventListener('close', () => {
    document.body.style.overflow = previousOverflow;
    const visible = returnFocus?.isConnected && returnFocus.getClientRects().length > 0 && !returnFocus.closest('[inert]');
    if (visible) returnFocus.focus();
    else document.querySelector('[data-sidebar-open]')?.focus();
  });
})();
