(() => {
  'use strict';
  // Layout/fragment re-evaluation must never duplicate operational handlers.
  if (window.__pxeBulkInstallBound) return;
  window.__pxeBulkInstallBound = true;

  // Sélection groupée des machines. Le polling HTMX (#machines-section, every 5s,
  // hx-swap="outerHTML") remplace toute la section — header (avec ses actions),
  // table, checkboxes et boutons compris. On ne peut donc PAS capturer les
  // éléments une fois ni poser des listeners directs (orphelins après le 1er swap).
  // Deux principes :
  //   1. Source de vérité = `selectedIds` (Set d'IDs), qui survit aux swaps. Après
  //      chaque rendu (htmx:afterSwap) on re-coche les checkboxes ET on rebascule
  //      le header (#btn-new-machine ↔ #bulk-actions) depuis le Set.
  //   2. Tous les listeners sont délégués sur `document` (stable), jamais sur les
  //      éléments swappés.

  const REINSTALL_STATUSES = ['installed', 'provisioned'];
  const ACTIVE_STATUSES    = ['wiping', 'installing', 'inventorying', 'testing'];

  const $          = (id) => document.getElementById(id);
  const rowChecks  = () => [...document.querySelectorAll('.row-select')];
  const checked    = () => rowChecks().filter((c) => c.checked);

  let selectedIds = new Set();

  function syncChecksFromState() {
    rowChecks().forEach((c) => { c.checked = selectedIds.has(c.dataset.id); });
  }

  function refreshBar() {
    const actions = $('bulk-actions');
    const newBtn  = $('btn-new-machine');
    if (!actions || !newBtn) return; // page sans table (état vide)
    // Purge les IDs disparus du DOM (machine supprimée / filtrée).
    const present = new Set(rowChecks().map((c) => c.dataset.id));
    selectedIds = new Set([...selectedIds].filter((id) => present.has(id)));

    const n = selectedIds.size;
    ['bulk-count', 'bulk-count-delete'].forEach((id) => {
      const el = $(id);
      if (el) el.textContent = n;
    });
    // Bascule mutuellement exclusive header : « + Nouvelle machine » ↔ actions
    // groupées. refreshBar est l'unique mutateur de ces deux display.
    actions.style.display = n > 0 ? '' : 'none';
    newBtn.style.display  = n > 0 ? 'none' : '';

    const selectAll = $('select-all');
    if (selectAll) {
      const total = rowChecks().length;
      selectAll.checked       = (n > 0 && n === total);
      selectAll.indeterminate = (n > 0 && n < total);
    }
  }

  // ---- Changements de cases (délégué) --------------------------------------
  document.addEventListener('change', (e) => {
    const t = e.target;
    if (t.classList?.contains('row-select')) {
      if (t.checked) selectedIds.add(t.dataset.id);
      else           selectedIds.delete(t.dataset.id);
      refreshBar();
    } else if (t.id === 'select-all') {
      rowChecks().forEach((c) => {
        c.checked = t.checked;
        if (t.checked) selectedIds.add(c.dataset.id);
        else           selectedIds.delete(c.dataset.id);
      });
      refreshBar();
    }
  });

  // ---- Boutons d'actions groupées du header (délégué) ----------------------
  // Désélection : pas de bouton dédié — la case « tout sélectionner » de
  // l'en-tête du tableau (#select-all) vide la sélection (cf. handler change).
  document.addEventListener('click', (e) => {
    if (e.target.closest?.('#bulk-delete-btn'))  { handleDelete();  return; }
    if (e.target.closest?.('#bulk-install-btn')) { openModal();     return; }
  });

  // ---- Suppression groupée -------------------------------------------------
  function handleDelete() {
    const sel = checked();
    if (sel.length === 0) return;
    const form          = $('bulk-delete-form');          // dans la section swappée
    const idsContainer  = $('bulk-delete-machine-ids');   // → re-query au clic
    if (!form || !idsContainer) return;

    const names  = sel.map((c) => c.dataset.hostname).join(', ');
    const active = sel.filter((c) => ACTIVE_STATUSES.includes(c.dataset.status));
    let prompt = `Supprimer ${sel.length} machine(s) ?\n\n${names}\n\n` +
                 `Les fiches, inventaires, configurations enregistrées et historiques liés seront supprimés. Cela n’efface pas les disques et ne garantit pas l’arrêt des processus distants.`;
    if (active.length > 0) {
      const activeNames = active.map((c) => `  • ${c.dataset.hostname} (${c.dataset.status})`).join('\n');
      prompt = `⚠️ ATTENTION : ${active.length} machine(s) sont en cours d'opération :\n\n${activeNames}\n\n` +
               `Si vous confirmez, elles seront supprimées en plein milieu de leur tâche. ` +
               `Elles continueront leur opération côté machine mais ne seront plus suivies depuis l'UI. ` +
               `Au prochain poll, elles seront recréées automatiquement avec une demande d'action.\n\n` +
               `--- Détails complets ---\n` + prompt;
    }
    if (!window.confirm(prompt)) return;
    idsContainer.innerHTML = sel
      .map((c) => `<input type="hidden" name="machine_ids[]" value="${c.dataset.id}">`)
      .join('');
    form.submit();
  }

  // ---- Installation groupée (modal) ----------------------------------------
  function openModal() {
    const modal = $('bulk-install-modal'); // hors section (persiste)
    const sel   = checked();
    if (!modal || sel.length === 0) return;

    const machines = sel.map((c) => ({
      id: c.dataset.id, hostname: c.dataset.hostname, status: c.dataset.status,
    }));

    $('bulk-modal-count').textContent = machines.length;
    $('bulk-modal-list').textContent  = machines.map((m) => m.hostname).join(', ');

    const reinstall = machines.filter((m) => REINSTALL_STATUSES.includes(m.status));
    const warn = $('bulk-modal-warning');
    if (reinstall.length > 0) {
      $('bulk-modal-warning-count').textContent = reinstall.length;
      $('bulk-modal-warning-list').textContent  = reinstall.map((m) => m.hostname).join(', ');
      warn.hidden = false;
    } else {
      warn.hidden = true;
    }

    const idsContainer = $('bulk-modal-machine-ids');
    idsContainer.replaceChildren();
    machines.forEach((m) => {
      const inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = 'machine_ids[]'; inp.value = m.id;
      idsContainer.appendChild(inp);
    });

    invalidateReview();
    modal.showModal();
  }

  let reviewVersion = 0;
  let readyTargets = [];
  let launching = false;
  function invalidateReview() {
    reviewVersion++;
    readyTargets = [];
    if ($('bulk-launch-btn')) $('bulk-launch-btn').disabled = true;
    $('bulk-preflight-fingerprints')?.replaceChildren();
    $('bulk-preflight-results')?.replaceChildren();
    if ($('bulk-preflight-status')) $('bulk-preflight-status').textContent = 'Contrôle préalable requis. Aucune opération ne sera lancée pendant le contrôle.';
  }
  document.addEventListener('change', (e) => {
    if (e.target.closest?.('#bulk-install-form') && !launching) invalidateReview();
  });
  function paragraph(parent, value) {
    const p = document.createElement('p'); p.textContent = value; parent.append(p); return p;
  }
  async function jsonRequest(url, body) {
    const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' } });
    if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Session expirée ou réponse indisponible. Reconnectez-vous puis relancez le contrôle.');
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Le contrôle a échoué. Réessayez.');
    return data;
  }
  async function preflight() {
    const form = $('bulk-install-form');
    if (!form?.reportValidity() || launching) return;
    invalidateReview();
    const version = reviewVersion;
    const button = $('bulk-preflight-btn');
    button.disabled = true;
    $('bulk-preflight-status').textContent = 'Contrôle des cibles en cours…';
    try {
      const data = await jsonRequest('/machines/bulk-preflight', new FormData(form));
      if (version !== reviewVersion) return;
      const results = $('bulk-preflight-results');
      results.replaceChildren();
      data.targets.forEach((target) => {
        const card = document.createElement('article'); card.className = 'card mb-3';
        const title = document.createElement('h3');
        const link = document.createElement('a'); link.href = '/machines/' + target.id + '#install'; link.textContent = target.label;
        title.append(link, document.createTextNode(' · ' + ({ ready: 'Prête', blocked: 'Bloquée', adapt: 'À adapter' }[target.state] || target.state)));
        card.append(title);
        target.reasons.forEach((reason) => paragraph(card, reason));
        if (target.image) paragraph(card, target.image);
        paragraph(card, 'Réseau : ' + target.network.join(' ; '));
        paragraph(card, 'Installation : ' + target.disks.join(', '));
        if (target.boot_mode) paragraph(card, 'Firmware observé : ' + target.boot_mode);
        if (Array.isArray(target.preserved_disks)) paragraph(card, 'Disques hors partitionnement et pré-effacement : ' + (target.preserved_disks.join(', ') || 'aucun autre disque inventorié'));
        paragraph(card, 'Pré-effacement : ' + (target.pre_wipe.length ? target.pre_wipe.map((d) => d.name + ' (' + d.mode + ')').join(', ') : 'aucun — le partitionnement d’installation reste destructif'));
        if (target.action_id) { const a = document.createElement('a'); a.href = '/tasks/' + target.action_id; a.textContent = 'Voir la progression'; card.append(a); }
        results.append(card);
      });
      readyTargets = data.targets.filter((t) => t.state === 'ready');
      $('bulk-preflight-status').textContent = readyTargets.length + ' sur ' + data.total + ' cible(s) prête(s). Seules ces cibles seront programmées ; elles seront contrôlées à nouveau au lancement.';
      $('bulk-launch-btn').textContent = 'Programmer ' + readyTargets.length + ' cible(s) prête(s)';
      $('bulk-launch-btn').disabled = readyTargets.length === 0;
    } catch (error) {
      if (version === reviewVersion) $('bulk-preflight-status').textContent = error.message;
    } finally { button.disabled = false; }
  }
  document.addEventListener('click', (e) => {
    if (e.target.closest?.('#bulk-preflight-btn')) preflight();
  });
  document.addEventListener('submit', async (e) => {
    if (e.target.id !== 'bulk-install-form') return;
    e.preventDefault();
    if (launching || !readyTargets.length) return;
    const form = e.target;
    const data = new FormData(form);
    data.delete('machine_ids[]');
    readyTargets.forEach((t) => { data.append('machine_ids[]', t.id); data.append('fingerprints[' + t.id + ']', t.fingerprint); });
    launching = true;
    form.querySelectorAll('button,select,input').forEach((el) => { el.disabled = true; });
    $('bulk-preflight-status').textContent = 'Programmation en cours…';
    try {
      const result = await jsonRequest(form.action, data);
      const container = $('bulk-preflight-results'); container.replaceChildren();
      result.results.forEach((target) => {
        const p = paragraph(container, 'Machine #' + target.id + ' : ' + (target.ok ? 'programmée. ' : target.reason));
        if (target.ok) { const a = document.createElement('a'); a.href = '/tasks/' + target.action_id; a.textContent = 'Suivre l’opération'; p.append(a); }
      });
      $('bulk-preflight-status').textContent = result.started_count + ' installation(s) programmée(s). Les résultats restent disponibles dans Activité.';
      readyTargets = [];
    } catch (error) {
      readyTargets = [];
      $('bulk-preflight-status').textContent = error.message + ' Consultez l’activité avant de réessayer : certaines demandes peuvent avoir été reçues.';
    } finally {
      launching = false;
      form.querySelectorAll('button,select,input').forEach((el) => { el.disabled = false; });
      $('bulk-launch-btn').disabled = true;
    }
  });

  $('bulk-install-modal')?.addEventListener('cancel', (e) => { if (launching) e.preventDefault(); });

  // Modal (hors section, stable) : annuler + clic backdrop.
  document.addEventListener('click', (e) => {
    const modal = $('bulk-install-modal');
    if (!modal) return;
    if (launching) return;
    if (e.target.closest?.('#bulk-modal-cancel')) { modal.close(); $('bulk-install-btn')?.focus(); return; }
    if (e.target === modal) modal.close();
  });

  // ---- Préservation à travers le swap de #machines-section -----------------
  // Le polling remplace la section (hx-swap="outerHTML") → header + cases re-rendus
  // en état "0 sélectionnée". On restaure depuis selectedIds (idempotent).
  // PIÈGE htmx : `attributesToSettle` inclut "style" et "class" → la phase de
  // settle (≈20 ms APRÈS htmx:afterSwap) réapplique l'attribut `style` du fragment
  // et ÉCRASE notre `style.display` du header (la coche .checked et le compteur
  // textContent, eux, survivent car non « settled »). Il faut donc restaurer
  // (aussi et surtout) sur htmx:afterSettle, qui se déclenche APRÈS le settle.
  // On binde les deux : afterSwap re-coche tout de suite (pas de flash des cases),
  // afterSettle re-bascule le header de façon durable.
  function restoreSelectionUi() {
    if (!$('bulk-actions')) return;
    syncChecksFromState();
    refreshBar();
  }
  document.body.addEventListener('htmx:afterSwap', restoreSelectionUi);
  document.body.addEventListener('htmx:afterSettle', restoreSelectionUi);

  // Anti-flash racine : à chaque poll de la liste, on renvoie la signature
  // courante (data-sig de #machines-section). Si le serveur la juge inchangée, il
  // répond 204 → htmx ne swappe pas → le tableau (et les cases cochées) n'est pas
  // recréé du tout → zéro clignotement. Sur changement réel → 200 + nouveau swap.
  document.body.addEventListener('htmx:configRequest', (e) => {
    if (e.detail && e.detail.path && e.detail.path.indexOf('/machines/list-fragment') !== -1) {
      const sec = $('machines-section');
      e.detail.parameters.sig = sec ? (sec.dataset.sig || '') : '';
    }
  });

  // Anti-flash des cases : htmx:afterSwap suit l'insertion DOM → une frame avec
  // les cases décochées (fragment frais) peut être peinte AVANT la re-coche, d'où
  // un clignotement visible au polling. Un MutationObserver sur le parent (stable)
  // de la section se déclenche en MICROTASK, AVANT le paint → on re-coche avant
  // que la frame décochée soit rendue. (afterSettle reste pour le header, que le
  // settle htmx écrase, cf. plus haut.)
  const sectionParent = $('machines-section') && $('machines-section').parentElement;
  if (sectionParent) {
    new MutationObserver(restoreSelectionUi).observe(sectionParent, { childList: true });
  }

  refreshBar();
})();
