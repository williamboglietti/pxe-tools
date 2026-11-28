// SPDX-License-Identifier: LGPL-3.0-only
(() => {
  const drawer = document.getElementById('mobile-navigation');
  const trigger = document.querySelector('.mobile-toggle');
  if (typeof drawer?.showModal !== 'function' || !trigger) return;

  const compact = matchMedia('(max-width: 920px)');
  trigger.addEventListener('click', () => {
    drawer.showModal();
    trigger.setAttribute('aria-expanded', 'true');
  });
  drawer.addEventListener('close', () => {
    trigger.setAttribute('aria-expanded', 'false');
  });
  drawer.addEventListener('click', event => {
    if (event.target === drawer) drawer.close();
  });
  drawer.querySelector('nav').addEventListener('click', event => {
    if (event.target.closest('a')) drawer.close();
  });
  compact.addEventListener('change', () => {
    if (!compact.matches && drawer.open) drawer.close();
  });
  document.documentElement.classList.add('navigation-ready');
})();

(() => {
  const menus = document.querySelectorAll('.profile-menu, .notification-menu');
  for (const menu of menus) {
    menu.addEventListener('keydown', event => {
      if (event.key !== 'Escape' || !menu.open) return;
      event.preventDefault();
      event.stopPropagation();
      menu.open = false;
      menu.querySelector('summary').focus();
    });
    menu.addEventListener('focusout', event => {
      if (event.relatedTarget && !menu.contains(event.relatedTarget)) menu.open = false;
    });
  }
  document.addEventListener('pointerdown', event => {
    for (const menu of menus) {
      if (!menu.contains(event.target)) menu.open = false;
    }
  });
})();

(() => {
  const menu = document.querySelector('[data-notifications]');
  if (!menu) return;
  const summary = menu.querySelector('summary');
  const count = menu.querySelector('[data-notification-count]');
  const status = menu.querySelector('[data-notification-status]');
  const list = menu.querySelector('[data-notification-list]');
  const read = menu.querySelector('[data-notification-read]');
  const announcement = document.querySelector('[data-notification-announcement]');
  const key = 'pxe-notifications:' + menu.dataset.account;
  const date = new Intl.DateTimeFormat('fr', { dateStyle: 'short', timeStyle: 'short' });
  let entries = [];
  let snapshot = '';
  let loading = false;
  let expired = false;
  let seen = loadSeen();

  function loadSeen() {
    try {
      const values = JSON.parse(localStorage.getItem(key));
      if (Array.isArray(values)) return new Set(values.slice(-100));
    } catch {
      // Reading still works when browser storage is unavailable.
    }
    return new Set();
  }

  function unread() {
    const pending = entries.filter(entry => !seen.has(entry.key)).length;
    for (const row of list.children) row.classList.toggle('is-unread', !seen.has(row.dataset.key));
    const label = pending > 1 ? `${pending} notifications non lues` : '1 notification non lue';
    const message = pending ? label : '';
    if (announcement.textContent !== message) announcement.textContent = message;
    summary.setAttribute('aria-label', pending ? 'Notifications, ' + label : 'Notifications');
    count.textContent = pending;
    count.hidden = !pending;
    read.disabled = !pending;
  }

  function render(items) {
    const version = JSON.stringify(items);
    if (version === snapshot) return;
    snapshot = version;
    entries = items;
    const focused = list.contains(document.activeElement) ? document.activeElement.closest('li').dataset.key : null;
    let restoreFocus;
    list.replaceChildren(...items.map(entry => {
      const row = document.createElement('li');
      row.dataset.key = entry.key;
      row.className = 'notification-row notification-row--' + entry.tone;
      const link = document.createElement('a');
      link.href = entry.href;
      const title = document.createElement('strong');
      title.textContent = entry.title;
      const detail = document.createElement('span');
      detail.textContent = entry.detail;
      link.append(title, detail);
      if (entry.at !== null) {
        const timestamp = new Date(entry.at * 1000);
        const time = document.createElement('time');
        time.dateTime = timestamp.toISOString();
        time.textContent = date.format(timestamp);
        link.append(time);
      }
      if (entry.key === focused) restoreFocus = link;
      row.append(link);
      return row;
    }));
    if (focused) (restoreFocus ?? summary).focus({ preventScroll: true });
    unread();
  }

  async function refresh() {
    if (loading || expired || document.hidden) return;
    loading = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(menu.dataset.notifications, {
        headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal,
      });
      expired = response.redirected || [401, 403].includes(response.status);
      if (!response.ok || expired) throw new Error();
      render(await response.json());
      status.hidden = entries.length > 0;
      if (!status.hidden) status.textContent = 'Aucune notification récente.';
    } catch {
      if (expired) render([]);
      status.hidden = false;
      status.textContent = expired ? 'Reconnectez-vous pour recevoir les notifications.' : 'Notifications indisponibles. Un nouvel essai aura lieu automatiquement.';
    } finally {
      clearTimeout(timeout);
      loading = false;
    }
  }

  read.addEventListener('click', () => {
    if (document.activeElement === read) summary.focus();
    seen = new Set([...loadSeen(), ...seen, ...entries.map(entry => entry.key)].slice(-100));
    try { localStorage.setItem(key, JSON.stringify([...seen])); } catch { /* Keep this tab usable. */ }
    unread();
  });
  window.addEventListener('storage', event => {
    if (event.key === key) {
      seen = loadSeen();
      unread();
    }
  });
  menu.addEventListener('toggle', () => { if (menu.open) refresh(); });
  document.addEventListener('visibilitychange', refresh);
  setInterval(refresh, 15_000);
  refresh();

  if (menu.hasAttribute('data-check-updates') && !document.querySelector('[data-update-phase]')) {
    const checkUpdates = () => {
      if (expired || document.hidden) return;
      fetch('/settings/updates', {
        method: 'POST', headers: { Accept: 'application/json' },
        body: new URLSearchParams({ action: 'auto' }), cache: 'no-store',
        signal: AbortSignal.timeout(8000),
      }).catch(() => {});
    };
    checkUpdates();
    setInterval(checkUpdates, 15 * 60_000);
  }
})();

