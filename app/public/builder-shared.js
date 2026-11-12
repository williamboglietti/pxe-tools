/**
 * Logique partagée v2 (multi-disques + LVM multi-VG, RAID stub Phase B) :
 *   - add/remove DNS rows
 *   - toggle partition_mode default/custom
 *   - toggle target_disks checkboxes → show/hide disk cards
 *   - add/remove partition rows per disk + auto-ID (sda1, sda2, sdb1, ...)
 *   - fs=lvm-pv : grey out mount/reserved/options
 *   - add/remove VG cards
 *   - add/remove LV rows per VG
 *   - sync VG name → vg field of all LVs in that VG
 *   - rebuild PV checkbox lists per VG quand partitions changent
 *   - reindex hidden form names après suppression
 *
 * Expose `window.builderShared` :
 *   - initBuilders(root) — branche tous les listeners sur un sous-arbre
 */
(() => {
  'use strict';

  const EXT_FS = ['ext2', 'ext3', 'ext4'];

  // ====== Helpers généraux ===================================================

  function readMachineHw() {
    try {
      const json = document.querySelector('script#machine-hw')?.textContent;
      return json ? JSON.parse(json) : null;
    } catch { return null; }
  }

  function basename(path) {
    return (path || '').replace(/^.*\//, '');
  }

  function reindexAttrs(scopeEl, regex, replacer) {
    scopeEl.querySelectorAll('[name],[for],[id]').forEach(el => {
      ['name', 'for', 'id'].forEach(attr => {
        const v = el.getAttribute(attr);
        if (v && regex.test(v)) el.setAttribute(attr, v.replace(regex, replacer));
      });
    });
  }

  // ====== DNS ================================================================
  // Premier row : input required + btn trash disabled (tooltip).
  // Deuxième row : btn trash actif → confirm() warning redondance.
  // Rows ≥3 : suppression directe sans dialog.
  // refreshRoles() reconfigure tous les rows à chaque add/remove pour préserver
  // ces règles quand l'ordre change.

  function bindDns(root) {
    const dnsList = root.querySelector('#dns-list');
    const dnsAdd  = root.querySelector('#dns-add');
    const dnsTpl  = root.querySelector('#dns-row-tpl');
    if (!dnsList) return;

    function refreshRoles() {
      const rows = [...dnsList.querySelectorAll('.dns-row')];
      rows.forEach((row, idx) => {
        const inp = row.querySelector('input[name="dns[]"]');
        const btn = row.querySelector('.btn-remove');
        if (!inp || !btn) return;
        inp.required = false;
        btn.disabled = false;
        btn.removeAttribute('aria-disabled');
        btn.removeAttribute('title');
        delete btn.dataset.dnsWarnRedundancy;
        delete btn.dataset.dnsRole;
        if (idx === 0) {
          inp.required = true;
          inp.placeholder = dnsList.dataset.defaultPrimary || inp.placeholder;
          btn.disabled = true;
          btn.setAttribute('aria-disabled', 'true');
          btn.title = 'Au moins 1 serveur DNS valide est requis';
          btn.dataset.dnsRole = 'primary';
        } else if (idx === 1) {
          inp.placeholder = dnsList.dataset.defaultSecondary || inp.placeholder;
          btn.dataset.dnsWarnRedundancy = '';
          btn.dataset.dnsRole = 'secondary';
        } else {
          inp.placeholder = '192.0.2.53';
        }
      });
    }

    if (dnsAdd && dnsTpl) {
      dnsAdd.addEventListener('click', () => {
        dnsList.appendChild(dnsTpl.content.firstElementChild.cloneNode(true));
        refreshRoles();
      });
    }

    dnsList.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-remove');
      if (!btn || btn.disabled) return;
      if ('dnsWarnRedundancy' in btn.dataset) {
        const ok = window.confirm(
          'Il est recommandé de conserver 2 serveurs DNS pour la redondance.\n\n' +
          'Si le premier devient indisponible, le second prendra le relais.\n\n' +
          'Supprimer quand même ce DNS secondaire ?'
        );
        if (!ok) return;
      }
      btn.closest('.dns-row')?.remove();
      refreshRoles();
    });

    refreshRoles();
  }

  // ====== DNS multi-NIC ======================================================
  // Une liste DNS add/remove par carte NIC (mode statique). Plancher dur de 2
  // (redondance) : quand il ne reste que 2 rows, tous les trash sont désactivés.
  // Distinct de bindDns() (legacy single-NIC, min 1 + warn) : scope par carte
  // via [data-nic-dns-list] / [data-nic-dns-add] / template[data-nic-dns-tpl].
  function bindNicDns(root) {
    root.querySelectorAll('[data-nic-dns-list]').forEach((list) => {
      const card   = list.closest('.nic-card') || root;
      const addBtn = card.querySelector('[data-nic-dns-add]');
      const tpl    = card.querySelector('template[data-nic-dns-tpl]');

      function refresh() {
        const rows  = [...list.querySelectorAll('.dns-row')];
        const atMin = rows.length <= 2;
        rows.forEach((row, idx) => {
          const inp = row.querySelector('input');
          const btn = row.querySelector('.btn-remove');
          if (inp) inp.placeholder = idx === 0
            ? (list.dataset.defaultPrimary || inp.placeholder)
            : (idx === 1 ? (list.dataset.defaultSecondary || inp.placeholder) : '192.0.2.53');
          if (!btn) return;
          btn.disabled = atMin;
          if (atMin) {
            btn.setAttribute('aria-disabled', 'true');
            btn.title = '2 serveurs DNS minimum (redondance)';
          } else {
            btn.removeAttribute('aria-disabled');
            btn.removeAttribute('title');
          }
        });
      }

      if (addBtn && tpl) {
        addBtn.addEventListener('click', () => {
          list.appendChild(tpl.content.firstElementChild.cloneNode(true));
          refresh();
        });
      }
      list.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-remove');
        if (!btn || btn.disabled) return;
        btn.closest('.dns-row')?.remove();
        refresh();
      });
      // Exposé pour applyConfig (install-wizard) : après avoir fait grandir la
      // liste depuis le template, réappliquer placeholders + plancher de 2.
      list._refreshNicDns = refresh;
      refresh();
    });
  }

  // ====== Partition mode + Network mode (inchangés) ==========================

  function bindPartitionMode(root) {
    const builder = root.querySelector('#partitions-builder');
    if (!builder) return;
    const radios = root.querySelectorAll('input[name="partition_mode"]');
    const sync = () => {
      const mode = root.querySelector('input[name="partition_mode"]:checked')?.value;
      builder.hidden = (mode !== 'custom');
    };
    radios.forEach(r => r.addEventListener('change', sync));
    sync();
  }

  function bindNetworkMode(root) {
    const staticFields = root.querySelector('#static-fields');
    if (!staticFields) return;
    const radios = root.querySelectorAll('input[name="network_mode"]');
    const sync = () => {
      const mode = root.querySelector('input[name="network_mode"]:checked')?.value;
      staticFields.hidden = (mode !== 'static');
    };
    radios.forEach(r => r.addEventListener('change', sync));
    sync();
  }

  // ====== Section 1 — Disques cibles =========================================

  function bindTargetDisks(root) {
    const container = root.querySelector('#disks-partitions-container');
    const tpl = root.querySelector('#disk-card-tpl');
    if (!container || !tpl) return;

    // Cache des disk-cards retirées par décochage : permet de restaurer la
    // configuration complète (partitions, fs, mounts, fill, etc.) si l'utilisateur
    // recoche le même disque. Sans ce cache, recocher recréait une carte vide
    // depuis le template et perdait toute la saisie.
    const cardCacheByPath = new Map();

    // Délégation : couvre les checkboxes SSR + celles ajoutées via "+ Ajouter un disque".
    root.addEventListener('change', (e) => {
      const cb = e.target;
      if (!cb.matches?.('input[type="checkbox"][name="target_disks[]"]')) return;
      const path = cb.value;
      if (!path) return;
      const existing = container.querySelector(`.disk-card[data-disk-path="${CSS.escape(path)}"]`);
      if (cb.checked) {
        if (!existing) {
          const cached = cardCacheByPath.get(path);
          if (cached) {
            container.appendChild(cached);
            cardCacheByPath.delete(path);
            reindexDisks(container);
          } else {
            appendDiskCard(container, tpl, path);
          }
        }
      } else {
        if (existing) {
          cardCacheByPath.set(path, snapshotDiskCard(existing));
          existing.remove();
          reindexDisks(container);
        }
      }
      syncPreWipeTable(root);
      renumberAllPartitionIds(root);
      rebuildAllPvCheckboxes(root);
      rebuildAllRaidCheckboxes(root);
      renderAllDiskGauges(root);
      const hint = root.querySelector('.disk-empty-hint');
      if (hint) hint.hidden = container.querySelector('.disk-card') !== null;
    });

    // Input "Capacité (Go)" par disque cible — sert d'override pour
    // diskMetaByPath quand l'inventory ne fournit pas size_bytes (preset
    // générique) ou pour preview override.
    root.addEventListener('input', (e) => {
      if (e.target.matches?.('input.target-disk-capacity-input')) {
        renderAllDiskGauges(root);
      }
    });

    // Input "Chemin" (preset générique : path éditable) → met à jour la value
    // de la checkbox target_disks[] adjacente + data-disk-path + name de l'input
    // capacité, et propage le rename dans tout le builder.
    root.addEventListener('input', (e) => {
      const pathInput = e.target;
      if (!pathInput.matches?.('input.target-disk-path-input')) return;
      const row = pathInput.closest('.target-disk-row');
      if (!row) return;
      const oldPath = row.dataset.diskPath || '';
      const newPath = pathInput.value.trim();
      if (newPath === oldPath) return;
      row.dataset.diskPath = newPath;
      const cb = row.querySelector('input[type="checkbox"][name="target_disks[]"]');
      if (cb) cb.value = newPath;
      const capInput = row.querySelector('input.target-disk-capacity-input');
      if (capInput) {
        capInput.dataset.diskPath = newPath;
        capInput.name = newPath ? `disk_capacity_gb[${newPath}]` : 'disk_capacity_gb[]';
      }
      // Renomme la disk-card associée si elle existe (et son input path hidden)
      const card = container.querySelector(`.disk-card[data-disk-path="${CSS.escape(oldPath)}"]`);
      if (card) {
        card.dataset.diskPath = newPath;
        const pi = card.querySelector('input[name$="[path]"]');
        if (pi) pi.value = newPath;
      }
      syncPreWipeTable(root);
      renderAllDiskGauges(root);
    });

    // Bouton "+ Ajouter un disque" (preset générique uniquement)
    const btnAddDisk = root.querySelector('#add-target-disk');
    if (btnAddDisk) {
      btnAddDisk.addEventListener('click', () => {
        const tbody = root.querySelector('#target-disks-tbody');
        const tplRow = root.querySelector('#target-disk-row-tpl');
        if (!tbody || !tplRow) return;
        const idx = tbody.querySelectorAll('tr.target-disk-row').length;
        const html = tplRow.innerHTML.replace(/__I__/g, String(idx));
        const tmp = document.createElement('tbody');
        tmp.innerHTML = html.trim();
        const tr = tmp.firstElementChild;
        tbody.appendChild(tr);
        tr.querySelector('input.target-disk-path-input')?.focus();
      });
    }

    // Bouton "×" — supprime une ligne disque cible (et la disk-card associée)
    root.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-target-disk-remove');
      if (!btn) return;
      const row = btn.closest('.target-disk-row');
      if (!row) return;
      const path = row.dataset.diskPath || '';
      row.remove();
      if (path) {
        const card = container.querySelector(`.disk-card[data-disk-path="${CSS.escape(path)}"]`);
        if (card) {
          card.remove();
          reindexDisks(container);
        }
      }
      syncPreWipeTable(root);
      renumberAllPartitionIds(root);
      rebuildAllPvCheckboxes(root);
      rebuildAllRaidCheckboxes(root);
      renderAllDiskGauges(root);
    });
  }

  /**
   * Synchronise la table pre-wipe (par disque) avec les disques cibles
   * actuellement cochés dans #target-disks-tbody. Préserve les modes
   * sélectionnés via snapshot, et rebuild les rows manquantes/orphelines.
   */
  function syncPreWipeTable(root) {
    const tbody = root.querySelector('#pre-wipe-per-disk-tbody');
    if (!tbody) return;
    const table = tbody.closest('table');
    const hint  = root.querySelector('#pre-wipe-empty-hint');

    // Disques inclus = checkboxes target_disks[] cochées
    const includedPaths = [...root.querySelectorAll('input[type="checkbox"][name="target_disks[]"]:checked')]
      .map(cb => cb.value)
      .filter(Boolean);

    // Snapshot modes courants pour préserver la sélection
    const snapshot = {};
    tbody.querySelectorAll('input[type="radio"][name^="pre_wipe_mode["]:checked').forEach(r => {
      const m = r.name.match(/^pre_wipe_mode\[(.+)\]$/);
      if (m) snapshot[m[1]] = r.value;
    });

    tbody.innerHTML = '';
    includedPaths.forEach(dkey => {
      const mode = snapshot[dkey] || 'aucun';
      const tr = document.createElement('tr');
      tr.dataset.diskKey = dkey;
      const td1 = document.createElement('td');
      const code = document.createElement('code');
      code.textContent = dkey;
      td1.appendChild(code);
      tr.appendChild(td1);
      ['aucun', 'partial', 'full'].forEach(value => {
        const td = document.createElement('td');
        td.style.textAlign = 'center';
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = `pre_wipe_mode[${dkey}]`;
        input.value = value;
        input.setAttribute('aria-label', `${value === 'aucun' ? 'Aucun pré-effacement' : 'Pré-effacement ' + value} ${dkey}`);
        if (mode === value) input.checked = true;
        td.appendChild(input);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    if (table) table.hidden = includedPaths.length === 0;
    if (hint)  hint.hidden  = includedPaths.length > 0;
  }

  /**
   * Sérialise une disk-card avec ses valeurs courantes (form state) pour
   * pouvoir la restaurer plus tard. cloneNode(true) ne préserve PAS les
   * `value` courantes d'<input>/<textarea>/<select> — il copie seulement
   * les attributs HTML. On synchronise donc les attributs avec les
   * properties JS avant de cloner.
   */
  function snapshotDiskCard(card) {
    card.querySelectorAll('input').forEach(el => {
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) el.setAttribute('checked', '');
        else el.removeAttribute('checked');
      } else {
        el.setAttribute('value', el.value);
      }
    });
    card.querySelectorAll('select').forEach(sel => {
      [...sel.options].forEach(o => {
        if (o.selected) o.setAttribute('selected', '');
        else o.removeAttribute('selected');
      });
    });
    card.querySelectorAll('textarea').forEach(el => {
      el.textContent = el.value;
    });
    return card.cloneNode(true);
  }

  function appendDiskCard(container, tpl, path) {
    const idx = container.querySelectorAll('.disk-card').length;
    const html = tpl.innerHTML.replace(/__DI__/g, String(idx));
    const tmp = document.createElement('div');
    tmp.innerHTML = html.trim();
    const card = tmp.firstElementChild;
    card.dataset.diskPath = path;
    card.dataset.diskIdx = String(idx);
    const display = card.querySelector('.disk-card-path');
    if (display) display.textContent = path;
    const pathInput = card.querySelector('.disk-path-input');
    if (pathInput) pathInput.value = path;
    container.appendChild(card);
    return card;
  }

  function reindexDisks(container) {
    [...container.querySelectorAll('.disk-card')].forEach((card, newIdx) => {
      const oldIdx = card.dataset.diskIdx;
      if (oldIdx === String(newIdx)) return;
      card.dataset.diskIdx = String(newIdx);
      // Remap : disks[oldIdx][...] → disks[newIdx][...]
      reindexAttrs(card, new RegExp(`disks\\[${oldIdx}\\]`, 'g'), `disks[${newIdx}]`);
      card.querySelectorAll('[data-disk-idx]').forEach(el => {
        if (el.dataset.diskIdx === oldIdx) el.dataset.diskIdx = String(newIdx);
      });
    });
  }

  // ====== Section 2 — Partitions par disque ==================================

  function bindPartitionRowActions(root) {
    // Add partition (button per disk-card) — pré-remplit size_mb avec
    // l'espace libre du disque si capacité connue (suggestion utile :
    // l'utilisateur ajuste à la baisse si besoin).
    root.addEventListener('click', (e) => {
      const btnAdd = e.target.closest('.btn-add-partition');
      if (btnAdd) {
        if (btnAdd.disabled) return;
        const card = btnAdd.closest('.disk-card');
        if (!card) return;
        const tpl = root.querySelector('#partition-row-tpl');
        const optsTpl = root.querySelector('#partition-options-row-tpl');
        if (!tpl) return;
        const freeMb = computeDiskFreeMb(card);
        const row = appendPartitionRow(card, tpl, optsTpl);
        if (freeMb !== null && freeMb > 0) {
          const sizeInput = row.querySelector('input[name$="[size_mb]"]');
          if (sizeInput) {
            sizeInput.dataset.mbSi = String(freeMb);
          }
        }
        applyUnitToInputs(row);
        renumberAllPartitionIds(root);
        rebuildAllPvCheckboxes(root);
        rebuildAllRaidCheckboxes(root);
        renderAllDiskGauges(root);
        return;
      }

      const btnRemove = e.target.closest('.btn-remove');
      if (btnRemove) {
        const partRow = btnRemove.closest('.partition-row');
        const lvRow   = btnRemove.closest('.lv-row');
        const vgCard  = btnRemove.closest('.vg-card');
        const raidCard = btnRemove.closest('.raid-card');
        if (partRow) {
          const tbody = partRow.parentElement;
          const next = partRow.nextElementSibling;
          if (next && next.classList.contains('partition-options-row')) next.remove();
          partRow.remove();
          reindexPartitionRows(tbody);
          renumberAllPartitionIds(root);
          rebuildAllPvCheckboxes(root);
          rebuildAllRaidCheckboxes(root);
          renderAllDiskGauges(root);
          return;
        }
        if (lvRow) {
          const tbody = lvRow.parentElement;
          const next = lvRow.nextElementSibling;
          if (next && next.classList.contains('partition-options-row')) next.remove();
          lvRow.remove();
          reindexLvRowsGlobal(root);
          return;
        }
        if (raidCard && btnRemove.closest('.raid-header')) {
          raidCard.remove();
          reindexRaidArrays(root.querySelector('#raid-arrays-container'));
          rebuildAllPvCheckboxes(root);
          return;
        }
        if (vgCard && btnRemove.closest('.vg-header')) {
          // Suppression d'un VG entier
          vgCard.remove();
          reindexVgs(root.querySelector('#vgs-container'));
          reindexLvRowsGlobal(root);
          rebuildAllPvCheckboxes(root);
          return;
        }
      }

      const btnOpts = e.target.closest('.btn-options-toggle');
      if (btnOpts) {
        const row = btnOpts.closest('.partition-row, .lv-row');
        if (!row) return;
        const optsRow = row.nextElementSibling;
        if (optsRow && optsRow.classList.contains('partition-options-row')) {
          optsRow.hidden = !optsRow.hidden;
          btnOpts.classList.toggle('active', !optsRow.hidden);
        }
      }
    });

    // Change : fs select, fill checkbox, vg name (sync LV vg fields)
    root.addEventListener('change', (e) => {
      const el = e.target;
      const partRow = el.closest?.('.partition-row');
      const lvRow   = el.closest?.('.lv-row');
      const raidCard = el.closest?.('.raid-card');

      if (el.matches?.('select[name$="[fs]"]')) {
        const row = partRow || lvRow;
        if (row) {
          syncReservedAndMountFor(row);
          syncOptionsButton(row);
        }
        if (partRow) {
          rebuildAllPvCheckboxes(root);
          rebuildAllRaidCheckboxes(root);
          renderAllDiskGauges(root);
        }
      }

      if (el.type === 'checkbox' && el.name?.endsWith('[fill]')) {
        const row = partRow || lvRow;
        if (row) {
          const sizeInput = row.querySelector('input[name$="[size_mb]"]');
          if (sizeInput) {
            sizeInput.disabled = el.checked;
            if (el.checked) {
              // Mémorise la value courante en placeholder. Si la capacité
              // disque/VG est connue, renderAllDiskGauges l'écrasera par
              // la part fill calculée. Sinon (preset générique sans machine
              // de réf), ce placeholder reste comme repère affiché.
              const currentMbSi = parseInt(sizeInput.dataset.mbSi || '0', 10) || 0;
              if (currentMbSi > 0) setPlaceholderMbSi(sizeInput, currentMbSi);
              sizeInput.value = '';
              // Reset data-mb-si pour éviter qu'une ancienne valeur soit
              // comptabilisée dans les sommes alors que l'input est vidé.
              sizeInput.dataset.mbSi = '0';
            } else {
              // Décocher : transfère le placeholder calculé (fill MB SI)
              // vers la value pour que l'utilisateur puisse l'éditer comme
              // point de départ. Sinon la value resterait vide.
              const phMbSi = parseInt(sizeInput.dataset.placeholderMbSi || '0', 10) || 0;
              if (phMbSi > 0) {
                sizeInput.dataset.mbSi = String(phMbSi);
                sizeInput.value = isBinaryUnit() ? String(mbSiToBinary(phMbSi)) : String(phMbSi);
                delete sizeInput.dataset.placeholderMbSi;
                sizeInput.placeholder = '';
              }
            }
          }
        }
        // Recalcule jauge + placeholders fill (côté partition ET LV)
        renderAllDiskGauges(root);
        // Si la partition est raid-member, sa taille impacte la capacité utile RAID
        if (partRow && partRow.querySelector('select[name$="[fs]"]')?.value === 'raid-member') {
          refreshRaidMetadata(root);
        }
      }

      // VG name change → propage sur les hidden vg fields des LV de ce VG
      if (el.matches?.('.vg-name-input')) {
        syncVgNameInLvs(el);
        rebuildAllPvCheckboxes(root); // les checkboxes PV affichent le nom VG
      }

      // Toggle is_pv sur un MD → impacte la liste des PV disponibles aux VG
      if (el.matches?.('input[type="checkbox"][name^="raid_arrays"][name$="[is_pv]"]')) {
        rebuildAllPvCheckboxes(root);
      }

      // Renommage d'un MD → recalcule les PV disponibles (le nom MD = identifiant PV)
      if (el.matches?.('.raid-name-input')) {
        rebuildAllPvCheckboxes(root);
      }

      // Niveau RAID changé → met à jour hint, contraintes, capacité utile
      if (el.matches?.('.raid-level-select')) {
        refreshRaidMetadata(root);
      }

      // Toggle membre/spare → mutex + maj capacité
      if (raidCard && el.type === 'checkbox' &&
          (el.name?.match(/raid_arrays\[\d+\]\[(members|spares)\]/))) {
        enforceRaidMutex(root);
        refreshRaidMetadata(root);
      }

      // Toggle PV cochée/décochée dans un VG → recalcule capacité du VG donc
      // placeholder des LV fill du même VG. Aussi : met à jour l'exclusivité
      // cross-VG (invariant LVM 1 PV = 1 VG max) dans tous les autres VGs.
      if (el.type === 'checkbox' && el.name?.match(/^vgs\[\d+\]\[pvs\]\[\]$/)) {
        rebuildAllPvCheckboxes(root);
        renderAllDiskGauges(root);
      }
    });

    // Évènement input (ex: tailles size_mb tapées) — maj capa RAID + jauge disque
    // + placeholders fill (un changement sur une partition impacte le PV fill,
    // donc la capacité du VG, donc le placeholder du LV fill).
    root.addEventListener('input', (e) => {
      const el = e.target;
      if (el.matches?.('input[name$="[size_mb]"]')) {
        const partRow = el.closest('.partition-row');
        const lvRow = el.closest('.lv-row');
        if (partRow || lvRow) {
          renderAllDiskGauges(root);
          if (partRow?.querySelector('select[name$="[fs]"]')?.value === 'raid-member') {
            refreshRaidMetadata(root);
          }
        }
      }
    });
  }

  function appendPartitionRow(diskCard, tpl, optsTpl) {
    const tbody = diskCard.querySelector('.partition-rows');
    const diskIdx = parseInt(diskCard.dataset.diskIdx || '0', 10);
    const partIdx = tbody.querySelectorAll('.partition-row').length;
    const html = tpl.innerHTML
      .replace(/__DI__/g, String(diskIdx))
      .replace(/__PI__/g, String(partIdx));
    const tmp = document.createElement('tbody');
    tmp.innerHTML = html.trim();
    const row = tmp.firstElementChild;
    tbody.appendChild(row);

    if (optsTpl) {
      const optsHtml = optsTpl.innerHTML
        .replace(/__DI__/g, String(diskIdx))
        .replace(/__PI__/g, String(partIdx));
      const tmpO = document.createElement('tbody');
      tmpO.innerHTML = optsHtml.trim();
      tbody.appendChild(tmpO.firstElementChild);
    }

    syncReservedAndMountFor(row);
    syncOptionsButton(row);
    return row;
  }

  function reindexPartitionRows(tbody) {
    const diskIdx = tbody.dataset.diskIdx;
    let mainIdx = -1;
    [...tbody.children].forEach(row => {
      if (row.classList.contains('partition-row')) mainIdx++;
      if (mainIdx < 0) return;
      reindexAttrs(row, /partitions\[\d+\]/g, `partitions[${mainIdx}]`);
    });
  }

  // Réassigne un id "logique" (sda1, sda2, sdb1, ...) à chaque partition
  // depuis son disque parent + sa position. L'id est consommé par les VG
  // (vgs[].pvs[]) et les arrays RAID (raid_arrays[].members[]/.spares[]).
  // Quand un renommage a lieu (ex : suppression d'une partition biosgrub →
  // les partitions suivantes se renumérotent), on remappe aussi les
  // références dans les checkboxes PV/RAID pour préserver la sélection.
  function renumberAllPartitionIds(root) {
    const oldToNew = {};
    root.querySelectorAll('.disk-card').forEach(card => {
      const path = card.dataset.diskPath || '';
      const base = basename(path) || ('disk' + (card.dataset.diskIdx || '0'));
      let pos = 1;
      card.querySelectorAll('.partition-row').forEach(row => {
        const idInput = row.querySelector('input[type="hidden"][name$="[id]"]');
        const idDisplay = row.querySelector('.part-id-display');
        const oldId = idInput?.value || '';
        const newId = base + pos;
        if (oldId && oldId !== newId) oldToNew[oldId] = newId;
        if (idInput) idInput.value = newId;
        if (idDisplay) idDisplay.textContent = newId;
        pos++;
      });
    });
    // Remap des références vers les anciens IDs.
    if (Object.keys(oldToNew).length > 0) {
      root.querySelectorAll(
        'input[type="checkbox"][name*="[pvs]"], ' +
        'input[type="checkbox"][name*="[members]"], ' +
        'input[type="checkbox"][name*="[spares]"]'
      ).forEach(cb => {
        if (cb.value && oldToNew[cb.value]) cb.value = oldToNew[cb.value];
      });
    }
  }

  // Sync mount + reserved + options selon fs
  function syncReservedAndMountFor(row) {
    const fs = row.querySelector('select[name$="[fs]"]')?.value || '';
    const mountInput = row.querySelector('input[name$="[mount]"]');
    const reservedInput = row.querySelector('.cell-reserved input');

    const isContainer = (fs === 'lvm-pv' || fs === 'raid-member' || fs === 'biosgrub');
    const isSwap = fs === 'swap';
    const isEfi = fs === 'efi';
    const isExt = EXT_FS.includes(fs);

    if (mountInput) {
      if (isContainer || isSwap) {
        mountInput.value = '';
        mountInput.disabled = true;
        mountInput.readOnly = false;
      } else if (isEfi) {
        // EFI : mountpoint imposé par convention partman/Linux. readonly (et
        // pas disabled) pour que la valeur soit bien soumise côté serveur.
        mountInput.value = '/boot/efi';
        mountInput.disabled = false;
        mountInput.readOnly = true;
      } else {
        mountInput.disabled = false;
        mountInput.readOnly = false;
      }
    }
    if (reservedInput) {
      if (!isExt) {
        reservedInput.value = '';
        reservedInput.disabled = true;
        reservedInput.style.visibility = 'hidden';
      } else {
        reservedInput.disabled = false;
        reservedInput.style.visibility = '';
      }
    }
  }

  function syncOptionsButton(row) {
    const fs = row.querySelector('select[name$="[fs]"]')?.value || '';
    const btn = row.querySelector('.btn-options-toggle');
    const optsRow = row.nextElementSibling;
    const noOptions = (fs === 'efi' || fs === 'swap' || fs === 'lvm-pv' || fs === 'raid-member' || fs === 'biosgrub');
    if (btn) {
      btn.disabled = noOptions;
      btn.title = noOptions
        ? "Pas d'options de montage pour " + fs.toUpperCase()
        : 'Options de montage';
      if (noOptions) btn.classList.remove('active');
    }
    if (noOptions && optsRow && optsRow.classList.contains('partition-options-row')) {
      optsRow.hidden = true;
    }
  }

  // ====== Section 4 — Volume Groups + Logical Volumes ========================

  function bindVgActions(root) {
    const vgAdd = root.querySelector('#vg-add');
    const vgsContainer = root.querySelector('#vgs-container');
    const vgTpl = root.querySelector('#vg-card-tpl');
    if (!vgAdd || !vgsContainer || !vgTpl) return;

    vgAdd.addEventListener('click', () => {
      const idx = vgsContainer.querySelectorAll('.vg-card').length;
      const html = vgTpl.innerHTML.replace(/__VI__/g, String(idx));
      const tmp = document.createElement('div');
      tmp.innerHTML = html.trim();
      const card = tmp.firstElementChild;
      vgsContainer.appendChild(card);
      rebuildPvCheckboxesFor(card, root);
    });

    // Add LV — pré-remplit size_mb avec l'espace libre du VG si dispo.
    root.addEventListener('click', (e) => {
      const btnLv = e.target.closest('.btn-add-lv');
      if (!btnLv || btnLv.disabled) return;
      const vgCard = btnLv.closest('.vg-card');
      if (!vgCard) return;
      const lvTpl = root.querySelector('#lv-row-tpl');
      const lvOptsTpl = root.querySelector('#lv-options-row-tpl');
      if (!lvTpl) return;
      const freeMb = computeVgFreeMb(vgCard, root);
      const row = appendLvRow(vgCard, lvTpl, lvOptsTpl, root);
      if (freeMb !== null && freeMb > 0) {
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        if (sizeInput) {
          sizeInput.dataset.mbSi = String(freeMb);
        }
      }
      applyUnitToInputs(row);
      renderAllDiskGauges(root);
    });
  }

  function appendLvRow(vgCard, tpl, optsTpl, root) {
    const tbody = vgCard.querySelector('.lv-rows');
    const vgName = vgCard.querySelector('.vg-name-input')?.value || '';
    const globalLvIdx = root.querySelectorAll('.lv-row').length;
    const html = tpl.innerHTML.replace(/__LI__/g, String(globalLvIdx));
    const tmp = document.createElement('tbody');
    tmp.innerHTML = html.trim();
    const row = tmp.firstElementChild;
    tbody.appendChild(row);
    // Set vg hidden
    const vgInput = row.querySelector('input[type="hidden"][name$="[vg]"]');
    if (vgInput) vgInput.value = vgName;

    if (optsTpl) {
      const optsHtml = optsTpl.innerHTML.replace(/__LI__/g, String(globalLvIdx));
      const tmpO = document.createElement('tbody');
      tmpO.innerHTML = optsHtml.trim();
      tbody.appendChild(tmpO.firstElementChild);
    }

    syncReservedAndMountFor(row);
    syncOptionsButton(row);
    return row;
  }

  function reindexLvRowsGlobal(root) {
    let idx = 0;
    root.querySelectorAll('.lv-row').forEach(row => {
      reindexAttrs(row, /logical_volumes\[\d+\]/g, `logical_volumes[${idx}]`);
      const optsRow = row.nextElementSibling;
      if (optsRow && optsRow.classList.contains('partition-options-row')) {
        reindexAttrs(optsRow, /logical_volumes\[\d+\]/g, `logical_volumes[${idx}]`);
      }
      idx++;
    });
  }

  function reindexVgs(container) {
    [...container.querySelectorAll('.vg-card')].forEach((card, newIdx) => {
      const oldIdx = card.dataset.vgIdx;
      if (oldIdx === String(newIdx)) return;
      card.dataset.vgIdx = String(newIdx);
      reindexAttrs(card, new RegExp(`vgs\\[${oldIdx}\\]`, 'g'), `vgs[${newIdx}]`);
      card.querySelectorAll('[data-vg-idx]').forEach(el => {
        if (el.dataset.vgIdx === oldIdx) el.dataset.vgIdx = String(newIdx);
      });
    });
  }

  function syncVgNameInLvs(vgNameInput) {
    const vgCard = vgNameInput.closest('.vg-card');
    if (!vgCard) return;
    const newName = vgNameInput.value;
    const lvBody = vgCard.querySelector('.lv-rows');
    if (!lvBody) return;
    lvBody.dataset.vgName = newName;
    lvBody.querySelectorAll('input[type="hidden"][name$="[vg]"]').forEach(inp => {
      inp.value = newName;
    });
  }

  // Reconstruit les checkboxes PV de tous les VG depuis l'état courant
  // des partitions lvm-pv (id = sda1, sda2, ...).
  // Invariant LVM : 1 PV = 1 VG max. Les PVs déjà assignés à un autre VG
  // sont grisés (disabled) avec tooltip explicite pour guider l'admin.
  function rebuildAllPvCheckboxes(root) {
    const availablePvs = collectAvailablePvIds(root);
    root.querySelectorAll('.vg-card').forEach(card => {
      rebuildPvCheckboxesFor(card, root, availablePvs);
    });
  }

  function collectAvailablePvIds(root) {
    const ids = [];
    root.querySelectorAll('.partition-row').forEach(row => {
      const fs = row.querySelector('select[name$="[fs]"]')?.value;
      if (fs !== 'lvm-pv') return;
      const id = row.querySelector('input[type="hidden"][name$="[id]"]')?.value;
      if (id) ids.push(id);
    });
    // Phase B : MD avec is_pv coché → ajouté comme PV référençable par les VG
    root.querySelectorAll('.raid-card').forEach(card => {
      const isPv = card.querySelector('input[type="checkbox"][name$="[is_pv]"]:checked');
      if (!isPv) return;
      const name = card.querySelector('.raid-name-input')?.value.trim();
      if (name) ids.push(name);
    });
    return ids;
  }

  function collectAvailableRaidMemberIds(root) {
    const ids = [];
    root.querySelectorAll('.partition-row').forEach(row => {
      const fs = row.querySelector('select[name$="[fs]"]')?.value;
      if (fs !== 'raid-member') return;
      const id = row.querySelector('input[type="hidden"][name$="[id]"]')?.value;
      if (id) ids.push(id);
    });
    return ids;
  }

  /**
   * Retourne un Map pvId → vgName pour tous les PVs cochés dans tous les VGs.
   * Utilisé par rebuildPvCheckboxesFor pour appliquer l'exclusivité cross-VG.
   * Le vgCard courant est exclu du calcul (pour ne pas griser ses propres PVs).
   */
  function collectPvsAssignedToOtherVgs(root, excludeVgCard) {
    const map = new Map(); // pvId → vgName
    root.querySelectorAll('.vg-card').forEach(card => {
      if (card === excludeVgCard) return;
      const vgName = card.querySelector('.vg-name-input')?.value || ('VG#' + (card.dataset.vgIdx || '?'));
      card.querySelectorAll('.vg-pvs-checkboxes input[type="checkbox"]:checked').forEach(cb => {
        if (cb.value) map.set(cb.value, vgName);
      });
    });
    return map;
  }

  function rebuildPvCheckboxesFor(vgCard, root, availablePvs) {
    if (!availablePvs) availablePvs = collectAvailablePvIds(root);
    const container = vgCard.querySelector('.vg-pvs-checkboxes');
    if (!container) return;
    const vgIdx = vgCard.dataset.vgIdx;

    // Conserve l'état des cases déjà cochées
    const checked = new Set(
      [...container.querySelectorAll('input[type="checkbox"]:checked')].map(cb => cb.value)
    );

    // Invariant LVM : 1 PV = 1 VG max.
    // PVs déjà assignés dans d'autres VGs → grisés + tooltip.
    const assignedElsewhere = collectPvsAssignedToOtherVgs(root, vgCard);

    container.innerHTML = '';
    if (availablePvs.length === 0) {
      const hint = document.createElement('span');
      hint.className = 'muted text-small';
      hint.textContent = 'Aucune partition lvm-pv disponible — créez-en une dans la section Partitions.';
      container.appendChild(hint);
      return;
    }

    availablePvs.forEach(pvId => {
      const ownerVg = assignedElsewhere.get(pvId);
      const isAssigned = ownerVg !== undefined;
      const label = document.createElement('label');
      label.className = 'pv-option' + (isAssigned ? ' pv-option--unavailable' : '');
      // Un PV déjà membre d'un autre VG : désactivé + tooltip explicite.
      // Ne jamais pré-cocher un PV grisé (invalide sémantiquement).
      const isChecked = checked.has(pvId) && !isAssigned;
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.name = `vgs[${vgIdx}][pvs][]`;
      cb.value = pvId;
      cb.checked = isChecked;
      if (isAssigned) {
        cb.disabled = true;
        label.title = `Déjà membre de ${ownerVg} — un PV ne peut appartenir qu'à un seul VG`;
      }
      const code = document.createElement('code');
      code.textContent = pvId;
      label.appendChild(cb);
      label.appendChild(document.createTextNode(' '));
      label.appendChild(code);
      container.appendChild(label);
    });
  }

  // ====== Disk gauge (style macOS Storage) ===================================

  // Couleurs supportées par le CSS .disk-gauge__seg--<key>.
  // Inclut les containers (lvm-pv, raid-member, biosgrub) avec leurs propres
  // teintes pour les distinguer visuellement (style macOS Storage).
  const GAUGE_FS_KEYS = {
    ext4: 'ext4', ext3: 'ext3', ext2: 'ext2',
    xfs: 'xfs', swap: 'swap', efi: 'efi',
    'lvm-pv': 'lvm-pv',
    'raid-member': 'raid-member',
    biosgrub: 'biosgrub',
  };

  function diskMetaByPath() {
    const hw = readMachineHw();
    const map = {};
    for (const d of hw?.disks || []) {
      const path = (d.name || '').startsWith('/dev/') ? d.name : '/dev/' + (d.name || '');
      map[path] = {
        size_bytes: d.size_bytes || null,
        vendor: d.vendor || '',
        model: d.model || '',
        is_ssd: d.is_ssd,
      };
    }
    // Modèle générique uniquement : inputs disk_capacity_gb[] saisis par
    // l'utilisateur (sans machine de référence pour fournir un inventaire).
    // Lus comme Go SI (multiplié par 1e9 pour matcher size_bytes de l'inventory).
    // Garde-fou : ne pas écraser l'inventaire si déjà présent (la discovery est
    // la source de vérité pour Installation et Modèle avec ref machine).
    document.querySelectorAll('input.target-disk-capacity-input').forEach(input => {
      const path = input.dataset.diskPath || input.name?.replace(/^disk_capacity_gb\[(.+)\]$/, '$1');
      if (!path) return;
      if (map[path]?.size_bytes) return;
      const val = parseInt(input.value || '0', 10);
      if (val > 0) {
        if (!map[path]) map[path] = { size_bytes: null, vendor: '', model: '', is_ssd: null };
        map[path].size_bytes = val * 1_000_000_000;
      }
    });
    return map;
  }

  // Convention d'unité : par défaut SI (MB / GB = 10^6 / 10^9 bytes), cohérent
  // avec partman et l'installeur Debian manuel. Toggle utilisateur pour basculer
  // en binaire (Mo / Go = 2^20 / 2^30 bytes, convention française historique).
  // L'état est lu à chaque appel pour suivre le toggle sans bind.
  function isBinaryUnit() {
    try { return localStorage.getItem('pxe-tools.partition.unitBinary') === '1'; }
    catch (e) { return false; }
  }
  function formatBytesGo(bytes) {
    if (!bytes || bytes <= 0) return null;
    const binary = isBinaryUnit();
    const divisor = binary ? (1024 ** 3) : 1e9;
    const unit = binary ? 'Go' : 'GB';
    return (bytes / divisor).toFixed(1).replace('.', ',') + ' ' + unit;
  }
  function formatMbGo(mbSi) {
    if (mbSi === null || mbSi === undefined) return '—';
    if (isBinaryUnit()) {
      // Conversion réelle MB SI → MiB binaire pour rester cohérent
      // avec formatBytesGo (bytes / 1024³). 1 MB SI = 0,9537 MiB.
      const mbBin = mbSi * 1_000_000 / (1024 * 1024);
      if (mbBin < 1024) return Math.round(mbBin) + ' Mo';
      return (mbBin / 1024).toFixed(1).replace('.', ',') + ' Go';
    }
    if (mbSi < 1000) return mbSi + ' MB';
    return (mbSi / 1000).toFixed(1).replace('.', ',') + ' GB';
  }

  // Convertit un fs en clé de palette gauge. Fallback `ext4` pour les fs
  // inconnus (futures additions sans CSS dédié).
  function gaugeFsKey(fs) {
    return GAUGE_FS_KEYS[fs] || 'ext4';
  }
  function gaugeLabel(fs) {
    if (fs === 'lvm-pv') return 'LVM PV';
    if (fs === 'raid-member') return 'RAID';
    if (fs === 'biosgrub') return 'BIOS GRUB';
    return fs.toUpperCase();
  }

  function renderDiskGauge(diskCard, metaByPath) {
    const slot = diskCard.querySelector('[data-gauge-slot]');
    if (!slot) return;
    const path = diskCard.dataset.diskPath || '';
    const meta = (metaByPath || diskMetaByPath())[path] || {};
    const totalBytes = meta.size_bytes || null;
    const totalMb = totalBytes ? Math.floor(totalBytes / 1_000_000) : null;

    // Collecte les partitions de cette card
    const parts = [...diskCard.querySelectorAll('.partition-row')].map(row => {
      const fs = row.querySelector('select[name$="[fs]"]')?.value || '';
      const id = row.querySelector('input[type="hidden"][name$="[id]"]')?.value || '';
      const sizeRaw = row.querySelector('input[name$="[size_mb]"]')?.value || '';
      const fill = !!row.querySelector('input[type="checkbox"][name$="[fill]"]:checked');
      const sizeMb = fill ? null : parseInt(sizeRaw || '0', 10);
      return { fs, id, sizeMb, fill };
    });

    // Si aucune partition, on enlève la jauge
    if (parts.length === 0) {
      slot.innerHTML = '';
      return;
    }

    const usedFixedMb = parts
      .filter(p => !p.fill && typeof p.sizeMb === 'number')
      .reduce((s, p) => s + p.sizeMb, 0);
    const hasFill = parts.some(p => p.fill);

    // Détermine l'état
    let state = '';
    if (totalMb && usedFixedMb > totalMb) state = 'over-budget';
    else if (!totalMb) state = 'relative';

    // Calcule les % de chaque segment
    let segments;
    let freePct = 0;
    if (totalMb && state !== 'over-budget') {
      // Mode "absolu" : pourcentages réels par rapport au disque
      const remainingMb = Math.max(0, totalMb - usedFixedMb);
      const fillCount = parts.filter(p => p.fill).length;
      const perFillMb = fillCount > 0 ? remainingMb / fillCount : 0;
      segments = parts.map(p => ({
        ...p,
        widthPct: ((p.fill ? perFillMb : p.sizeMb) / totalMb) * 100,
        displayMb: p.fill ? perFillMb : p.sizeMb,
      }));
      // Free segment seulement si pas de fill (sinon fill consomme tout le restant)
      if (!hasFill) {
        freePct = (remainingMb / totalMb) * 100;
      }
    } else if (state === 'over-budget') {
      // Mode "dépassement" : on rapporte au usedFixedMb (qui est > totalMb)
      segments = parts.map(p => ({
        ...p,
        widthPct: !p.fill && p.sizeMb ? (p.sizeMb / usedFixedMb) * 100 : 0,
        displayMb: p.sizeMb,
      }));
    } else {
      // Mode "relatif" : pourcentages relatifs entre partitions (pas de total)
      const totalDeclared = parts.reduce((s, p) => s + (p.sizeMb || 0), 0) || 1;
      segments = parts.map(p => ({
        ...p,
        widthPct: p.fill ? 100 / parts.length : ((p.sizeMb || 0) / totalDeclared) * 100,
        displayMb: p.sizeMb,
      }));
    }

    // Construction HTML
    const totalLabel = formatBytesGo(totalBytes);
    // Une partition en mode "Remplir" consomme l'espace restant → l'affichage
    // "utilisés" doit inclure ce reste (sinon incohérent avec "aucun espace libre").
    const usedDisplayMb = (totalMb && hasFill && state !== 'over-budget')
      ? totalMb
      : usedFixedMb;
    const usedLabel  = formatMbGo(usedDisplayMb);
    const vendorBits = [meta.vendor, meta.model].filter(Boolean).join(' ');

    const segHtml = segments.map(s => {
      const cls = s.fill
        ? `disk-gauge__seg disk-gauge__seg--${gaugeFsKey(s.fs)} disk-gauge__seg--fill`
        : `disk-gauge__seg disk-gauge__seg--${gaugeFsKey(s.fs)}`;
      return `<div class="${cls}" style="--seg-width: ${s.widthPct.toFixed(2)}%" title="${s.id} · ${gaugeLabel(s.fs)} · ${formatMbGo(s.displayMb)}"></div>`;
    }).join('');

    const freeSegHtml = freePct > 0.01
      ? `<div class="disk-gauge__seg disk-gauge__seg--free" style="--seg-width: ${freePct.toFixed(2)}%" title="Espace libre"></div>`
      : '';

    const legendHtml = segments.map(s => {
      const fillBadge = s.fill ? '<span class="disk-gauge__badge">FILL</span>' : '';
      return `<li class="disk-gauge__legend-item">
        <span class="disk-gauge__dot disk-gauge__dot--${gaugeFsKey(s.fs)}"></span>
        <span class="disk-gauge__legend-label">${s.id || '?'}</span>
        <span class="disk-gauge__legend-meta">${gaugeLabel(s.fs)} · ${formatMbGo(s.displayMb)} ${fillBadge}</span>
      </li>`;
    }).join('');
    const freeLegendHtml = (totalMb && !hasFill && freePct > 0.01)
      ? `<li class="disk-gauge__legend-item">
          <span class="disk-gauge__dot disk-gauge__dot--free"></span>
          <span class="disk-gauge__legend-label">Libre</span>
          <span class="disk-gauge__legend-meta">${formatMbGo(Math.max(0, (totalMb||0) - usedFixedMb))}</span>
        </li>` : '';

    const overflowMb = state === 'over-budget' ? (usedFixedMb - (totalMb || 0)) : 0;
    const alertHtml = state === 'over-budget'
      ? `<p class="disk-gauge__alert">Dépassement de capacité : ${formatMbGo(overflowMb)} de trop par rapport au disque (${totalLabel || '—'}).</p>`
      : '';

    const freeMb = totalMb !== null
      ? (hasFill ? 0 : Math.max(0, totalMb - usedFixedMb))
      : null;
    const freeLabel = freeMb !== null ? formatMbGo(freeMb) : null;

    const summaryHtml = totalLabel
      ? `<p class="disk-gauge__summary">
          <span class="disk-gauge__used"><strong>${usedLabel}</strong> utilisés</span>
          <span class="disk-gauge__sep">·</span>
          <span class="disk-gauge__free"><strong>${freeLabel}</strong> libres</span>
          <span class="disk-gauge__sep">·</span>
          <span class="disk-gauge__total"><strong>${totalLabel}</strong> total</span>
        </p>`
      : `<p class="disk-gauge__summary"><span class="disk-gauge__used">${usedLabel}</span> <span class="disk-gauge__used-suffix">déclarés (taille disque inconnue)</span></p>`;

    slot.innerHTML = `
      <div class="disk-gauge"${state ? ` data-state="${state}"` : ''}>
        <div class="disk-gauge__header">
          <h4 class="disk-gauge__title">
            Disque <code>${path}</code>
            ${vendorBits ? `<span class="disk-gauge__title-vendor">· ${vendorBits}</span>` : ''}
          </h4>
          ${summaryHtml}
        </div>
        <div class="disk-gauge__bar">${segHtml}${freeSegHtml}</div>
        <ul class="disk-gauge__legend">${legendHtml}${freeLegendHtml}</ul>
        ${alertHtml}
      </div>
    `;

    // Placeholder lecture-seule sur les inputs size_mb des partitions fill :
    // affiche la taille calculée (espace restant / nb fills) sans la persister
    // côté serveur (placeholder, pas value). Seulement si on connaît la taille
    // totale du disque (sinon on ne peut pas calculer le restant).
    if (totalMb && state !== 'over-budget') {
      const fillCount = parts.filter(p => p.fill).length;
      const remainingMb = Math.max(0, totalMb - usedFixedMb);
      const perFillMb = fillCount > 0 ? Math.floor(remainingMb / fillCount) : 0;
      diskCard.querySelectorAll('.partition-row').forEach(row => {
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        if (!sizeInput) return;
        setPlaceholderMbSi(sizeInput, fillCb?.checked ? perFillMb : 0);
      });
    } else {
      // Capacité disque inconnue (ex: preset générique sans machine de réf).
      // Pas de calcul dynamique possible : on garde le placeholder mémorisé
      // (au load via data-mb-si initial, ou au coch via le handler fill),
      // qui sert de repère affiché et de fallback au décochage.
      diskCard.querySelectorAll('.partition-row').forEach(row => {
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        if (!sizeInput) return;
        if (!fillCb?.checked) {
          setPlaceholderMbSi(sizeInput, 0);
          return;
        }
        const existing = parseInt(sizeInput.dataset.placeholderMbSi || '0', 10) || 0;
        if (existing === 0) {
          const initial = parseInt(sizeInput.dataset.mbSi || '0', 10) || 0;
          if (initial > 0) setPlaceholderMbSi(sizeInput, initial);
        }
      });
    }
  }

  /**
   * Pour chaque VG, calcule la taille totale (somme des PVs cochés, en
   * tenant compte des fills via les size_mb effectifs des partitions PV),
   * puis renseigne le placeholder des LV row avec fill=true.
   * Pas de modification de value (= rien n'est POSTé), juste un affichage.
   */
  function renderLvSizePlaceholders(root) {
    // Étape 1 : calculer la taille effective de chaque partition (id → mb).
    //   - taille fixe = sizeInput.value
    //   - fill        = sizeInput.placeholder (déjà calculé par renderDiskGauge)
    const partSizeById = {};
    root.querySelectorAll('.disk-card .partition-row').forEach(row => {
      const id = row.querySelector('input[type="hidden"][name$="[id]"]')?.value || '';
      if (!id) return;
      const sizeInput = row.querySelector('input[name$="[size_mb]"]');
      if (!sizeInput) return;
      const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
      partSizeById[id] = fillCb?.checked ? inputPlaceholderMbSi(sizeInput) : inputMbSi(sizeInput);
    });

    // Étape 2 : par VG, somme les tailles des PVs cochés, puis dérive la
    // taille des LV fill. Masque aussi la table LV tant que 0 PV coché.
    root.querySelectorAll('.vg-card').forEach(vgCard => {
      const pvCbs = vgCard.querySelectorAll('.vg-pvs-checkboxes input[type="checkbox"][name*="[pvs]"]');
      let vgTotalMb = 0;
      let checkedCount = 0;
      pvCbs.forEach(cb => {
        if (!cb.checked) return;
        checkedCount++;
        const pvId = cb.value || '';
        if (partSizeById[pvId] != null) vgTotalMb += partSizeById[pvId];
      });

      // Toggle visibilité : sans PV coché, la table n'a aucun sens (capacité
      // VG inconnue). On grise tout et on affiche un hint inline.
      const hasPv = checkedCount > 0;
      const lvTable = vgCard.querySelector('.lv-table');
      const lvHint  = vgCard.querySelector('.vg-lvs__hint');
      const btnAddLv = vgCard.querySelector('.btn-add-lv');
      if (lvTable) lvTable.hidden = !hasPv;
      if (lvHint)  lvHint.hidden  = hasPv;
      if (btnAddLv) btnAddLv.disabled = !hasPv;

      const lvRows = [...vgCard.querySelectorAll('.lv-row')];
      const lvFixedSum = lvRows.reduce((s, row) => {
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked) return s;
        const v = inputMbSi(row.querySelector('input[name$="[size_mb]"]'));
        return s + v;
      }, 0);
      const lvFillRows = lvRows.filter(r =>
        r.querySelector('input[type="checkbox"][name$="[fill]"]')?.checked
      );
      const perLvFillMb = lvFillRows.length > 0 && vgTotalMb > 0
        ? Math.floor(Math.max(0, vgTotalMb - lvFixedSum) / lvFillRows.length)
        : 0;

      lvRows.forEach(row => {
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        if (!sizeInput) return;
        if (!fillCb?.checked) {
          setPlaceholderMbSi(sizeInput, 0);
          return;
        }
        if (perLvFillMb > 0) {
          setPlaceholderMbSi(sizeInput, perLvFillMb);
          return;
        }
        // Capacité VG inconnue (PV en fill sans capa disque, ou preset
        // générique). On garde le placeholder mémorisé ou on retombe sur
        // data-mb-si initial pour afficher au moins un repère.
        const existing = parseInt(sizeInput.dataset.placeholderMbSi || '0', 10) || 0;
        if (existing === 0) {
          const initial = parseInt(sizeInput.dataset.mbSi || '0', 10) || 0;
          if (initial > 0) setPlaceholderMbSi(sizeInput, initial);
        }
      });
    });
  }

  // Marge réservée par partman pour la table de partition + alignement,
  // exprimée en MB SI (10^6 bytes) — cohérent avec partman expert_recipe.
  // GPT (présence biosgrub ou efi) = 2 MB ; MBR/msdos = 1 MB.
  // Doit rester aligné avec InstallConfigBuilder::partitionTableMarginMb (PHP).
  function partitionTableMarginMb(parts) {
    for (const p of parts) {
      const fs = (p.fs || '').toLowerCase();
      if (fs === 'biosgrub' || fs === 'efi') return 2;
    }
    return 1;
  }

  /**
   * Espace libre (MiB) du disque = capacité − marge − somme partitions fixes.
   * Retourne null si la capacité du disque est inconnue (preset générique).
   * Si au moins une partition est en fill=true, l'espace est consommé → 0.
   */
  function computeDiskFreeMb(diskCard) {
    const path = diskCard.dataset.diskPath || '';
    const totalBytes = diskMetaByPath()[path]?.size_bytes || null;
    if (!totalBytes) return null;
    const totalMb = Math.floor(totalBytes / 1_000_000);
    const parts = [...diskCard.querySelectorAll('.partition-row')];
    const partsForMargin = parts.map(r => ({
      fs: r.querySelector('select[name$="[fs]"]')?.value || '',
    }));
    const marginMb = partitionTableMarginMb(partsForMargin);
    const hasFill = parts.some(r =>
      r.querySelector('input[type="checkbox"][name$="[fill]"]')?.checked
    );
    if (hasFill) return 0;
    const sumFixed = parts.reduce((s, r) => {
      return s + inputMbSi(r.querySelector('input[name$="[size_mb]"]'));
    }, 0);
    return Math.max(0, totalMb - marginMb - sumFixed);
  }

  /**
   * Espace libre (MB SI) du VG = somme PVs cochés − somme LVs fixes.
   * Retourne null si aucun PV coché (capacité inconnue).
   * Retourne null (pas 0) si un LV est en fill=true : l'espace restant est
   * consommé par ce fill mais l'admin peut quand même ajouter un LV (le fill
   * se réduira en conséquence). null = "inconnu/indéterminé" → le bouton
   * "+ Ajouter un LV" reste actif, le hint disparaît.
   */
  function computeVgFreeMb(vgCard, root) {
    const partSizeById = {};
    root.querySelectorAll('.disk-card .partition-row').forEach(r => {
      const id = r.querySelector('input[type="hidden"][name$="[id]"]')?.value || '';
      if (!id) return;
      const sizeInput = r.querySelector('input[name$="[size_mb]"]');
      if (!sizeInput) return;
      const fillCb = r.querySelector('input[type="checkbox"][name$="[fill]"]');
      partSizeById[id] = fillCb?.checked ? inputPlaceholderMbSi(sizeInput) : inputMbSi(sizeInput);
    });
    const pvCbs = vgCard.querySelectorAll('.vg-pvs-checkboxes input[type="checkbox"][name*="[pvs]"]:checked');
    if (pvCbs.length === 0) return null;
    let total = 0;
    pvCbs.forEach(cb => { total += partSizeById[cb.value] || 0; });
    if (total === 0) return null;
    const lvRows = [...vgCard.querySelectorAll('.lv-row')];
    const hasFill = lvRows.some(r =>
      r.querySelector('input[type="checkbox"][name$="[fill]"]')?.checked
    );
    // Un LV fill consomme tout le restant → espace libre apparent = 0, mais
    // on retourne null pour signifier "indéterminé" et laisser le bouton actif.
    if (hasFill) return null;
    const lvFixedSum = lvRows.reduce((s, r) => {
      return s + inputMbSi(r.querySelector('input[name$="[size_mb]"]'));
    }, 0);
    return Math.max(0, total - lvFixedSum);
  }

  /**
   * Applique HTML5 setCustomValidity sur les inputs size_mb pour bloquer
   * le submit en cas d'overflow (somme > capacité − marge). Pas d'effet si
   * la capacité n'est pas connue (preset générique sans inventory).
   */
  function applyOverflowValidity(root) {
    const meta = diskMetaByPath();

    // 1. Par disque
    root.querySelectorAll('.disk-card').forEach(diskCard => {
      const path = diskCard.dataset.diskPath || '';
      const totalBytes = meta[path]?.size_bytes || null;
      const totalMb = totalBytes ? Math.floor(totalBytes / 1_000_000) : null;

      const rows = [...diskCard.querySelectorAll('.partition-row')];
      const partsForMargin = rows.map(row => ({
        fs: row.querySelector('select[name$="[fs]"]')?.value || '',
      }));
      const marginMb = totalMb ? partitionTableMarginMb(partsForMargin) : 0;
      const usableMb = totalMb ? Math.max(0, totalMb - marginMb) : null;

      const fixedSum = rows.reduce((s, row) => {
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked) return s;
        const v = inputMbSi(row.querySelector('input[name$="[size_mb]"]'));
        return s + v;
      }, 0);

      rows.forEach(row => {
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        if (!sizeInput) return;
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked || usableMb === null) {
          sizeInput.setCustomValidity('');
          return;
        }
        if (fixedSum > usableMb) {
          const overflow = fixedSum - usableMb;
          const msg = `Dépassement disque ${path} : ${formatMbGo(overflow)} de trop (capacité utile ${formatMbGo(usableMb)}, marge partman ${formatMbGo(marginMb)})`;
          sizeInput.setCustomValidity(msg);
          sizeInput.title = msg;
        } else {
          sizeInput.setCustomValidity('');
          sizeInput.title = '';
        }
      });
    });

    // 2. Par VG — réutilise les placeholders des partitions PV pour la
    //    capacité (déjà calculés par renderLvSizePlaceholders).
    //    Skip si aucune capacité disque n'est connue : on s'aligne sur le
    //    serveur (InstallConfigBuilder::validatePartitioningPlan skip via
    //    `if (!empty($diskCapacitiesMb))`). Sans capacité disque, le PV
    //    fill=true n'a pas de taille effective vérifiable et le placeholder
    //    issu de data-mb-si serait un seed UI trompeur — bloquer le submit
    //    sur cette base produisait un faux overflow (chicken-and-egg : on ne
    //    peut pas lier l'inventaire car le submit est bloqué par la validation
    //    générique stale).
    const anyDiskKnown = Object.values(meta).some(m => m && m.size_bytes);
    if (!anyDiskKnown) {
      root.querySelectorAll('.lv-row input[name$="[size_mb]"]').forEach(i => i.setCustomValidity(''));
      return;
    }

    const partSizeById = {};
    root.querySelectorAll('.disk-card .partition-row').forEach(row => {
      const id = row.querySelector('input[type="hidden"][name$="[id]"]')?.value || '';
      if (!id) return;
      const sizeInput = row.querySelector('input[name$="[size_mb]"]');
      if (!sizeInput) return;
      const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
      partSizeById[id] = fillCb?.checked ? inputPlaceholderMbSi(sizeInput) : inputMbSi(sizeInput);
    });

    root.querySelectorAll('.vg-card').forEach(vgCard => {
      const pvCbs = vgCard.querySelectorAll('.vg-pvs-checkboxes input[type="checkbox"][name*="[pvs]"]:checked');
      let vgTotalMb = 0;
      pvCbs.forEach(cb => {
        vgTotalMb += partSizeById[cb.value] || 0;
      });

      const lvRows = [...vgCard.querySelectorAll('.lv-row')];
      const lvFixedSum = lvRows.reduce((s, row) => {
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked) return s;
        const v = inputMbSi(row.querySelector('input[name$="[size_mb]"]'));
        return s + v;
      }, 0);

      lvRows.forEach(row => {
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        if (!sizeInput) return;
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked || vgTotalMb === 0) {
          sizeInput.setCustomValidity('');
          return;
        }
        if (lvFixedSum > vgTotalMb) {
          const overflow = lvFixedSum - vgTotalMb;
          const vgName = vgCard.querySelector('.vg-name-input')?.value || '?';
          const msg = `Dépassement VG ${vgName} : ${formatMbGo(overflow)} de trop (capacité ${formatMbGo(vgTotalMb)})`;
          sizeInput.setCustomValidity(msg);
          sizeInput.title = msg;
        } else {
          sizeInput.setCustomValidity('');
          sizeInput.title = '';
        }
      });
    });
  }

  /**
   * Bandeau capacité par VG : total / utilisé / libre, en miroir du summary
   * de la jauge disque. Caché tant que 0 PV coché (vgTotalMb = 0).
   */
  function renderVgCapacityBanners(root) {
    // partSizeById : copy de la logique de renderLvSizePlaceholders.
    const partSizeById = {};
    root.querySelectorAll('.disk-card .partition-row').forEach(row => {
      const id = row.querySelector('input[type="hidden"][name$="[id]"]')?.value || '';
      if (!id) return;
      const sizeInput = row.querySelector('input[name$="[size_mb]"]');
      if (!sizeInput) return;
      const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
      partSizeById[id] = fillCb?.checked ? inputPlaceholderMbSi(sizeInput) : inputMbSi(sizeInput);
    });

    root.querySelectorAll('.vg-card').forEach(vgCard => {
      const slot = vgCard.querySelector('[data-vg-capacity]');
      if (!slot) return;
      const pvCbs = vgCard.querySelectorAll('.vg-pvs-checkboxes input[type="checkbox"][name*="[pvs]"]:checked');
      let totalMb = 0;
      pvCbs.forEach(cb => { totalMb += partSizeById[cb.value] || 0; });

      if (totalMb === 0) {
        slot.hidden = true;
        return;
      }

      const lvRows = [...vgCard.querySelectorAll('.lv-row')];
      const hasFill = lvRows.some(row =>
        row.querySelector('input[type="checkbox"][name$="[fill]"]')?.checked
      );
      const usedFixed = lvRows.reduce((s, row) => {
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked) return s;
        const v = inputMbSi(row.querySelector('input[name$="[size_mb]"]'));
        return s + v;
      }, 0);
      // Un LV en mode "Remplir" consomme l'espace restant → "libre" = 0
      // et "utilisé" affiche le total (ou usedFixed si overflow).
      const usedDisplay = hasFill ? Math.max(usedFixed, totalMb) : usedFixed;
      const freeDisplay = hasFill ? 0 : Math.max(0, totalMb - usedFixed);

      slot.hidden = false;
      slot.querySelector('.vg-capacity__used strong').textContent  = formatMbGo(usedDisplay);
      slot.querySelector('.vg-capacity__free strong').textContent  = formatMbGo(freeDisplay);
      slot.querySelector('.vg-capacity__total strong').textContent = formatMbGo(totalMb);
      slot.classList.toggle('vg-capacity--over', usedFixed > totalMb);
    });
  }

  /**
   * Pour chaque bouton "+ Ajouter une partition" / "+ Ajouter un LV" :
   *   - met à jour le hint "· X Go restants" à droite du bouton
   *   - désactive le bouton si freeMb <= 0 (espace épuisé ou fill consommé)
   * Pas de hint si la capacité du parent est inconnue (preset générique).
   */
  function renderAddButtonHints(root) {
    root.querySelectorAll('.disk-card').forEach(diskCard => {
      const btn = diskCard.querySelector('.btn-add-partition');
      const hint = diskCard.querySelector('.add-row-bar__hint[data-free-hint]');
      if (!btn) return;
      const freeMb = computeDiskFreeMb(diskCard);
      if (freeMb === null) {
        if (hint) hint.textContent = '';
        btn.disabled = false; // capacité inconnue → on autorise
        return;
      }
      if (hint) hint.textContent = freeMb > 0 ? '· ' + formatMbGo(freeMb) + ' restants' : '· aucun espace libre';
      btn.disabled = freeMb <= 0;
    });

    root.querySelectorAll('.vg-card').forEach(vgCard => {
      const btn = vgCard.querySelector('.btn-add-lv');
      const hint = vgCard.querySelector('.add-row-bar__hint[data-free-hint]');
      if (!btn) return;
      const freeMb = computeVgFreeMb(vgCard, root);
      if (freeMb === null) {
        if (hint) hint.textContent = '';
        // disabled déjà géré par renderLvSizePlaceholders quand 0 PV
        return;
      }
      if (hint) hint.textContent = freeMb > 0 ? '· ' + formatMbGo(freeMb) + ' restants' : '· aucun espace libre';
      btn.disabled = freeMb <= 0;
    });
  }

  /**
   * Affiche à côté de chaque champ size_mb d'une partition disque un hint
   * "· max X Go" indiquant la taille maximale qu'on peut saisir sans dépasser
   * la capacité utile du disque (somme des autres partitions fixes incluse).
   * Masque le hint quand fill=true sur la ligne courante ou capacité inconnue
   * (preset générique sans inventory).
   */
  function renderPartMaxHints(root) {
    const meta = diskMetaByPath();
    root.querySelectorAll('.disk-card').forEach(diskCard => {
      const path = diskCard.dataset.diskPath || '';
      const totalBytes = meta[path]?.size_bytes || null;
      const totalMb = totalBytes ? Math.floor(totalBytes / 1_000_000) : 0;
      const rows = [...diskCard.querySelectorAll('.partition-row')];
      const partsForMargin = rows.map(r => ({
        fs: r.querySelector('select[name$="[fs]"]')?.value || '',
      }));
      const marginMb = totalMb > 0 ? partitionTableMarginMb(partsForMargin) : 0;
      const usableMb = Math.max(0, totalMb - marginMb);
      let fixedSum = 0;
      rows.forEach(r => {
        const fillCb = r.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked) return;
        fixedSum += inputMbSi(r.querySelector('input[name$="[size_mb]"]'));
      });
      rows.forEach(row => {
        const hint = row.querySelector('[data-size-max-hint="part"]');
        if (!hint) return;
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (!sizeInput || usableMb <= 0 || fillCb?.checked) {
          hint.hidden = true;
          return;
        }
        const myFixed = inputMbSi(sizeInput);
        const otherFixed = fixedSum - myFixed;
        const maxForThis = Math.max(0, usableMb - otherFixed);
        const strong = hint.querySelector('strong');
        if (strong) strong.textContent = formatMbGo(maxForThis);
        hint.hidden = false;
      });
    });
  }

  /**
   * Affiche à côté de chaque champ size_mb d'un LV un hint "· max X Go"
   * indiquant la taille maximale possible dans son VG (somme des autres LVs
   * fixes incluse). Masque le hint quand fill=true ou aucun PV coché.
   */
  function renderLvMaxHints(root) {
    // Tailles effectives des partitions (réutilise le placeholder pour les fill).
    const partSizeById = {};
    root.querySelectorAll('.disk-card .partition-row').forEach(r => {
      const id = r.querySelector('input[type="hidden"][name$="[id]"]')?.value || '';
      if (!id) return;
      const sizeInput = r.querySelector('input[name$="[size_mb]"]');
      if (!sizeInput) return;
      const fillCb = r.querySelector('input[type="checkbox"][name$="[fill]"]');
      partSizeById[id] = fillCb?.checked ? inputPlaceholderMbSi(sizeInput) : inputMbSi(sizeInput);
    });

    root.querySelectorAll('.vg-card').forEach(vgCard => {
      const pvCbs = vgCard.querySelectorAll('.vg-pvs-checkboxes input[type="checkbox"][name*="[pvs]"]:checked');
      let vgTotalMb = 0;
      pvCbs.forEach(cb => { vgTotalMb += partSizeById[cb.value] || 0; });

      const lvRows = [...vgCard.querySelectorAll('.lv-row')];
      let lvFixedSum = 0;
      lvRows.forEach(r => {
        const fillCb = r.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (fillCb?.checked) return;
        lvFixedSum += inputMbSi(r.querySelector('input[name$="[size_mb]"]'));
      });

      lvRows.forEach(row => {
        const hint = row.querySelector('[data-size-max-hint="lv"]');
        if (!hint) return;
        const sizeInput = row.querySelector('input[name$="[size_mb]"]');
        const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
        if (!sizeInput || vgTotalMb <= 0 || fillCb?.checked) {
          hint.hidden = true;
          return;
        }
        const myFixed = inputMbSi(sizeInput);
        const otherFixed = lvFixedSum - myFixed;
        const maxForThis = Math.max(0, vgTotalMb - otherFixed);
        const strong = hint.querySelector('strong');
        if (strong) strong.textContent = formatMbGo(maxForThis);
        hint.hidden = false;
      });
    });
  }

  function renderAllDiskGauges(root) {
    const raidSummary = root.querySelector('[data-raid-summary]');
    const lvmSummary = root.querySelector('[data-lvm-summary]');
    if (raidSummary) raidSummary.textContent = ' · ' + root.querySelectorAll('.raid-card').length + ' ensemble(s)';
    if (lvmSummary) lvmSummary.textContent = ' · ' + root.querySelectorAll('.vg-card').length + ' groupe(s), ' + root.querySelectorAll('.lv-row').length + ' volume(s)';
    const map = diskMetaByPath();
    root.querySelectorAll('.disk-card').forEach(card => renderDiskGauge(card, map));
    // Les LV placeholders dépendent des tailles des PV calculées juste au-dessus.
    renderLvSizePlaceholders(root);
    // Bandeau capacité VG (after placeholders : utilise les valeurs des PV fill).
    renderVgCapacityBanners(root);
    // Hints "· X restants" + disable des boutons "+ Ajouter" (after capacity calc).
    renderAddButtonHints(root);
    // Hints "· max X Go" inline à côté de chaque champ size_mb (préventif).
    renderPartMaxHints(root);
    renderLvMaxHints(root);
    // La validation overflow utilise les tailles + placeholders calculés ci-dessus.
    applyOverflowValidity(root);
  }

  // ====== Auto-layout : boot + LVM ============================================

  function buildAutoLayoutPlan(diskPath, isUefi) {
    const path = String(diskPath || '').trim();
    if (!/^\/dev\/[a-zA-Z0-9._/-]+$/.test(path)) return null;
    const diskName = basename(path);
    const partitions = [];
    const addPart = (fs, sizeMb, mount = null, extra = {}) => {
      const id = `${diskName}${partitions.length + 1}`;
      partitions.push({ id, fs, size_mb: sizeMb, fill: false, ...(mount ? { mount } : {}), ...extra });
      return id;
    };

    // GPT dans les deux cas : l'EFI remplace biosgrub sur une machine UEFI.
    if (isUefi) addPart('efi', 512, '/boot/efi');
    else addPart('biosgrub', 1);
    addPart('ext4', 1024, '/boot', {
      reserved_pct: 5, mount_options: ['noatime', 'nodev', 'nosuid', 'noexec'],
    });
    const pvId = addPart('lvm-pv', 1000, null, { fill: true });

    return {
      target_disks: [path],
      disks: [{ path, partitions }],
      raid_arrays: [],
      vgs: [{ name: 'vg_root', pvs: [pvId] }],
      logical_volumes: [
        { vg: 'vg_root', name: 'lv_root', fs: 'ext4', mount: '/', size_mb: 16384,
          fill: false, reserved_pct: 1, mount_options: ['noatime'] },
        { vg: 'vg_root', name: 'lv_swap', fs: 'swap', size_mb: 3072, fill: false },
        { vg: 'vg_root', name: 'lv_var', fs: 'ext4', mount: '/var', size_mb: 1000,
          fill: true, reserved_pct: 1, mount_options: ['noatime', 'nodev', 'nosuid'] },
      ],
    };
  }

  function bindAutoLayout(root) {
    const btn = root.querySelector('#partition-auto-layout');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const hw = readMachineHw();
      const isUefi = !!(hw?.is_uefi);

      const selected = [...root.querySelectorAll('input[type="checkbox"][name="target_disks[]"]:checked')]
        .map(cb => cb.value).filter(Boolean);
      if (selected.length !== 1) {
        alert('Sélectionnez exactement un disque cible avant de créer le partitionnement automatique.');
        return;
      }
      const plan = buildAutoLayoutPlan(selected[0], isUefi);
      if (!plan) return;
      const hasPlan = root.querySelector('.partition-row, .vg-card, .raid-card');
      if (hasPlan && !confirm(`Remplacer le partitionnement actuel par le layout LVM automatique sur ${selected[0]} ?`)) return;
      rebuildPartitioningPlan(plan, root);
      const lvmDetails = root.querySelector('[data-storage-lvm]');
      if (lvmDetails) lvmDetails.open = true;
      const bootdev = root.querySelector('input[name="adv[bootloader][bootdev]"]');
      if (bootdev) bootdev.value = selected[0];
    });
  }

  // ====== Section 3 — Arrays RAID ============================================

  // Métadonnées par niveau RAID — utilisé pour le hint contextuel et le calcul
  // de capacité utile. `usable(n, sizeGb)` renvoie la capacité approchée en Go.
  const RAID_LEVEL_META = {
    0:  {
      label: 'Stripe — performance, aucune redondance',
      desc: 'Données réparties sur ≥2 disques, pas de tolérance de panne. Capacité utile = somme des membres.',
      minMembers: 2,
      usable: (n, s) => n * s,
    },
    1:  {
      label: 'Miroir — copie identique, ≥2 disques, tolère 1 panne',
      desc: 'Chaque disque contient les mêmes données. Capacité utile = taille d\'un membre.',
      minMembers: 2,
      usable: (_, s) => s,
    },
    5:  {
      label: 'Stripe + parité distribuée, ≥3 disques, tolère 1 panne',
      desc: 'Une parité répartie sur l\'ensemble. Capacité utile = (n−1) × taille membre.',
      minMembers: 3,
      usable: (n, s) => Math.max(0, n - 1) * s,
    },
    6:  {
      label: 'Stripe + double parité, ≥4 disques, tolère 2 pannes',
      desc: 'Deux paritées réparties. Capacité utile = (n−2) × taille membre.',
      minMembers: 4,
      usable: (n, s) => Math.max(0, n - 2) * s,
    },
    10: {
      label: 'Miroir + stripe, ≥4 disques (paire de paires), tolère 1 panne par paire',
      desc: 'Chaque paire mirroirée puis stripée. Capacité utile = n / 2 × taille membre.',
      minMembers: 4,
      usable: (n, s) => Math.floor(n / 2) * s,
    },
  };

  function bindRaidActions(root) {
    const raidAdd = root.querySelector('#raid-add');
    const raidContainer = root.querySelector('#raid-arrays-container');
    const raidTpl = root.querySelector('#raid-card-tpl');
    if (!raidAdd || !raidContainer || !raidTpl) return;

    raidAdd.addEventListener('click', () => {
      const idx = raidContainer.querySelectorAll('.raid-card').length;
      const html = raidTpl.innerHTML.replace(/__RI__/g, String(idx));
      const tmp = document.createElement('div');
      tmp.innerHTML = html.trim();
      const card = tmp.firstElementChild;
      raidContainer.appendChild(card);

      // Auto-naming md0, md1, ... si aucun nom déjà utilisé
      const existingNames = new Set(
        [...raidContainer.querySelectorAll('.raid-name-input')]
          .map(i => i.value.trim())
          .filter(v => v !== '')
      );
      let n = 0;
      while (existingNames.has('md' + n)) n++;
      const nameInput = card.querySelector('.raid-name-input');
      if (nameInput) nameInput.value = 'md' + n;

      rebuildAllRaidCheckboxes(root);
      rebuildAllPvCheckboxes(root); // un nouveau MD avec is_pv pourrait apparaître côté VG
      refreshRaidMetadata(root);
    });
  }

  function reindexRaidArrays(container) {
    if (!container) return;
    [...container.querySelectorAll('.raid-card')].forEach((card, newIdx) => {
      const oldIdx = card.dataset.raidIdx;
      if (oldIdx === String(newIdx)) return;
      card.dataset.raidIdx = String(newIdx);
      reindexAttrs(card, new RegExp(`raid_arrays\\[${oldIdx}\\]`, 'g'), `raid_arrays[${newIdx}]`);
      card.querySelectorAll('[data-raid-idx]').forEach(el => {
        if (el.dataset.raidIdx === oldIdx) el.dataset.raidIdx = String(newIdx);
      });
    });
  }

  // Récupère les métadonnées d'une partition raid-member depuis sa row :
  // disque parent + taille (en Mo) + flag fill.
  function collectRaidMemberMeta(root) {
    const meta = {};
    root.querySelectorAll('.partition-row').forEach(row => {
      const fs = row.querySelector('select[name$="[fs]"]')?.value;
      if (fs !== 'raid-member') return;
      const id = row.querySelector('input[type="hidden"][name$="[id]"]')?.value;
      if (!id) return;
      const card = row.closest('.disk-card');
      const diskPath = card?.dataset.diskPath || '';
      const sizeInput = row.querySelector('input[name$="[size_mb]"]');
      const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
      const fill = !!fillCb?.checked;
      const sizeMb = fill ? null : parseInt(sizeInput?.value || '0', 10);
      meta[id] = { diskPath, sizeMb, fill };
    });
    return meta;
  }

  // Format taille humain (Go avec virgule décimale)
  function formatSize(mb) {
    if (mb === null || mb === undefined) return '—';
    if (mb < 1024) return mb + ' Mo';
    return (mb / 1024).toFixed(1).replace('.', ',') + ' Go';
  }

  // Reconstruit les checkboxes membres+spares de tous les arrays depuis l'état
  // courant des partitions raid-member. Inclut taille et disque parent.
  function rebuildAllRaidCheckboxes(root) {
    const available = collectAvailableRaidMemberIds(root);
    const meta = collectRaidMemberMeta(root);

    root.querySelectorAll('.raid-card').forEach(card => {
      const raidIdx = card.dataset.raidIdx;
      ['members', 'spares'].forEach(kind => {
        const container = card.querySelector(`.raid-${kind}-checkboxes`);
        if (!container) return;
        const checked = new Set(
          [...container.querySelectorAll('input[type="checkbox"]:checked')].map(cb => cb.value)
        );
        container.innerHTML = '';
        if (available.length === 0) {
          const hint = document.createElement('div');
          hint.className = 'raid-empty-hint';
          hint.textContent = 'Aucune partition raid-member disponible. Créez-en une dans la section Partitions.';
          container.appendChild(hint);
          return;
        }
        available.forEach(memberId => {
          const m = meta[memberId] || {};
          const sizeStr = m.fill ? 'espace restant du disque' : formatSize(m.sizeMb);
          const diskStr = m.diskPath || '';
          const lbl = document.createElement('label');
          lbl.className = kind === 'members' ? 'member-option' : 'spare-option';
          const bodyClass = kind === 'members' ? 'member-option__body' : 'spare-option__body';
          const idClass   = kind === 'members' ? 'member-option__id'   : 'spare-option__id';
          const metaClass = kind === 'members' ? 'member-option__meta' : 'spare-option__meta';
          lbl.innerHTML =
            `<input type="checkbox" name="raid_arrays[${raidIdx}][${kind}][]" value="${memberId}" ${checked.has(memberId) ? 'checked' : ''}>` +
            `<span class="${bodyClass}">` +
              `<span class="${idClass}">${memberId}</span>` +
              `<span class="${metaClass}">${sizeStr}${diskStr ? ' · ' + diskStr : ''}</span>` +
            `</span>`;
          container.appendChild(lbl);
        });
      });
    });

    // Mutex membres↔spares + maj des hints/capacités
    enforceRaidMutex(root);
    refreshRaidMetadata(root);
  }

  // Décoche+disable les spares déjà cochés en membre (et inverse) au sein d'une même card.
  function enforceRaidMutex(root) {
    root.querySelectorAll('.raid-card').forEach(card => {
      const memberBox = card.querySelector('.raid-members-checkboxes');
      const sparesBox = card.querySelector('.raid-spares-checkboxes');
      if (!memberBox || !sparesBox) return;
      const checkedMembers = new Set(
        [...memberBox.querySelectorAll('input[type="checkbox"]:checked')].map(cb => cb.value)
      );
      const checkedSpares = new Set(
        [...sparesBox.querySelectorAll('input[type="checkbox"]:checked')].map(cb => cb.value)
      );
      // Spares dont la cible est déjà membre → grisés
      sparesBox.querySelectorAll('label.spare-option').forEach(lbl => {
        const cb = lbl.querySelector('input[type="checkbox"]');
        if (!cb) return;
        const isMember = checkedMembers.has(cb.value);
        lbl.classList.toggle('spare-option--unavailable', isMember);
        if (isMember && cb.checked) cb.checked = false;
        cb.disabled = isMember;
      });
      // Membres déjà cochés en spare → idem (garde-fou symétrique, peu probable)
      memberBox.querySelectorAll('label.member-option').forEach(lbl => {
        const cb = lbl.querySelector('input[type="checkbox"]');
        if (!cb) return;
        const isSpare = checkedSpares.has(cb.value) && !checkedMembers.has(cb.value);
        lbl.classList.toggle('member-option--unavailable', isSpare);
        cb.disabled = isSpare;
      });
    });
  }

  // Met à jour : hint du niveau, badge "X / Y membres", capacité utile estimée.
  function refreshRaidMetadata(root) {
    const meta = collectRaidMemberMeta(root);
    root.querySelectorAll('.raid-card').forEach(card => {
      const level = parseInt(card.querySelector('.raid-level-select')?.value || '1', 10);
      const lvl = RAID_LEVEL_META[level] || RAID_LEVEL_META[1];
      const checkedMembers = [...card.querySelectorAll('.raid-members-checkboxes input[type="checkbox"]:checked')]
        .map(cb => cb.value);
      const n = checkedMembers.length;

      // Hint texte
      const hintEl = card.querySelector('[data-raid-level-hint]');
      if (hintEl) hintEl.textContent = `RAID ${level} — ${lvl.label}. ${lvl.desc}`;

      // Compteur "n / min requis"
      const countEl = card.querySelector('[data-raid-members-count]');
      if (countEl) {
        countEl.textContent = `${n} / ${lvl.minMembers} requis`;
        countEl.classList.toggle('raid-section__count--invalid', n < lvl.minMembers);
      }

      // Capacité utile : tous les membres doivent avoir une taille connue (pas de fill).
      // Estimation = usable(n, taille_min_membre).
      const capEl = card.querySelector('[data-raid-capacity]');
      if (capEl) {
        if (n < lvl.minMembers) {
          capEl.textContent = '—';
        } else {
          const sizes = checkedMembers.map(id => meta[id]?.sizeMb).filter(s => typeof s === 'number' && s > 0);
          if (sizes.length !== n) {
            // au moins un membre en "fill" → pas estimable proprement
            capEl.textContent = 'estimation impossible (membre fill)';
          } else {
            const minSize = Math.min(...sizes);
            const usableMb = lvl.usable(n, minSize);
            capEl.textContent = formatSize(usableMb);
          }
        }
      }
    });
  }

  // ====== Rebuild complet depuis un plan (pour chargement preset) ============

  function rebuildPartitioningPlan(plan, root = document) {
    if (!plan || typeof plan !== 'object') return;

    // 1. target_disks : coche/décoche les checkboxes
    const targetSet = new Set(plan.target_disks || []);
    root.querySelectorAll('input[type="checkbox"][name="target_disks[]"]').forEach(cb => {
      cb.checked = targetSet.has(cb.value);
    });

    // 2. Reset disks-partitions-container puis recrée les cards
    const container = root.querySelector('#disks-partitions-container');
    const tpl = root.querySelector('#disk-card-tpl');
    const partTpl = root.querySelector('#partition-row-tpl');
    const partOptsTpl = root.querySelector('#partition-options-row-tpl');
    if (container && tpl && partTpl) {
      container.innerHTML = '';
      (plan.disks || []).forEach((disk, di) => {
        const card = appendDiskCard(container, tpl, disk.path);
        // Update disk-idx in case it differs
        card.dataset.diskIdx = String(di);
        reindexAttrs(card, /disks\[\d+\]/g, `disks[${di}]`);
        const tbody = card.querySelector('.partition-rows');
        if (tbody) tbody.dataset.diskIdx = String(di);

        (disk.partitions || []).forEach((p, pi) => {
          const row = appendPartitionRow(card, partTpl, partOptsTpl);
          // Set valeurs
          const setVal = (sel, val) => {
            const el = row.querySelector(sel);
            if (el && val !== undefined && val !== null) el.value = val;
          };
          // POURQUOI poser l'ID depuis le plan : renumberAllPartitionIds
          // (appelé en fin de rebuildPartitioningPlan) calcule oldToNew en
          // comparant oldId (input[name$=[id]].value) à newId (basename+pos).
          // Sans ce setVal, oldId est vide → oldToNew vide → les références
          // dans les VG.pvs / RAID.members qui pointent sur l'id "logique"
          // du plan (ex: "sda3" alors que renumber le renommera "sda2")
          // ne sont jamais remappées → checkbox PV décochée après render
          // (bug observé : VG défini avec sda3 dans preset, UI affiche sda2
          // non coché alors que c'est le seul PV disponible).
          setVal('input[type="hidden"][name$="[id]"]', p.id || '');
          setVal('select[name$="[fs]"]', p.fs || 'ext4');
          setVal('input[name$="[mount]"]', p.mount || '');
          const fillCb = row.querySelector('input[type="checkbox"][name$="[fill]"]');
          const sizeInput = row.querySelector('input[name$="[size_mb]"]');
          const isFill = !!p.fill;
          if (fillCb) fillCb.checked = isFill;
          if (sizeInput) {
            // fill=true → taille vidée + grisée (le disque remplit le restant,
            // la valeur size_mb du preset sert juste de minimum côté backend).
            sizeInput.disabled = isFill;
            // data-mb-si reste la source de vérité (MB SI). applyUnitToInputs
            // s'occupera de formater value selon le mode courant.
            sizeInput.dataset.mbSi = String(p.size_mb || 0);
            sizeInput.value = isFill ? '' : String(p.size_mb || 0);
          }
          setVal('.cell-reserved input', p.reserved_pct ?? '');
          // Options de montage : ouvre la sub-row si options présentes
          const optsRow = row.nextElementSibling;
          if (optsRow && optsRow.classList.contains('partition-options-row') && Array.isArray(p.mount_options)) {
            const known = ['noatime','nodev','nosuid','noexec','discard'];
            const knownOpts = p.mount_options.filter(o => known.includes(o));
            const otherOpts = p.mount_options.filter(o => !known.includes(o));
            optsRow.querySelectorAll('input[type="checkbox"][name$="[mount_options][]"]').forEach(cb => {
              cb.checked = knownOpts.includes(cb.value);
            });
            const otherInput = optsRow.querySelector('input[name$="[mount_options_other]"]');
            if (otherInput) otherInput.value = otherOpts.join(',');
          }
          syncReservedAndMountFor(row);
          syncOptionsButton(row);
        });
      });
    }

    // 3. Reset raid-arrays-container puis recrée les cards
    const raidContainer = root.querySelector('#raid-arrays-container');
    const raidTpl = root.querySelector('#raid-card-tpl');
    if (raidContainer && raidTpl) {
      raidContainer.innerHTML = '';
      (plan.raid_arrays || []).forEach((arr, ri) => {
        const html = raidTpl.innerHTML.replace(/__RI__/g, String(ri));
        const tmp = document.createElement('div');
        tmp.innerHTML = html.trim();
        const card = tmp.firstElementChild;
        raidContainer.appendChild(card);

        const nameInput = card.querySelector('.raid-name-input');
        if (nameInput) nameInput.value = arr.name || '';
        const lvlSelect = card.querySelector('.raid-level-select');
        if (lvlSelect && arr.level !== undefined && arr.level !== null) {
          lvlSelect.value = String(arr.level);
        }
        const isPvCb = card.querySelector('input[type="checkbox"][name$="[is_pv]"]');
        if (isPvCb) isPvCb.checked = !!arr.is_pv;

        // Pré-coche les membres/spares (rebuildAllRaidCheckboxes en fin de fonction conservera l'état)
        ['members', 'spares'].forEach(kind => {
          const box = card.querySelector(`.raid-${kind}-checkboxes`);
          if (!box) return;
          const list = (kind === 'members' ? arr.members : arr.spares) || [];
          list.forEach(memberId => {
            const lbl = document.createElement('label');
            lbl.className = kind === 'members' ? 'member-option' : 'spare-option';
            lbl.innerHTML = `<input type="checkbox" name="raid_arrays[${ri}][${kind}][]" value="${memberId}" checked> <code>${memberId}</code>`;
            box.appendChild(lbl);
          });
        });
      });
    }

    // 4. Reset vgs-container
    const vgsContainer = root.querySelector('#vgs-container');
    const vgTpl = root.querySelector('#vg-card-tpl');
    const lvTpl = root.querySelector('#lv-row-tpl');
    const lvOptsTpl = root.querySelector('#lv-options-row-tpl');
    if (vgsContainer && vgTpl && lvTpl) {
      vgsContainer.innerHTML = '';
      let globalLvIdx = 0;
      (plan.vgs || []).forEach((vg, vi) => {
        const html = vgTpl.innerHTML.replace(/__VI__/g, String(vi));
        const tmp = document.createElement('div');
        tmp.innerHTML = html.trim();
        const card = tmp.firstElementChild;
        vgsContainer.appendChild(card);
        const nameInput = card.querySelector('.vg-name-input');
        if (nameInput) nameInput.value = vg.name || '';

        // PV checkboxes : sera rebuilt via rebuildAllPvCheckboxes mais on pré-coche
        // les valeurs souhaitées en marquant un dataset à exploiter par rebuildPvCheckboxesFor.
        const pvBox = card.querySelector('.vg-pvs-checkboxes');
        if (pvBox) {
          (vg.pvs || []).forEach(pvId => {
            const lbl = document.createElement('label');
            lbl.className = 'pv-option';
            lbl.innerHTML = `<input type="checkbox" name="vgs[${vi}][pvs][]" value="${pvId}" checked> <code>${pvId}</code>`;
            pvBox.appendChild(lbl);
          });
        }

        // LVs de ce VG
        const lvsForVg = (plan.logical_volumes || []).filter(lv => (lv.vg || '') === (vg.name || ''));
        const tbody = card.querySelector('.lv-rows');
        if (tbody) {
          tbody.dataset.vgName = vg.name || '';
          lvsForVg.forEach(lv => {
            const lvHtml = lvTpl.innerHTML.replace(/__LI__/g, String(globalLvIdx));
            const lvTmp = document.createElement('tbody');
            lvTmp.innerHTML = lvHtml.trim();
            const lvRow = lvTmp.firstElementChild;
            tbody.appendChild(lvRow);

            const setVal = (sel, val) => {
              const el = lvRow.querySelector(sel);
              if (el && val !== undefined && val !== null) el.value = val;
            };
            const vgInput = lvRow.querySelector('input[type="hidden"][name$="[vg]"]');
            if (vgInput) vgInput.value = vg.name || '';
            setVal('input[name$="[name]"]', lv.name || '');
            setVal('select[name$="[fs]"]', lv.fs || 'ext4');
            setVal('input[name$="[mount]"]', lv.mount || '');
            const lvFillCb = lvRow.querySelector('input[type="checkbox"][name$="[fill]"]');
            const lvSizeInput = lvRow.querySelector('input[name$="[size_mb]"]');
            const lvIsFill = !!lv.fill;
            if (lvFillCb) lvFillCb.checked = lvIsFill;
            if (lvSizeInput) {
              lvSizeInput.disabled = lvIsFill;
              // data-mb-si = source de vérité MB SI. applyUnitToInputs (en fin
              // de rebuild) formate value selon le mode courant.
              lvSizeInput.dataset.mbSi = String(lv.size_mb || 0);
              lvSizeInput.value = lvIsFill ? '' : String(lv.size_mb || 0);
            }
            setVal('.cell-reserved input', lv.reserved_pct ?? '');

            if (lvOptsTpl) {
              const optsHtml = lvOptsTpl.innerHTML.replace(/__LI__/g, String(globalLvIdx));
              const optsTmp = document.createElement('tbody');
              optsTmp.innerHTML = optsHtml.trim();
              const optsRow = optsTmp.firstElementChild;
              tbody.appendChild(optsRow);
              if (Array.isArray(lv.mount_options)) {
                const known = ['noatime','nodev','nosuid','noexec','discard'];
                const knownOpts = lv.mount_options.filter(o => known.includes(o));
                const otherOpts = lv.mount_options.filter(o => !known.includes(o));
                optsRow.querySelectorAll('input[type="checkbox"][name$="[mount_options][]"]').forEach(cb => {
                  cb.checked = knownOpts.includes(cb.value);
                });
                const otherInput = optsRow.querySelector('input[name$="[mount_options_other]"]');
                if (otherInput) otherInput.value = otherOpts.join(',');
              }
            }
            syncReservedAndMountFor(lvRow);
            syncOptionsButton(lvRow);
            globalLvIdx++;
          });
        }
      });
    }

    renumberAllPartitionIds(root);
    rebuildAllPvCheckboxes(root);
    rebuildAllRaidCheckboxes(root);
    refreshRaidMetadata(root);
    // Aligne les values des inputs sur le mode unité courant (SI/binaire)
    // depuis data-mb-si fraîchement posé par cette fonction.
    applyUnitToInputs(root);
    renderAllDiskGauges(root);
    const hint = root.querySelector('.disk-empty-hint');
    if (hint) hint.hidden = (plan.target_disks || []).length > 0;
  }

  // ====== Init ===============================================================

  /**
   * Conversion MB SI <-> MiB binaire (et inverse).
   * 1 MB SI = 10^6 bytes ; 1 MiB = 2^20 bytes. Ratio ~ 0,9537.
   */
  function mbSiToBinary(mbSi) {
    return Math.round((mbSi * 1_000_000) / (1024 * 1024));
  }
  function binaryToMbSi(mbBin) {
    return Math.round((mbBin * (1024 * 1024)) / 1_000_000);
  }

  /**
   * Applique l'unite courante (SI/binaire) aux valeurs visibles des inputs
   * `size_mb`. La source de verite reste `data-mb-si` (toujours en MB SI).
   * Appele a l'init et a chaque toggle.
   */
  /**
   * Lit la valeur MB SI d'un input size_mb.
   * Source de vérité = data-mb-si (toujours en MB SI). Fallback value
   * (utile seulement si data-mb-si absent OU si mode SI courant).
   * IMPORTANT : ne PAS utiliser parseInt(input.value) directement dans
   * les calculs — en mode binaire, input.value est en Mo binaire et
   * faussera les sommes.
   */
  function inputMbSi(input) {
    if (!input) return 0;
    // Value visible vide (utilisateur a explicitement effacé, ou fill ON
    // sans saisie) → 0, peu importe data-mb-si stocké. Garantit la
    // cohérence entre ce que l'utilisateur voit et ce que les calculs
    // de capacité interprètent.
    if (input.value === '' || input.value === null) return 0;
    const raw = (input.dataset.mbSi !== undefined && input.dataset.mbSi !== '')
      ? input.dataset.mbSi
      : input.value;
    return parseInt(raw || '0', 10) || 0;
  }
  function inputPlaceholderMbSi(input) {
    if (!input) return 0;
    return parseInt(input.dataset.placeholderMbSi || '0', 10) || 0;
  }

  /**
   * Helper : pose un placeholder MB SI sur un input et le formate
   * automatiquement selon l'unité courante. La source de vérité est
   * stockée dans data-placeholder-mb-si (lue ensuite par applyUnitToInputs).
   */
  function setPlaceholderMbSi(input, mbSi) {
    if (!input) return;
    if (mbSi > 0) {
      input.dataset.placeholderMbSi = String(mbSi);
      input.placeholder = isBinaryUnit() ? String(mbSiToBinary(mbSi)) : String(mbSi);
    } else {
      delete input.dataset.placeholderMbSi;
      input.placeholder = '';
    }
  }

  function applyUnitToInputs(root) {
    const binary = isBinaryUnit();
    root.querySelectorAll('input[name$="[size_mb]"]').forEach(input => {
      // Fallback : si data-mb-si absent (vieux HTML mis en cache navigateur),
      // initialise depuis input.value courante (supposée MB SI).
      if (input.dataset.mbSi === undefined || input.dataset.mbSi === '') {
        input.dataset.mbSi = input.value || '0';
      }
      const mbSi = parseInt(input.dataset.mbSi || '0', 10) || 0;
      // Placeholder (cas fill=true) : reformate depuis data-placeholder-mb-si.
      if (input.dataset.placeholderMbSi) {
        const phMbSi = parseInt(input.dataset.placeholderMbSi, 10) || 0;
        input.placeholder = phMbSi > 0
          ? (binary ? String(mbSiToBinary(phMbSi)) : String(phMbSi))
          : '';
      }
      if (input.disabled) { input.value = ''; return; }
      input.value = binary ? String(mbSiToBinary(mbSi)) : String(mbSi);
    });

    // Met aussi à jour les labels statiques "(MB)" / "(Mo)" dans les tables.
    root.querySelectorAll('th.size-unit-label, [data-size-unit-label]').forEach(th => {
      th.textContent = binary ? 'Taille (Mo)' : 'Taille (MB)';
    });
  }

  /**
   * Toggle MB <-> Mo : checkboxes `.js-unit-binary-toggle` (plusieurs
   * instances synchronisees dans la page). Persiste l'etat dans
   * localStorage. Convertit aussi les `value` des inputs size_mb.
   */
  function bindUnitToggle(root) {
    const checkboxes = root.querySelectorAll('.js-unit-binary-toggle');
    if (checkboxes.length === 0) return;
    let initial = false;
    try { initial = localStorage.getItem('pxe-tools.partition.unitBinary') === '1'; }
    catch (e) { initial = false; }
    checkboxes.forEach(cb => { cb.checked = initial; });
    applyUnitToInputs(root);

    checkboxes.forEach(cb => {
      cb.addEventListener('change', () => {
        try { localStorage.setItem('pxe-tools.partition.unitBinary', cb.checked ? '1' : '0'); }
        catch (e) {}
        checkboxes.forEach(other => { if (other !== cb) other.checked = cb.checked; });
        applyUnitToInputs(root);
        renderAllDiskGauges(root);
      });
    });
  }

  /**
   * Met a jour data-mb-si quand l'utilisateur tape dans un input size_mb.
   * Convertit binaire -> MB SI si le mode courant est binaire.
   */
  function bindSizeInputMbSiSync(root) {
    // useCapture=true : DOIT s'exécuter AVANT les listeners bubble qui
    // appellent renderAllDiskGauges (bindPartitionRowActions). Sinon le
    // render lit l'ancien data-mb-si et la jauge affiche un calcul stale
    // (ex: tape 100000 dans lv_var → render calcule avec ancien 10000 →
    // "29 GB utilisés" au lieu de "119 GB").
    root.addEventListener('input', (e) => {
      const el = e.target;
      if (!el.matches?.('input[name$="[size_mb]"]')) return;
      const v = parseInt(el.value || '0', 10) || 0;
      el.dataset.mbSi = String(isBinaryUnit() ? binaryToMbSi(v) : v);
    }, true);
  }

  /**
   * Lors de la sérialisation, envoie data-mb-si en MB SI sans modifier l’affichage
   * avec la sémantique DB (size_mb = MB SI partout côté serveur).
   */
  function bindFormSubmitRestoreMbSi(root) {
    const forms = root.querySelectorAll?.('form') || [];
    forms.forEach(form => {
      form.addEventListener('formdata', event => {
        form.querySelectorAll('input[name$="[size_mb]"][data-mb-si]').forEach(input => {
          if (input.disabled) return;
          event.formData.set(input.name, input.dataset.mbSi || '0');
        });
      });
    });
  }

  function initBuilders(root = document) {
    root.addEventListener('change', event => {
      if (!event.target.name?.startsWith('pre_wipe_mode[')) return;
      const summary = root.querySelector('[data-wipe-summary]');
      const count = [...root.querySelectorAll('[name^="pre_wipe_mode["]:checked')].filter(input => input.value !== 'aucun').length;
      if (summary) summary.textContent = count ? ` · ${count} disque(s) à effacer` : ' · aucun';
    });
    bindDns(root);
    bindNicDns(root);
    bindNetworkMode(root);
    bindPartitionMode(root);
    bindTargetDisks(root);
    bindPartitionRowActions(root);
    bindAutoLayout(root);
    bindRaidActions(root);
    bindVgActions(root);
    bindUnitToggle(root);
    bindSizeInputMbSiSync(root);
    bindFormSubmitRestoreMbSi(root);

    // Init state pour les rows déjà présentes
    root.querySelectorAll('.partition-row, .lv-row').forEach(row => {
      syncReservedAndMountFor(row);
      syncOptionsButton(row);
    });
    renumberAllPartitionIds(root);
    rebuildAllPvCheckboxes(root);
    rebuildAllRaidCheckboxes(root);
    refreshRaidMetadata(root);
    renderAllDiskGauges(root);
  }

  function rebuildDnsList(rowsEl, dnsValues) {
    if (!rowsEl || !Array.isArray(dnsValues)) return;
    rowsEl.innerHTML = '';
    const tpl = document.querySelector('#dns-row-tpl');
    if (!tpl) return;
    dnsValues.forEach(val => {
      const node = tpl.content.firstElementChild.cloneNode(true);
      const inp = node.querySelector('input[name="dns[]"]');
      if (inp) inp.value = val;
      rowsEl.appendChild(node);
    });
  }

  // --- Remap des disques d'un plan preset vers l'inventaire machine -----------
  // Le preset cible des paths fixes (ex /dev/sda) ; la machine découverte peut
  // n'avoir que /dev/sdb. On remappe par position (tri naturel) les disques du
  // plan vers les disques réels, en ne touchant QUE target_disks + disks[].path.
  // renumberAllPartitionIds (appelé en fin de rebuildPartitioningPlan) recalcule
  // ensuite les ids depuis le path de la card ET remappe les références
  // vgs.pvs / raid.members / spares via oldToNew → NE PAS toucher partition.id ici.
  function _normDiskPath(p) {
    p = String(p || '').trim();
    if (!p) return '';
    return p.startsWith('/dev/') ? p : '/dev/' + p.replace(/^\/+/, '');
  }
  function _naturalCmp(a, b) { return String(a).localeCompare(String(b), undefined, { numeric: true }); }

  function remapPlanToInventory(plan) {
    if (!plan || typeof plan !== 'object') return { plan: plan, info: null };
    const meta = diskMetaByPath();
    const invPaths = Object.keys(meta);
    // Disques du preset (ordre stable, normalisés, dédupliqués).
    const presetDisks = [];
    const seen = new Set();
    (plan.target_disks || []).concat((plan.disks || []).map(d => d && d.path))
      .map(_normDiskPath).forEach(p => { if (p && !seen.has(p)) { seen.add(p); presetDisks.push(p); } });
    if (presetDisks.length === 0) return { plan: plan, info: null };
    if (invPaths.length === 0) {
      return { plan: plan, info: { fatal: true, mappings: [], dropped: presetDisks.slice() } };
    }
    // Identité d'abord (paths déjà présents dans l'inventaire), puis positionnel.
    const remap = {};
    const remainingInv = invPaths.slice().sort(_naturalCmp);
    const remainingPreset = [];
    presetDisks.forEach(p => {
      const i = remainingInv.indexOf(p);
      if (i >= 0) { remap[p] = p; remainingInv.splice(i, 1); }
      else remainingPreset.push(p);
    });
    remainingPreset.sort(_naturalCmp);
    const dropped = [];
    remainingPreset.forEach((p, i) => {
      if (i < remainingInv.length) remap[p] = remainingInv[i];
      else dropped.push(p);
    });
    const droppedSet = new Set(dropped);
    const apply = p => remap[_normDiskPath(p)] || _normDiskPath(p);
    const newPlan = JSON.parse(JSON.stringify(plan));
    newPlan.target_disks = (newPlan.target_disks || []).map(apply).filter(p => !droppedSet.has(_normDiskPath(p)));
    newPlan.disks = (newPlan.disks || [])
      .filter(d => d && !droppedSet.has(_normDiskPath(d.path)))
      .map(d => Object.assign({}, d, { path: apply(d.path) }));   // partition.id INCHANGÉS
    const changed = Object.keys(remap).some(k => k !== remap[k]) || dropped.length > 0;
    if (!changed) return { plan: newPlan, info: null };
    const mappings = Object.keys(remap).filter(k => k !== remap[k]).map(from => ({
      from: from, to: remap[from], sizeBytes: meta[remap[from]] ? meta[remap[from]].size_bytes : null,
    }));
    return { plan: newPlan, info: { fatal: false, mappings: mappings, dropped: dropped } };
  }

  // A source is a complete configuration; an omitted value cannot inherit from
  // the model previously selected. Preserve explicit false, zero and empty.
  function normalizeInstallSource(source, advancedDefaults = {}) {
    const c = Object.assign({
      distro: 'debian', codename: '', version_full: '', domain: '',
      hostname: '', hostname_pattern: '', hostname_random: false,
      admin_user_login: '', admin_user_fullname: '', admin_user_groups: 'sudo',
      admin_user_pubkeys: '', admin_user_pwhash: null, admin_user_password_cipher: null,
      root_login_mode: 'disabled', root_password_hash: null, root_password_cipher: null,
      network_mode: 'dhcp', static_ip: '', static_cidr: 24, static_gateway: '', static_dns: [],
      network: {primary: 0, interfaces: []}, partition_mode: 'default', partition_custom_json: null,
      pre_wipe_mode: 'aucun', ansible_role: '', ansible_extra_vars: '',
      disk_target_constraints: {},
    }, JSON.parse(JSON.stringify(source || {})));
    const advanced = JSON.parse(JSON.stringify(advancedDefaults));
    Object.keys(advanced).forEach(section => Object.assign(advanced[section], c.advanced?.[section] || {}));
    c.advanced = advanced;
    return c;
  }

  // Preserve an unsupported historical variant for server-side preflight;
  // selecting another source must explicitly replace it as well.
  function applyInstallVariant(form, source) {
    let variant = form.querySelector('[name="variant_proxmox"]');
    if (!variant) {
      variant = form.ownerDocument.createElement('input');
      variant.type = 'hidden';
      variant.name = 'variant_proxmox';
      form.appendChild(variant);
    }
    variant.value = source?.variant === 'proxmox-ve' ? '1' : '0';
  }

  // Use the stored SI sizes for previews too, without modifying display units.
  function installFormData(form) {
    const data = new FormData(form);
    form.querySelectorAll('input[name$="[size_mb]"][data-mb-si]').forEach(input => {
      if (!input.disabled) data.set(input.name, input.dataset.mbSi || '0');
    });
    return data;
  }

  window.builderShared = {
    initBuilders,
    normalizeInstallSource,
    applyInstallVariant,
    installFormData,
    readMachineHw,
    rebuildPartitioningPlan,
    buildAutoLayoutPlan,
    rebuildDnsList,
    remapPlanToInventory,
    formatBytesGo,
  };
})();
