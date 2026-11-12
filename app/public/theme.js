(() => {
  'use strict';
  const root = document.documentElement;
  const system = matchMedia('(prefers-color-scheme: dark)');
  let mode = ['light', 'dark', 'system'].includes(root.dataset.themeMode) ? root.dataset.themeMode : 'system';
  function apply(next, persist = true) {
    mode = ['light', 'dark', 'system'].includes(next) ? next : 'system';
    root.dataset.themeMode = mode;
    root.dataset.theme = mode === 'system' ? (system.matches ? 'dark' : 'light') : mode;
    document.querySelectorAll('[data-theme-preference]').forEach(select => { select.value = mode; });
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      const label = root.dataset.theme === 'dark' ? 'Passer au thème clair' : 'Passer au thème sombre';
      button.setAttribute('aria-label', label); button.setAttribute('title', label);
    });
    if (persist) { try { localStorage.setItem('theme', mode); } catch (_) {} }
  }
  apply(mode, false);
  system.addEventListener('change', () => { if (mode === 'system') apply(mode, false); });
  document.addEventListener('click', event => { if (event.target.closest('[data-theme-toggle]')) apply(root.dataset.theme === 'dark' ? 'light' : 'dark'); });
  document.addEventListener('change', event => { if (event.target.matches('[data-theme-preference]')) apply(event.target.value); });
  document.addEventListener('click', event => {
    document.querySelectorAll('.account-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
  });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !document.querySelector('dialog[open]')) document.querySelectorAll('.account-menu[open]').forEach(menu => { menu.open = false; menu.querySelector('summary')?.focus(); }); });
})();