(() => {
  const panel = document.querySelector('[data-update-phase]');
  if (!panel) return;
  const pending = phase => ['queued', 'checking', 'install_queued', 'installing'].includes(phase);
  const route = '/settings/updates';
  const authenticationLost = response => response.redirected || [401, 403].includes(response.status);

  async function request(body) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(body ? route : route + '/status', {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: controller.signal,
        ...(body && { method: 'POST', body }),
      });
      const hasStatus = !authenticationLost(response) && (response.ok || response.status === 409);
      const update = hasStatus ? await response.json() : null;
      return { response, update };
    } finally {
      clearTimeout(timeout);
    }
  }

  const check = panel.querySelector('[data-update-action="check"]');
  const install = panel.querySelector('[data-update-action="install"]');
  const checkButton = check.querySelector('button');
  const installButton = install.querySelector('button');
  const progress = panel.querySelector('[data-update-progress]');
  const message = panel.querySelector('[data-update-message]');
  const notes = panel.querySelector('[data-update-notes]');
  const diagnostic = panel.querySelector('[data-update-diagnostic]');
  const packages = panel.querySelectorAll('[data-update-package]');
  let phase = panel.dataset.updatePhase;
  let deadline = 0;
  let tracking = false;
  let installing = false;
  let unconfirmed = false;

  function announce(text) {
    if (message.textContent !== text) message.textContent = text;
  }

  function setTracking(active) {
    tracking = active;
    progress.hidden = !active;
    checkButton.setAttribute('aria-disabled', String(active));
    installButton.setAttribute('aria-disabled', String(active || pending(phase)));
    checkButton.disabled = installButton.disabled = false;
  }

  function renderResult(update) {
    for (const row of packages) {
      const name = row.dataset.updatePackage;
      const installed = update.installed[name];
      const candidate = update.candidates[name];
      row.querySelector('[data-update-installed]').textContent = installed ?? 'À vérifier';
      row.querySelector('[data-update-candidate]').textContent = candidate ?? '—';
      row.hidden = name !== 'pxe-tools' && !installed && !candidate;
    }
    install.elements.plan.value = update.plan;
    if (!update.available && install.contains(document.activeElement)) {
      checkButton.focus({ preventScroll: true });
    }
    install.hidden = !update.available;
    notes.href = update.release_notes;
    diagnostic.hidden = update.phase !== 'failed';
  }

  async function poll(body) {
    try {
      const { response, update } = await request(body);
      if (authenticationLost(response)) {
        setTracking(false);
        announce('Rechargez la page pour vous reconnecter.');
        return;
      }
      if (update) {
        const requestUnconfirmed = unconfirmed && !body && update.phase === phase;
        phase = panel.dataset.updatePhase = update.phase;
        unconfirmed = false;
        if (!pending(phase)) {
          setTracking(false);
          renderResult(update);
          if (response.status === 409) announce('La version proposée a changé. Vérifiez-la avant de l’installer.');
          else if (requestUnconfirmed) announce('La demande a échoué. Réessayez.');
          else announce(update.message);
          return;
        }
        installing = ['install_queued', 'installing'].includes(phase);
        announce(update.message);
      } else if (body) {
        unconfirmed = true;
      }
    } catch {
      if (body) unconfirmed = true;
      if (installing) announce('Reconnexion à la console…');
    }
    if (Date.now() < deadline) {
      setTimeout(poll, 3000);
    } else {
      setTracking(false);
      announce('La console ne répond pas. Vérifiez les mises à jour pour reprendre le suivi.');
    }
  }

  function follow(form) {
    const body = form && !pending(phase) ? new URLSearchParams(new FormData(form)) : undefined;
    installing = body?.get('action') === 'install' || ['install_queued', 'installing'].includes(phase);
    unconfirmed = false;
    deadline = Date.now() + 26 * 60_000;
    setTracking(true);
    if (body) announce(installing ? 'Préparation de l’installation…' : 'Recherche des mises à jour…');
    poll(body);
  }

  for (const form of [check, install]) {
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (!tracking && !(form === install && pending(phase))) follow(form);
    });
  }
  if (pending(phase)) follow();
})();

