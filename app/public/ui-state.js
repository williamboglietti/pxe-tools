// Shared behavior for read-only polling. Forms remain authoritative while editing.
(() => {
  'use strict';
  const snapshots = new Map();
  const compactFilters = matchMedia('(max-width:600px)');
  if (compactFilters.matches) document.querySelectorAll('.filter-options').forEach(el=>el.open=false);
  compactFilters.addEventListener?.('change', event => { if (!event.matches) document.querySelectorAll('.filter-options').forEach(el=>el.open=true); });
  let lastUpdate = Date.now();
  const failedScopes = new Map();
  const scopeUpdates = new Map();
  const scopeKey = detail => detail?.target?.id || detail?.elt?.getAttribute('hx-get') || 'page';
  function freshness(message, stale = false) {
    let nodes = [...document.querySelectorAll('[data-freshness]')];
    if (!nodes.length) {
      const main = document.querySelector('main'); if (!main) return;
      const node = document.createElement('p'); node.dataset.freshness = ''; node.className = 'freshness'; node.setAttribute('role','status'); main.prepend(node); nodes = [node];
    }
    nodes.forEach(node => { node.textContent = message; node.classList.toggle('is-stale', stale); });
  }
  function updateHealth() {
    if (!failedScopes.size) { freshness('Dernière actualisation : ' + new Date(lastUpdate).toISOString().slice(11,19) + ' UTC'); return; }
    const failures = [...failedScopes.values()];
    const sessionExpired = failures.some(f => f.message === 'Session expirée');
    const since = Math.min(...failures.map(f => f.since));
    freshness((sessionExpired ? 'Session expirée' : 'Certaines données ne sont plus actualisées') + ' · dernier contact de ces données à ' + new Date(since).toISOString().slice(11,19) + ' UTC. Les dernières données restent affichées.', true);
    if (sessionExpired) {
      const node = document.querySelector('[data-freshness]');
      if (node) { const link=document.createElement('a'); link.href='/login?next='+encodeURIComponent(location.pathname+location.search+location.hash); link.target='_blank';link.rel='noopener';link.textContent=' Se reconnecter dans un nouvel onglet';node.append(link); }
    }
  }
  function connectionLost(message = 'Données non actualisées', key = 'network') {
    if (!failedScopes.has(key)) failedScopes.set(key, {message,since:scopeUpdates.get(key) || lastUpdate});
    else if (message === 'Session expirée') failedScopes.get(key).message=message;
    updateHealth();
  }
  window.addEventListener('offline', () => connectionLost());
  document.addEventListener('htmx:configRequest', e => {
    const scope = e.detail.elt?.closest('[data-sig]');
    if (scope) e.detail.parameters.sig = scope.dataset.sig;
  });
  document.addEventListener('htmx:beforeRequest', e => {
    const target = e.detail.target;
    const active = document.activeElement;
    if (e.detail.elt?.getAttribute('hx-trigger')?.includes('every') && target?.contains(active) && active.matches('textarea,select,input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]),[contenteditable=true]')) {
      e.preventDefault(); freshness('Actualisation suspendue pendant la saisie. Vos modifications sont conservées.');
    }
  });
  document.addEventListener('htmx:beforeOnLoad', e => {
    const redirect = e.detail.xhr?.getResponseHeader('HX-Redirect');
    if (!redirect?.startsWith('/login')) return;
    e.preventDefault(); connectionLost('Session expirée', scopeKey(e.detail));
  });
  document.addEventListener('htmx:afterRequest', e => {
    const key=scopeKey(e.detail);
    if (e.detail.successful && !e.detail.xhr?.getResponseHeader('HX-Redirect')) {
      lastUpdate = Date.now(); scopeUpdates.set(key,lastUpdate);
      failedScopes.delete(key); failedScopes.delete('network'); updateHealth();
    } else if (e.detail.failed) connectionLost('Données non actualisées',key);
  });
  ['htmx:sendError','htmx:timeout','htmx:responseError'].forEach(event => document.addEventListener(event, e => connectionLost('Données non actualisées',scopeKey(e.detail))));
  document.addEventListener('htmx:beforeSwap', e => {
    const target = e.detail.target;
    if (!target?.id || !e.detail.shouldSwap) return;
    const controls = [...target.querySelectorAll('input,select,textarea,button,a[href],summary')];
    const active = document.activeElement;
    snapshots.set(target.id, {
      focusId: target.contains(active) ? active.id : null,
      focusIndex: controls.indexOf(active),
      details: [...target.querySelectorAll('details')].map(el => el.open),
      scroll: [...target.querySelectorAll('pre,.job-log,.events-log')].map(el => ({top:el.scrollTop,left:el.scrollLeft,bottom:el.scrollHeight-el.clientHeight-el.scrollTop<8})),
      x:window.scrollX,y:window.scrollY
    });
  });
  document.addEventListener('htmx:afterSettle', e => {
    const target = e.detail.target;
    const current = target?.id && document.getElementById(target.id);
    const state = current && snapshots.get(current.id); if (!state) return;
    current.querySelectorAll('details').forEach((el,i) => { if (i < state.details.length) el.open=state.details[i]; });
    current.querySelectorAll('pre,.job-log,.events-log').forEach((el,i) => { const old=state.scroll[i]; if(old){el.scrollTop=old.bottom?el.scrollHeight:old.top;el.scrollLeft=old.left;} });
    if (state.focusIndex>=0) {
      const control=state.focusId ? document.getElementById(state.focusId) : current.querySelectorAll('input,select,textarea,button,a[href],summary')[state.focusIndex];
      control?.focus({preventScroll:true});
    }
    window.scrollTo(state.x,state.y); snapshots.delete(current.id);
  });
  document.querySelectorAll('#fleet-filters,#activity-filters').forEach(form => form.addEventListener('submit', () => { const page=form.elements.page; if(page)page.value='1'; }));
  // Native constraint validation must reveal the invalid control in disclosures.
  document.addEventListener('invalid', e => {
    let parent=e.target.parentElement; while(parent){if(parent.tagName==='DETAILS')parent.open=true;parent=parent.parentElement;}
  }, true);
})();
