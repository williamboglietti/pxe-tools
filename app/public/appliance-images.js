/* Keep controls and open usage panels stable while download states refresh. */
(() => {
  const message = document.getElementById('images-freshness');
  if (!message) return;
  let openPanels = [];
  document.body.addEventListener('htmx:beforeSwap', event => {
    if (event.detail.target?.id !== 'images-list') return;
    if (event.detail.target.contains(document.activeElement)) {
      event.detail.shouldSwap = false;
      message.textContent = 'Mise à jour en pause pendant votre interaction. Elle reprendra en quittant la liste.';
      return;
    }
    openPanels = [...event.detail.target.querySelectorAll('details[open]')].map(panel => panel.id);
  });
  document.body.addEventListener('htmx:afterSwap', event => {
    if (event.detail.target?.id !== 'images-list') return;
    openPanels.forEach(id => { const panel = document.getElementById(id); if (panel) panel.open = true; });
    message.textContent = 'État actualisé à ' + new Date().toLocaleTimeString('fr-FR') + '.';
  });
  ['htmx:responseError', 'htmx:sendError', 'htmx:timeout'].forEach(name => {
    document.body.addEventListener(name, event => {
      if (event.detail.elt?.id === 'images-list' || event.detail.target?.id === 'images-list') {
        message.textContent = 'Actualisation indisponible. Les états affichés peuvent être anciens. Réessayez ou rechargez cette page.';
      }
    });
  });
})();
