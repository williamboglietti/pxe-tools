// SPDX-License-Identifier: LGPL-3.0-only
(() => {
  const key = 'pxe-theme';
  const system = matchMedia('(prefers-color-scheme: dark)');
  const sanitize = value => value === 'light' || value === 'dark' ? value : 'auto';
  let choice = 'auto';
  try { choice = sanitize(localStorage.getItem(key)); } catch { /* Use the system preference. */ }

  function apply() {
    document.documentElement.dataset.theme = choice === 'auto'
      ? (system.matches ? 'dark' : 'light') : choice;
    for (const input of document.querySelectorAll('[data-theme-choice]')) {
      input.checked = input.value === choice;
    }
  }

  apply();
  system.addEventListener('change', apply);
  document.addEventListener('DOMContentLoaded', apply, { once: true });
  document.addEventListener('change', event => {
    const control = event.target;
    if (!control.matches('[data-theme-choice]')) return;
    choice = sanitize(control.value);
    try { localStorage.setItem(key, choice); } catch { /* Apply for this page. */ }
    apply();
  });
  window.addEventListener('storage', event => {
    if (event.key !== key && event.key !== null) return;
    choice = sanitize(event.newValue);
    apply();
  });
})();