(() => {
  let section = document.querySelector('[data-image-progress]');
  const active = '[data-state="pending"], [data-state="downloading"], [data-state="deleting"]';
  if (!section?.querySelector(active)) return;

  const url = new URL('/api/images-status', location.origin);
  url.search = location.search;

  async function poll() {
    try {
      const response = await fetch(url, { cache: 'no-store' });
      if (response.redirected || [401, 403, 404].includes(response.status)) return;
      if (response.ok) {
        const next = new DOMParser().parseFromString(await response.text(), 'text/html')
          .querySelector('[data-image-progress]');
        if (!next) return;
        const rows = [...next.querySelectorAll('[data-image-id]')];
        const existing = [...section.querySelectorAll('[data-image-id]')];
        if (rows.map(row => row.dataset.imageId).join() !== existing.map(row => row.dataset.imageId).join()) {
          if (section.contains(document.activeElement)) {
            setTimeout(poll, 2500);
            return;
          }
          section.replaceWith(next);
          section = next;
        } else {
          for (const [index, row] of rows.entries()) {
            const current = existing[index];
            const stateChanged = current.dataset.state !== row.dataset.state;
            if (stateChanged && current.querySelector('[data-image-actions]')?.contains(document.activeElement)) {
              setTimeout(poll, 2500);
              return;
            }
            current.dataset.state = row.dataset.state;
            for (const part of ['[data-download-status]', '[data-image-details]', '[data-image-actions]']) {
              const source = row.querySelector(part);
              const target = current.querySelector(part);
              if (source && target && (part !== '[data-image-actions]' || stateChanged) &&
                  source.innerHTML !== target.innerHTML) {
                target.replaceChildren(...source.childNodes);
              }
            }
          }
        }
        if (!section.querySelector(active)) return;
      }
    } catch {
      // Keep the last known progress visible during a temporary outage.
    }
    setTimeout(poll, 2500);
  }

  setTimeout(poll, 1000);
})();

