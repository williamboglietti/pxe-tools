/* Contextual names survive the storage/network builders' clones and renumbering. */
(() => {
  'use strict';
  const value = (scope, selector) => scope?.querySelector(selector)?.value?.trim() || '';
  const rank = (element, selector) => [...element.parentElement.querySelectorAll(selector)].indexOf(element) + 1;
  function context(control) {
    const nic = control.closest('.nic-card');
    if (nic) return nic.dataset.a11yNetwork || 'Interface réseau';
    let row = control.closest('.partition-row,.lv-row');
    if (!row && control.closest('.partition-options-row')) row = control.closest('.partition-options-row').previousElementSibling;
    if (row?.matches('.partition-row')) {
      const disk = row.closest('.disk-card');
      const id = value(row, 'input[name$="[id]"]') || String(rank(row, '.partition-row'));
      return 'Disque ' + (disk?.dataset.diskPath || 'à définir') + ' · partition ' + id;
    }
    const vg = control.closest('.vg-card');
    const vgName = value(vg, '.vg-name-input') || 'sans nom';
    if (row?.matches('.lv-row')) return 'Groupe ' + vgName + ' · volume ' + (value(row, 'input[name$="[name]"]') || String(rank(row, '.lv-row')));
    if (vg) return 'Groupe ' + vgName;
    const target = control.closest('.target-disk-row');
    if (target) return value(target, '.target-disk-path-input') || target.dataset.diskPath || 'disque ' + rank(target, '.target-disk-row');
    const disk = control.closest('.disk-card');
    if (disk) return 'Disque ' + (disk.dataset.diskPath || 'à définir');
    if (control.closest('#dns-list')) return 'interface PXE';
    return '';
  }
  let previousPrimary = null;
  function refreshNetworkSummaries() {
    document.querySelectorAll('[data-nic-disclosure]').forEach(panel => {
      const card = panel.querySelector('.nic-card');
      const summary = panel.querySelector('[data-network-summary]');
      if (!card || !summary) return;
      const primary = card.querySelector('[data-nic-primary-radio]')?.checked;
      const mode = value(card, '[data-nic-mode-radio]:checked');
      const parts = [card.dataset.a11yNetwork || 'Interface réseau'];
      if (primary) parts.push('Primaire');
      parts.push(mode === 'static' ? 'Statique · ' + value(card, '[name$="[static][ip]"]') + '/' + value(card, '[name$="[static][cidr]"]') : mode === 'disabled' ? 'Désactivée' : 'DHCP');
      const vlan = value(card, '[name$="[vlan][id]"]');
      const bond = value(card, '[name$="[bond][name]"]');
      if (vlan) parts.push('VLAN ' + vlan);
      if (bond) parts.push('Agrégation ' + bond + ' · ' + value(card, '[name$="[bond][role]"]'));
      const text = parts.join(' · ');
      if (summary.textContent !== text) summary.textContent = text;
      if (primary && previousPrimary !== panel) { panel.open = true; previousPrimary = panel; }
    });
  }
  function refresh() {
    refreshNetworkSummaries();
    document.querySelectorAll('[data-a11y-label]').forEach(control => {
      let label = control.dataset.a11yLabel;
      const dnsRow = control.closest('.dns-row');
      if (dnsRow) label += ' ' + rank(dnsRow, '.dns-row');
      if (control.hasAttribute('data-a11y-size')) {
        label = control.closest('table')?.querySelector('[data-size-unit-label]')?.textContent.trim() || label;
      }
      const suffix = context(control);
      const name = label + (suffix ? ' · ' + suffix : '');
      if (control.getAttribute('aria-label') !== name) control.setAttribute('aria-label', name);
    });
  }
  let scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    queueMicrotask(() => { scheduled = false; refresh(); });
  }
  function start() {
    refresh();
    document.addEventListener('input', schedule);
    document.addEventListener('change', schedule);
    new MutationObserver(schedule).observe(document.body, {
      subtree: true, childList: true, characterData: true,
      attributes: true, attributeFilter: ['name', 'value', 'data-disk-path'],
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
  else start();
})();
