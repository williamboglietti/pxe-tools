/**
 * Assistant 4 étapes /machines/{id}/install :
 *   - navigation steps (Suivant / Précédent)
 *   - validation HTML5 par étape avant d'avancer
 *   - chargement d'un preset comme template (fetch /presets/{id}.json)
 *   - réutilise builder-shared.js pour les rows DNS / partitions
 */
(() => {
  'use strict';

  if (!window.builderShared) {
    console.warn('install-wizard.js: builder-shared.js doit être chargé avant');
    return;
  }
  window.builderShared.initBuilders(document);

  const form = document.getElementById('install-wizard-form');
  if (!form) return;

  const steps = [...form.querySelectorAll('.wizard-form-step')];
  const indicators = [...document.querySelectorAll('.wizard-stepper [data-step-indicator]')];

  // Un mot de passe root ne sert pas lorsque le compte root est désactivé.
  // Désactiver l'input évite également qu'une valeur trop courte, saisie avant
  // de changer de mode, bloque la validation native alors que le bloc est masqué.
  const syncRootPasswordVisibility = () => {
    const section = form.querySelector('#root-password-section');
    const password = form.querySelector('input[name="root_password"]');
    const rootMode = form.querySelector('input[name="root_login_mode"]:checked')?.value;
    const isDisabled = rootMode === 'disabled';
    if (section) section.hidden = isDisabled;
    if (password) password.disabled = isDisabled;
  };
  form.querySelectorAll('input[name="root_login_mode"]').forEach(input => {
    input.addEventListener('change', syncRootPasswordVisibility);
  });
  syncRootPasswordVisibility();

  const goToStep = (target) => {
    if (target === 4) buildSummary();
    steps.forEach(s => {
      s.hidden = (s.dataset.step !== String(target));
    });
    indicators.forEach(i => {
      const n = parseInt(i.dataset.stepIndicator, 10);
      i.classList.toggle('active', n === target);
      i.classList.toggle('done', n < target);
      if (n === target) i.setAttribute('aria-current', 'step'); else i.removeAttribute('aria-current');
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Échappe les valeurs avant insertion dans innerHTML (XSS safe).
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  let previewSequence = 0;
  let reviewReady = false;
  const launchButton = form.querySelector('[data-launch-install]');
  const errorBox = document.getElementById('install-errors');

  function revealField(field, step) {
    const el = form.querySelector(`[name="${CSS.escape(field)}"]`);
    const actualStep = el?.closest('.wizard-form-step');
    goToStep(Number(actualStep?.dataset.step || step || 1));
    if (el) {
      for (let parent = el.parentElement; parent && parent !== form; parent = parent.parentElement) {
        if (parent.tagName === 'DETAILS') parent.open = true;
      }
      const focusable = el.type === 'hidden' ? el.closest('fieldset')?.querySelector('input:not([type="hidden"]),select,button') : el;
      focusable?.focus();
      focusable?.scrollIntoView({block: 'center', behavior: 'smooth'});
    }
  }

  function showErrors(errors) {
    errorBox.hidden = errors.length === 0;
    errorBox.innerHTML = errors.length ? '<strong>Corrigez les points suivants. Votre saisie est conservée.</strong><ul>' + errors.map((error, i) =>
      `<li><button type="button" class="error-link" data-error-index="${i}">${esc(error.message)}</button></li>`).join('') + '</ul>' : '';
    errorBox.querySelectorAll('[data-error-index]').forEach(button => button.addEventListener('click', () => {
      const error = errors[Number(button.dataset.errorIndex)];
      revealField(error.field, error.step);
    }));
    if (errors.length) revealField(errors[0].field, errors[0].step);
  }

  async function buildSummary() {
    const sequence = ++previewSequence;
    reviewReady = false;
    if (launchButton) launchButton.disabled = true;
    const summary = document.getElementById('confirm-summary');
    summary.textContent = 'Vérification de la configuration et du matériel…';
    try {
      const response = await fetch(form.dataset.previewUrl, {
        method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'},
        body: window.builderShared.installFormData(form),
      });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Session expirée ou réponse inattendue. Reconnectez-vous dans un autre onglet puis réessayez.');
      const data = await response.json();
      if (sequence !== previewSequence) return;
      if (data.errors?.length) { showErrors(data.errors); summary.textContent = 'La configuration contient des erreurs.'; return; }
      if (!response.ok) throw new Error(data.error || 'La vérification a échoué.');
      showErrors([]);
      summary.innerHTML = '<table class="summary-table"><tbody>' + (data.review || []).map(row =>
        `<tr><th scope="row">${esc(row.label)}</th><td>${esc(row.value)}</td></tr>`).join('') + '</tbody></table>';
      reviewReady = true;
      if (launchButton) launchButton.disabled = false;
    } catch (error) {
      if (sequence !== previewSequence) return;
      summary.innerHTML = `<p class="bulk-warning" role="alert">${esc(error.message)}</p><button type="button" class="btn btn-secondary" data-retry-review>Réessayer la vérification</button>`;
      summary.querySelector('[data-retry-review]')?.addEventListener('click', buildSummary);
    }
  }

  // Validation HTML5 + règles contextuelles (static / custom partitions)
  const validateStep = (stepEl) => {
    const inputs = [...stepEl.querySelectorAll('input, select, textarea')]
      .filter(el => !el.disabled && !el.closest('[hidden]'));
    for (const el of inputs) {
      if (!el.checkValidity()) {
        revealField(el.name, Number(stepEl.dataset.step));
        el.reportValidity();
        return false;
      }
    }

    const stepNum = parseInt(stepEl.dataset.step, 10);

    // Étape 2 : si mode static, IP/gateway/CIDR/au moins 1 DNS requis
    if (stepNum === 2) {
      const networkMode = document.querySelector('input[name="network_mode"]:checked')?.value;
      if (networkMode === 'static') {
        const requiredFields = stepEl.querySelectorAll('[data-required-if-static]');
        for (const el of requiredFields) {
          if (!el.value.trim()) {
            el.focus();
            showErrors([{message: 'Mode statique : ce champ est requis.', step: 2, field: el.name}]);
            return false;
          }
        }
        const dnsInputs = stepEl.querySelectorAll('input[name="dns[]"]');
        const hasValidDns = [...dnsInputs].some(el => el.value.trim() !== '');
        if (!hasValidDns) {
          showErrors([{message: 'Mode statique : au moins un serveur DNS requis.', step: 2, field: 'dns[]'}]);
          return false;
        }
      }
    }

    // Étape 3 : si mode custom, au moins 1 disque cible
    if (stepNum === 3) {
      const partitionMode = document.querySelector('input[name="partition_mode"]:checked')?.value;
      if (partitionMode === 'custom') {
        const targetChecked = stepEl.querySelectorAll('input[type="checkbox"][name="target_disks[]"]:checked');
        if (targetChecked.length === 0) {
          showErrors([{message: 'Sélectionnez au moins un disque cible.', step: 3, field: 'target_disks[]'}]);
          return false;
        }
      }
    }

    return true;
  };

  form.addEventListener('click', (e) => {
    const next = e.target.closest('.wizard-next');
    const prev = e.target.closest('.wizard-prev');
    if (next) {
      const currentStep = next.closest('.wizard-form-step');
      if (!validateStep(currentStep)) return;
      goToStep(parseInt(next.dataset.targetStep, 10));
    }
    if (prev) {
      goToStep(parseInt(prev.dataset.targetStep, 10));
    }
  });

  // Intercepte Entrée dans un input (sauf textarea) sur une étape intermédiaire :
  // déclenche "Suivant" au lieu de soumettre le form.
  form.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const target = e.target;
    if (target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON') return;
    const currentStepEl = target.closest('.wizard-form-step');
    if (!currentStepEl || currentStepEl.hidden) return;
    // Cherche le bouton "Suivant" de l'étape courante. Absent = dernière étape, on
    // laisse le submit naturel (Lancer l'installation).
    const nextBtn = currentStepEl.querySelector('.wizard-next');
    if (!nextBtn) return;
    e.preventDefault();
    if (!validateStep(currentStepEl)) return;
    goToStep(parseInt(nextBtn.dataset.targetStep, 10));
  });

  // --- Source de configuration : modèle, reset, ou garder l'existant -----
  const presetLoader = document.getElementById('preset-loader');
  const configSourceLabel = document.querySelector('[data-config-source-label]');
  function updateSourceBadge(text) {
    if (configSourceLabel) configSourceLabel.textContent = text;
  }
  const initialConfig = JSON.parse(document.getElementById('install-initial-config')?.textContent || '{}');
  const advancedDefaults = JSON.parse(document.getElementById('install-default-advanced')?.textContent || '{}');
  const initialSourceLabel = form.dataset.restoreLabel || 'Valeurs initiales';
  let appliedSourceLabel = configSourceLabel?.textContent || initialSourceLabel;
  let sourceSequence = 0;
  let applyingSource = false;
  function markChanged() {
    if (applyingSource) return;
    reviewReady = false;
    ++previewSequence;
    if (launchButton) launchButton.disabled = true;
    updateSourceBadge(appliedSourceLabel + ' · modifiée dans ce formulaire');
    updateOptionSummaries();
  }
  form.addEventListener('input', event => { if (event.target.name !== 'wake_now') markChanged(); });
  form.addEventListener('change', event => { if (event.target.name !== 'wake_now' && event.target !== presetLoader) markChanged(); });
  form.addEventListener('change', event => {
    if (event.target.matches('input[name="target_disks[]"]') && event.target.checked) {
      const identityConfirmation = form.querySelector('#disk-identity-selection-confirmed');
      if (identityConfirmation) identityConfirmation.value = '1';
    }
  });
  if (presetLoader) {
    presetLoader.addEventListener('change', async () => {
      const sequence = ++sourceSequence;
      const value = presetLoader.value;
      if (!value || value === '__reset__') {
        applyingSource = true;
        applyConfig(!value ? initialConfig : {}, {restore: !value});
        applyingSource = false;
        appliedSourceLabel = !value ? initialSourceLabel : 'Nouvelle configuration · valeurs par défaut';
        updateSourceBadge(appliedSourceLabel);
        markReviewStale();
        return;
      }
      const previousLabel = appliedSourceLabel;
      updateSourceBadge('Chargement du modèle…');
      try {
        const res = await fetch(`/presets/${encodeURIComponent(value)}.json`, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (sequence !== sourceSequence) return;
        applyingSource = true;
        applyConfig(data);
        applyingSource = false;
        appliedSourceLabel = 'Copie du modèle « ' + (presetLoader.options[presetLoader.selectedIndex]?.textContent || value).trim() + ' »';
        updateSourceBadge(appliedSourceLabel);
        markReviewStale();
      } catch (err) {
        applyingSource = false;
        if (sequence !== sourceSequence) return;
        updateSourceBadge(previousLabel + ' · chargement du modèle échoué, saisie inchangée');
        showErrors([{message: 'Échec du chargement du modèle : ' + err.message, step: 1, field: 'distro'}]);
      }
    });
    // A reference is a useful suggestion, never silently applied to a draft.
  }
  function markReviewStale() {
    showErrors([]);
    reviewReady = false; ++previewSequence;
    if (launchButton) launchButton.disabled = true;
    updateOptionSummaries();
  }

  function updateOptionSummaries() {
    let count = 0;
    form.querySelectorAll('[name^="adv["]').forEach(el => {
      const m = el.name.match(/^adv\[([^\]]+)\]\[([^\]]+)\]$/);
      if (!m) return;
      const expected = advancedDefaults[m[1]]?.[m[2]];
      const current = el.type === 'checkbox' ? el.checked : el.value;
      const base = el.type === 'checkbox' ? !!expected : (Array.isArray(expected) ? expected.join(',') : String(expected ?? ''));
      if (current !== base) count++;
    });
    const set = (selector, value) => { const el = form.querySelector(selector); if (el) el.textContent = value; };
    set('[data-advanced-count]', count ? `· ${count} réglage(s) personnalisé(s)` : '· valeurs par défaut');
    set('[data-raid-summary]', `· ${form.querySelectorAll('.raid-card').length} ensemble(s)`);
    set('[data-lvm-summary]', `· ${form.querySelectorAll('.vg-card').length} groupe(s), ${form.querySelectorAll('.lv-row').length} volume(s)`);
    const wipes = [...form.querySelectorAll('[name^="pre_wipe_mode["]:checked')].filter(el => el.value !== 'aucun');
    set('[data-wipe-summary]', wipes.length ? `· ${wipes.length} disque(s) à effacer` : '· aucun');
    if (wipes.length) form.querySelector('[data-wipe-summary]').closest('details').open = true;
  }

  // Un modèle peut provenir d'une machine dont les NICs sont dans un autre
  // ordre : la MAC PXE de la cible reste la meilleure valeur par défaut.
  function selectPrimaryNic(presetPrimaryMac = '', presetPrimaryIndex = 0, preservePrimary = false) {
    const network = form.querySelector('.network-multi-nic');
    if (!network) return;
    const cards = Array.from(network.querySelectorAll('.nic-card'));
    const findCard = mac => cards.find(card =>
      card.querySelector('input[name$="[match][mac]"]')?.value.toLowerCase() === String(mac || '').toLowerCase()
    );
    const card = (preservePrimary ? findCard(presetPrimaryMac) || cards[presetPrimaryIndex] : findCard(network.dataset.bootMac))
      || (presetPrimaryMac ? findCard(presetPrimaryMac) : null)
      || cards[presetPrimaryIndex]
      || cards[0];
    const radio = card?.querySelector('[data-nic-primary-radio]');
    if (radio) radio.checked = true;
  }

  function setInputValue(name, value) {
    const el = form.querySelector(`[name="${CSS.escape(name)}"]`);
    if (!el) return;
    const next = String(value ?? '');
    if (el.tagName === 'SELECT' && next !== '' && ![...el.options].some(option => option.value === next)) {
      el.add(new Option(next + ' (à vérifier)', next));
    }
    el.value = next;
  }
  function setRadioValue(name, value) {
    const el = form.querySelector(`input[type="radio"][name="${CSS.escape(name)}"][value="${CSS.escape(String(value))}"]`);
    if (el) {
      el.checked = true;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function diskRemapNoticeEl() { return document.getElementById('disk-remap-notice'); }
  function hideDiskRemapNotice() {
    const el = diskRemapNoticeEl();
    if (el) { el.hidden = true; el.textContent = ''; el.removeAttribute('style'); }
  }
  // Styles inline (pas de dépendance au CSS compilé Tailwind).
  function showDiskRemapNotice(kind, msg) {
    const el = diskRemapNoticeEl();
    if (!el) return;
    const palette = {
      status: ['rgba(13,110,253,0.08)', '#0d6efd'],
      warn:   ['rgba(245,158,11,0.10)', '#f59e0b'],
      error:  ['rgba(220,53,69,0.10)',  '#dc3545'],
    };
    const c = palette[kind] || palette.status;
    el.setAttribute('style',
      'display:block;margin:0 0 0.75rem;padding:0.6rem 0.8rem;border-radius:6px;font-size:0.9rem;'
      + 'background:' + c[0] + ';border-left:4px solid ' + c[1] + ';');
    el.textContent = msg;
    el.hidden = false;
  }
  function formatDiskRemapMessage(info) {
    const fmt = window.builderShared && window.builderShared.formatBytesGo;
    const parts = (info.mappings || []).map(m => {
      const size = (fmt && m.sizeBytes) ? ' (' + fmt(m.sizeBytes) + ')' : '';
      return m.from + ' → ' + m.to + size;
    });
    let msg = 'Le modèle ciblait un autre disque ; appliqué au disque détecté : ' + parts.join(', ') + '.';
    if (info.dropped && info.dropped.length) {
      msg += ' Disque(s) du modèle ignoré(s) (absent(s) de la machine) : ' + info.dropped.join(', ') + '.';
    }
    return msg;
  }

  function applyConfig(source, {restore = false} = {}) {
    const c = window.builderShared.normalizeInstallSource(source, advancedDefaults);
    const identityConfirmation = form.querySelector('#disk-identity-selection-confirmed');
    if (identityConfirmation) identityConfirmation.value = restore && c.disk_identity_selection_confirmed === false ? '0' : '1';
    setInputValue('disk_target_constraints_json', JSON.stringify(c.disk_target_constraints || {}));
    const constraintSummary = form.querySelector('[data-disk-constraints-summary]');
    if (constraintSummary) {
      const constraints = Object.entries(c.disk_target_constraints || {});
      constraintSummary.hidden = !constraints.length;
      constraintSummary.textContent = 'Disque imposé par le relevé : ' + constraints.map(([path, serial]) => path + ' · numéro de série ' + serial).join(', ') + '. Une série absente ou ambiguë bloque l’installation.';
    }
    hideDiskRemapNotice();
    ['admin_user_password', 'root_password'].forEach(name => setInputValue(name, ''));
    ['admin_user_pwhash', 'admin_user_password_cipher', 'root_password_hash', 'root_password_cipher'].forEach(name => {
      let el = form.querySelector(`[name="${name}_existing"]`);
      if (!el && c[name]) { el = document.createElement('input'); el.type = 'hidden'; el.name = name + '_existing'; form.appendChild(el); }
      if (el) el.value = c[name] || '';
    });
    form.querySelectorAll('.nic-card').forEach(card => {
      card.querySelectorAll('input:not([type="hidden"]):not([type="radio"]),select').forEach(el => {
        if (el.name.endsWith('[bond][mode]')) el.value = 'active-backup';
        else if (el.name.endsWith('[static][cidr]')) el.value = '24';
        else el.value = '';
      });
      const mode = card.querySelector('[data-nic-mode-radio][value="dhcp"]');
      if (mode) { mode.checked = true; mode.dispatchEvent(new Event('change')); }
    });

    window.builderShared.applyInstallVariant(form, c);

    // Étape 1 — cascade OS (nom) → codename → version_full (point release).
    // Ordre impératif : chaque change repeuple le select dépendant AVANT d'y poser
    // la valeur du preset (le select codename est peuplé au change de distro, le
    // select version_full au change de codename).
    if (c.distro) {
      setInputValue('distro', c.distro);
      const distroEl = form.querySelector('[name="distro"]');
      if (distroEl) distroEl.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (c.codename) {
      setInputValue('codename', c.codename);
      const cnEl = form.querySelector('[name="codename"]');
      if (cnEl) cnEl.dispatchEvent(new Event('change', { bubbles: true }));
    }
    setInputValue('version_full', c.version_full);

    // Nom d'hôte : 3 modes mutuellement exclusifs définis sur le preset.
    //  - hostname_random=1 → utiliser le nom auto-généré (style Docker,
    //    déterministe à la MAC) déjà calculé côté serveur dans le hidden
    //    [name="hostname_default"]. L'admin peut toujours overrider.
    //  - hostname_pattern non vide → pré-remplir avec cette valeur statique.
    //  - sinon → vider le champ : à l'install, hostname sera NULL → d-i
    //    prendra ce que le DHCP envoie (ou "debian" par défaut).
    const hostnameEl = form.querySelector('[name="hostname"]');
    if (hostnameEl) {
      if (restore) {
        hostnameEl.value = c.hostname || '';
      } else if (c.hostname_random) {
        const def = form.querySelector('[name="hostname_default"]');
        hostnameEl.value = def ? def.value : '';
      } else if (c.hostname_pattern) {
        hostnameEl.value = c.hostname_pattern;
      } else {
        hostnameEl.value = '';
      }
    }
    // Domaine : le preset prévaut lorsqu’il fournit explicitement une valeur.
    setInputValue('domain', c.domain);

    // Étape 2 — admin user
    if (c.admin_user_login !== undefined) setInputValue('admin_user_login', c.admin_user_login);
    if (c.admin_user_fullname !== undefined) setInputValue('admin_user_fullname', c.admin_user_fullname);
    if (c.admin_user_groups !== undefined) setInputValue('admin_user_groups', c.admin_user_groups);
    if (c.admin_user_pubkeys !== undefined) setInputValue('admin_user_pubkeys', c.admin_user_pubkeys);
    if (c.root_login_mode) setRadioValue('root_login_mode', c.root_login_mode);

    // Étape 2 — réseau legacy single-NIC (cf. _install/network-fields.php branche
    // `if (empty($nicsInventory))`). Couvre les presets sans plan multi-NIC.
    if (c.network_mode) setRadioValue('network_mode', c.network_mode);
    if (c.static_ip !== undefined) setInputValue('static_ip', c.static_ip);
    if (c.static_cidr !== undefined && c.static_cidr !== null) setInputValue('static_cidr', c.static_cidr);
    if (c.static_gateway !== undefined) setInputValue('static_gateway', c.static_gateway);
    if (Array.isArray(c.static_dns)) {
      window.builderShared.rebuildDnsList(document.getElementById('dns-list'), c.static_dns);
    }

    if (!c.network?.interfaces?.length && form.querySelector('.nic-card')) {
      const cards = [...form.querySelectorAll('.nic-card')];
      const bootMac = form.querySelector('.network-multi-nic')?.dataset.bootMac;
      const primary = Math.max(0, cards.findIndex(card => card.querySelector('[name$="[match][mac]"]')?.value === bootMac));
      c.network = {primary, interfaces: cards.map((card, i) => ({
        match: {mac: card.querySelector('[name$="[match][mac]"]')?.value},
        mode: i === primary ? c.network_mode : 'dhcp',
        static: i === primary ? {ip:c.static_ip, cidr:c.static_cidr, gateway:c.static_gateway, dns:c.static_dns} : null,
      }))};
    }
    // Étape 2 — réseau multi-NIC (cf. _install/network-fields.php branche `else`).
    // Pour chaque interface du preset, on retrouve la card NIC correspondante :
    //   1) par MAC en priorité (preset = inventaire d'origine → match exact si la
    //      machine cible a les mêmes NICs)
    //   2) par index en fallback (preset générique ou MAC du preset absente de la
    //      machine cible)
    // On clique sur le radio mode pour déclencher le listener change existant
    // (toggle visibilité du bloc static), puis on peuple les inputs static.
    if (c.network && Array.isArray(c.network.interfaces)) {
      const allCards = Array.from(form.querySelectorAll('.nic-card'));
      c.network.interfaces.forEach((iface, idx) => {
        let card = null;
        const ifMac = (iface.match && iface.match.mac) ? String(iface.match.mac).toLowerCase() : '';
        if (ifMac) {
          // Match par MAC via le hidden network[interfaces][N][match][mac]
          const hidden = form.querySelector(
            'input[type="hidden"][name$="[match][mac]"][value="' + CSS.escape(ifMac) + '"]'
          );
          if (hidden) card = hidden.closest('.nic-card');
        }
        if (!card && iface.match_by === 'primary') card = allCards.find(item => item.querySelector('[name$="[match][mac]"]')?.value === form.querySelector('.network-multi-nic')?.dataset.bootMac);
        if (!card) card = allCards[idx] || null;
        if (!card) return;

        ['vlan][id', 'bond][role', 'bond][name', 'bond][mode'].forEach(path => {
          const [section, key] = path.split('][');
          const el = card.querySelector(`[name$="[${section}][${key}]"]`);
          if (el) el.value = iface[section]?.[key] ?? (key === 'mode' ? 'active-backup' : '');
        });
        const mode = iface.mode || 'dhcp';
        const modeRadio = card.querySelector('[data-nic-mode-radio][value="' + CSS.escape(mode) + '"]');
        if (modeRadio) modeRadio.click(); // déclenche le listener qui toggle [data-nic-static-block]

        if (mode === 'static' && iface.static) {
          const setCardInput = (suffix, value) => {
            if (value === undefined || value === null) return;
            const el = card.querySelector('[name$="' + suffix + '"]');
            if (el) el.value = value;
          };
          setCardInput('[static][ip]',      iface.static.ip);
          setCardInput('[static][cidr]',    iface.static.cidr);
          setCardInput('[static][gateway]', iface.static.gateway);
          if (Array.isArray(iface.static.dns)) {
            // La card rend 2 DNS par défaut, mais un preset peut en porter plus
            // (ajout libre). On fait grandir la liste depuis le template de la
            // card pour accueillir tous les DNS, puis on réapplique le plancher.
            const dnsListEl = card.querySelector('[data-nic-dns-list]');
            const dnsTpl    = card.querySelector('template[data-nic-dns-tpl]');
            if (dnsListEl && dnsTpl) {
              let rows = dnsListEl.querySelectorAll('.dns-row').length;
              while (rows < iface.static.dns.length) {
                dnsListEl.appendChild(dnsTpl.content.firstElementChild.cloneNode(true));
                rows++;
              }
            }
            const dnsInputs = card.querySelectorAll('input[name$="[static][dns][]"]');
            iface.static.dns.forEach((v, i) => {
              if (dnsInputs[i]) dnsInputs[i].value = v;
            });
            // Si le preset a moins de DNS que d'inputs rendus, on vide le reste
            for (let i = iface.static.dns.length; i < dnsInputs.length; i++) {
              dnsInputs[i].value = '';
            }
            if (dnsListEl && dnsListEl._refreshNicDns) dnsListEl._refreshNicDns();
          }
        }
      });
      const primaryIndex = Number.isInteger(c.network.primary) ? c.network.primary : 0;
      selectPrimaryNic(c.network.interfaces[primaryIndex]?.match?.mac || '', primaryIndex, restore);
    }

    // Étape 2 — Ansible avancé
    if (c.ansible_role !== undefined) setInputValue('ansible_role', c.ansible_role);
    if (c.ansible_extra_vars !== undefined) setInputValue('ansible_extra_vars', c.ansible_extra_vars);

    // Étape 3 — wipe per-disk
    // pre_wipe_mode peut être :
    //   - un dict {"/dev/sda": "partial", ...} (format nouveau)
    //   - un scalar string 'partial'|'full'|'aucun' (legacy → appliqué à tous les disques)
    if (c.pre_wipe_mode !== undefined && c.pre_wipe_mode !== null) {
      const wipeMap = (typeof c.pre_wipe_mode === 'object' && !Array.isArray(c.pre_wipe_mode))
        ? c.pre_wipe_mode
        : null;
      const wipeScalar = (typeof c.pre_wipe_mode === 'string') ? c.pre_wipe_mode : null;

      form.querySelectorAll('input[type="radio"][name^="pre_wipe_mode["]').forEach(r => {
        const m = r.name.match(/^pre_wipe_mode\[(.+)\]$/);
        if (!m) return;
        const disk = m[1];
        let targetMode;
        if (wipeMap) {
          targetMode = wipeMap[disk] ?? 'aucun';
        } else if (wipeScalar) {
          // Scalar legacy → même mode pour tous les disques
          targetMode = wipeScalar;
        } else {
          return;
        }
        if (r.value === targetMode) r.checked = true;
      });
    }

    // Étape 3 — partitionnement (plan arborescent v2)
    // Retient le disque cible réel quand remapPlanToInventory a dû substituer
    // celui du preset (ex /dev/sda → /dev/sdb) : sert plus bas à corriger
    // adv[bootloader][bootdev], sinon GRUB serait pointé sur le disque du
    // preset au lieu du disque réellement partitionné (échec install en réel
    // sur board : "Installer le programme de démarrage GRUB").
    let remappedBootDisk = null;
    if (c.partition_mode) setRadioValue('partition_mode', c.partition_mode);
    if (!c.partition_custom_json) window.builderShared.rebuildPartitioningPlan({target_disks: [], disks: [], raid_arrays: [], vgs: [], logical_volumes: []}, document);
    if (c.partition_custom_json) {
      let plan = c.partition_custom_json;
      if (typeof plan === 'string') {
        try { plan = JSON.parse(plan); } catch (_) { plan = null; }
      }
      if (plan && typeof plan === 'object' && !Array.isArray(plan)) {
        // Remap des disques du preset vers l'inventaire de la machine (ex le
        // preset cible /dev/sda mais la machine n'a que /dev/sdb). Le plan remappé
        // est rebuild → la card devient le disque réel avec sa vraie taille.
        let toApply = plan;
        const savedIdentities = plan.target_disk_identities && typeof plan.target_disk_identities === 'object'
          ? plan.target_disk_identities : {};
        if (restore && Array.isArray(plan.target_disks) && plan.target_disks.length
            && plan.target_disks.some(path => !savedIdentities[path] || !savedIdentities[path].value)) {
          // Old per-machine snapshots had only /dev paths. Require a fresh,
          // explicit checkbox selection while keeping the saved recipe visible.
          toApply = Object.assign({}, plan, {target_disks: []});
          showDiskRemapNotice('error',
            'Cette configuration ne contient pas de numéro de série vérifiable. Vérifiez la découverte récente puis sélectionnez explicitement les disques cibles.');
        }
        if (!restore && window.builderShared.remapPlanToInventory) {
          const res = window.builderShared.remapPlanToInventory(plan);
          if (res.info && res.info.fatal) {
            showDiskRemapNotice('error',
              "Le modèle nécessite au moins un disque mais aucun n'a été détecté sur cette machine.");
            toApply = Object.assign({}, plan, { target_disks: [], disks: [] });
          } else {
            toApply = res.plan;
            if (res.info) showDiskRemapNotice(res.info.dropped.length ? 'warn' : 'status',
              formatDiskRemapMessage(res.info));
            else hideDiskRemapNotice();
          }
        }
        if (Array.isArray(toApply.target_disks) && toApply.target_disks.length === 1) {
          remappedBootDisk = toApply.target_disks[0];
        }
        window.builderShared.rebuildPartitioningPlan(toApply, document);
      }
    }

    const raidDetails = form.querySelector('[data-storage-raid]');
    const lvmDetails = form.querySelector('[data-storage-lvm]');
    if (raidDetails) raidDetails.open = !!form.querySelector('.raid-card');
    if (lvmDetails) lvmDetails.open = !!form.querySelector('.vg-card');
    syncRootPasswordVisibility();

    // Options d’installation — paramètres avancés du preseed (sections scalaires).
    if (c.advanced && typeof c.advanced === 'object') {
      ['localization', 'mirror', 'timezone', 'packages', 'apt', 'bootloader', 'finish'].forEach(section => {
        const sub = c.advanced[section];
        if (!sub || typeof sub !== 'object') return;
        Object.entries(sub).forEach(([key, val]) => {
          const el = form.querySelector(`[name="adv[${section}][${key}]"]`);
          if (!el) return;
          if (el.type === 'checkbox') {
            el.checked = !!val;
          } else if (Array.isArray(val)) {
            el.value = val.join(',');
          } else if (val === null || val === undefined) {
            el.value = '';
          } else {
            el.value = String(val);
          }
        });
      });

      // Le disque de boot du preset vient d'être réappliqué ci-dessus (il peut
      // ne pas correspondre au disque réel) ; si le partitionnement a été
      // remappé sur un disque unique détecté, il prime toujours sur la valeur
      // du preset.
      if (remappedBootDisk) {
        const bootdevEl = form.querySelector('[name="adv[bootloader][bootdev]"]');
        if (bootdevEl) bootdevEl.value = remappedBootDisk;
      }

      // post_install : sous-rows dynamiques (remote_pubkey_urls).
      // Sans ce rebuild, les URLs du preset ne sont pas affichées dans le wizard
      // → l'admin valide sans les voir → POST vide → URLs perdues dans le snapshot
      // → preseed n'émet pas les curl → pas de clés SSH ajoutées sur la machine.
      const pi = c.advanced.post_install;
      if (pi && typeof pi === 'object') {
        rebuildPostInstallRows('pubkey-urls-rows', 'remote_pubkey_urls', pi.remote_pubkey_urls, ['url', 'label'], [
          { label: 'URL HTTPS', type: 'url', placeholder: 'https://sshid.io/USER' },
          { label: 'Libellé <span class="muted">(optionnel)</span>', type: 'text', placeholder: 'sshid.io USER' },
        ]);
      }
    }
  }

  // Rebuild les rows dynamiques du bloc post_install à partir d'un array d'entries.
  // Conserve au moins 1 row vide (UX : l'admin peut saisir directement).
  function rebuildPostInstallRows(containerId, fieldName, entries, keys) {
    const c = document.getElementById(containerId);
    const template = document.getElementById('pubkey-url-row-tpl');
    if (!c || !template) return;
    c.innerHTML = '';
    const rows = Array.isArray(entries) && entries.length ? entries : [{}];
    rows.forEach((entry, i) => {
      const wrapper = document.createElement('div');
      wrapper.innerHTML = template.innerHTML.replace(/__IDX__/g, String(i));
      const row = wrapper.firstElementChild;
      keys.forEach(key => {
        const el = row.querySelector(`[name$="[${key}]"]`);
        if (el) el.value = entry?.[key] ?? '';
      });
      c.appendChild(row);
    });
  }
  updateOptionSummaries();
  goToStep(1);
  const initialErrors = JSON.parse(document.getElementById('install-validation-errors')?.textContent || '[]');
  if (initialErrors.length) showErrors(initialErrors);

  document.addEventListener('pxe:install-step', event => {
    const detail = event.detail || {};
    if (detail.field) revealField(detail.field, detail.step);
    else goToStep(Number(detail.step) || 1);
  });

  // Confirm "EFFACER" au submit si au moins un disque a un mode != 'aucun'.
  // Collecte les radios per-disk cochées avec valeur non-aucun.
  form.addEventListener('submit', (ev) => {
    if (!reviewReady) { ev.preventDefault(); goToStep(4); return; }
    const entries = [];
    form.querySelectorAll('input[type="radio"][name^="pre_wipe_mode["]:checked').forEach(r => {
      if (!r.value || r.value === 'aucun') return;
      const m = r.name.match(/^pre_wipe_mode\[(.+)\]$/);
      if (m) entries.push({ disk: m[1], mode: r.value });
    });
    if (entries.length === 0) return;

    const modeLabel = m => m === 'partial' ? 'Partiel (~14 Go)' : 'Complet';
    const summary = entries.map(e => '  ' + e.disk + ' → ' + modeLabel(e.mode)).join('\n');
    const typed = prompt(
      'Attention — Effacement avant installation :\n\n' + summary + '\n\n' +
      'Les données SERONT PERDUES.\n' +
      'L\'installation s\'enchaînera automatiquement à la fin du wipe.\n\n' +
      'Saisissez "EFFACER" (en majuscules) pour confirmer :'
    );
    if (typed !== 'EFFACER') {
      ev.preventDefault();
    }
  });
})();