(() => {
  const rail = document.querySelector('[data-catalog-tabs]');
  if (!rail) return;
  const list = rail.querySelector('[role="tablist"]');
  const tabs = [...list.querySelectorAll('[role="tab"]')];
  const panels = tabs.map(tab => document.getElementById(tab.getAttribute('aria-controls')));
  const previous = rail.querySelector('[data-tab-scroll="-1"]');
  const next = rail.querySelector('[data-tab-scroll="1"]');
  if (!tabs.length || panels.some(panel => !panel)) return;

  function activate(index, reveal = false) {
    tabs.forEach((tab, position) => {
      const selected = position === index;
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      panels[position].hidden = !selected;
    });
    if (reveal) tabs[index].scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  function updateArrows() {
    const overflow = list.scrollWidth > rail.clientWidth + 1;
    previous.hidden = next.hidden = !overflow;
    previous.disabled = list.scrollLeft <= 1;
    next.disabled = list.scrollLeft + list.clientWidth >= list.scrollWidth - 1;
  }

  tabs.forEach((tab, index) => {
    const panel = panels[index];
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', tab.id);
    panel.tabIndex = 0;
    tab.addEventListener('click', () => activate(index, true));
  });
  list.addEventListener('keydown', event => {
    const index = tabs.indexOf(event.target);
    if (index < 0) return;
    let target;
    switch (event.key) {
      case 'ArrowLeft': target = (index + tabs.length - 1) % tabs.length; break;
      case 'ArrowRight': target = (index + 1) % tabs.length; break;
      case 'Home': target = 0; break;
      case 'End': target = tabs.length - 1; break;
      default: return;
    }
    event.preventDefault();
    activate(target, true);
    tabs[target].focus({ preventScroll: true });
  });
  for (const arrow of [previous, next]) {
    arrow.addEventListener('click', () => {
      const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
      list.scrollBy({ left: Number(arrow.dataset.tabScroll) * list.clientWidth,
        behavior: reducedMotion ? 'auto' : 'smooth' });
    });
  }
  list.addEventListener('scroll', updateArrows, { passive: true });
  activate(0);
  rail.hidden = false;
  const observer = new ResizeObserver(updateArrows);
  observer.observe(rail);
  tabs.forEach(tab => observer.observe(tab));
  updateArrows();
})();

(() => {
  const form = document.querySelector('[data-catalog-selection]');
  if (!form) return;
  const submit = form.querySelector('button[type="submit"]');
  function update() {
    const count = [...form.elements].filter(input => input.name === 'image' && input.checked).length;
    submit.disabled = count === 0;
    submit.textContent = count ? `Télécharger la sélection (${count})` : 'Télécharger la sélection';
  }
  form.closest('#catalogue').addEventListener('change', update);
  window.addEventListener('pageshow', update);
  update();
})();

(() => {
  const status = document.querySelector('#catalogue [data-catalog-refresh]');
  const pending = state => state === 'pending' || state === 'running';
  if (!status || !pending(status.dataset.catalogRefresh)) return;

  async function updateVersions() {
    const response = await fetch('/settings/images/add', { cache: 'no-store' });
    if (response.redirected || [401, 403, 404].includes(response.status)) return true;
    if (!response.ok) return false;
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const catalog = document.getElementById('catalogue');
    const image = 'input[name="image"]';
    const selection = new Set([...catalog.querySelectorAll(`${image}:checked`)]
      .map(input => input.getAttribute('aria-label')));
    const focused = catalog.contains(document.activeElement) && document.activeElement.matches(image)
      ? document.activeElement.getAttribute('aria-label') : null;
    const tables = page.querySelectorAll('[data-catalog-versions]');
    if (!tables.length) return false;
    for (const table of tables) {
      const current = catalog.querySelector(`[data-catalog-versions="${table.dataset.catalogVersions}"]`);
      if (!current) return false;
      for (const group of [...current.tBodies]) group.remove();
      current.append(...table.tBodies);
      for (const input of current.querySelectorAll(image)) {
        const label = input.getAttribute('aria-label');
        input.checked = selection.has(label);
        if (label === focused) input.focus({ preventScroll: true });
      }
    }
    catalog.dispatchEvent(new Event('change'));
    return true;
  }

  async function poll() {
    try {
      const response = await fetch('/settings/images/refresh-button', { cache: 'no-store' });
      if (response.redirected || [401, 403, 404].includes(response.status)) return;
      if (response.ok) {
        const next = new DOMParser().parseFromString(await response.text(), 'text/html')
          .querySelector('[data-catalog-refresh]');
        if (!next) return;
        const state = next.dataset.catalogRefresh;
        status.dataset.catalogRefresh = state;
        if (status.innerHTML !== next.innerHTML) status.replaceChildren(...next.childNodes);
        if (state === 'success' && await updateVersions()) return;
        if (state !== 'success' && !pending(state)) return;
      }
    } catch {
      // Retry while the console is temporarily unavailable.
    }
    setTimeout(poll, 2000);
  }

  setTimeout(poll, 1000);
})();

(() => {
  let list = document.getElementById('activity-list');
  const freshness = document.querySelector('[data-activity-refresh]');
  if (!list || !freshness) return;

  const url = new URL('/activity/list', location.origin);
  url.search = location.search;

  async function refresh() {
    try {
      if (!document.hidden && !list.contains(document.activeElement)) {
        url.searchParams.set('sig', list.dataset.sig);
        const response = await fetch(url, { cache: 'no-store' });
        if (response.redirected || [401, 403, 404].includes(response.status)) return;
        if (response.status === 200) {
          const next = new DOMParser().parseFromString(await response.text(), 'text/html')
            .getElementById('activity-list');
          if (next && !list.contains(document.activeElement)) {
            list.replaceWith(next);
            list = next;
          }
        }
        if (response.ok) {
          const utc = new Date().toISOString().slice(11, 19);
          freshness.textContent = `Dernière actualisation à ${utc} UTC`;
        }
      }
    } catch {
      // Leave the latest results visible and try again.
    }
    setTimeout(refresh, 5000);
  }

  setTimeout(refresh, 5000);
})();

(() => {
  const panel = document.querySelector('[data-rhel-job]');
  if (!panel) return;

  async function refresh() {
    try {
      const response = await fetch(panel.dataset.rhelJob, { cache: 'no-store' });
      if (response.redirected || [401, 403, 404].includes(response.status)) return;
      if (response.ok) {
        const { status, message, progress_percent } = await response.json();
        if (status !== panel.dataset.rhelState) {
          location.reload();
          return;
        }
        const label = panel.querySelector('[role="status"]');
        if (message && message !== label.textContent) label.textContent = message;
        panel.querySelector('progress').value = progress_percent;
        panel.querySelector('span').textContent = progress_percent + ' %';
      }
    } catch {
      // Keep showing the last reported progress until the console responds again.
    }
    setTimeout(refresh, 2500);
  }

  setTimeout(refresh, 1000);
})();

(() => {
  const mode = document.querySelector('[data-acme-mode]');
  const privateFields = document.querySelector('[data-acme-private]');
  if (!mode || !privateFields) return;

  function syncMode() {
    const active = mode.value === 'private';
    for (const field of privateFields.querySelectorAll('input, textarea')) field.disabled = !active;
  }
  mode.addEventListener('change', () => {
    syncMode();
    privateFields.open = mode.value === 'private';
  });
  syncMode();
})();
