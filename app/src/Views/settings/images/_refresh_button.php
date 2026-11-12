<?php
use Admin\View;
/**
 * Bouton "Actualiser le catalogue" avec auto-refresh HTMX quand un job tourne.
 * Quand $refresh_running=true, le wrapper polle /settings/images/refresh-button
 * toutes les 3s pour se mettre à jour quand le worker a fini → swap auto vers
 * l'état idle sans rechargement manuel de la page.
 *
 * @var bool $refresh_running
 */
?>
<div id="refresh-button-wrapper"
     <?php if ($refresh_running): ?>
     hx-get="/settings/images/refresh-button"
     hx-trigger="every 3s"
     hx-swap="outerHTML"
     <?php endif; ?>>
  <form method="post" action="/settings/images/refresh" style="margin:0">
    <button type="submit"
            class="btn btn-secondary <?= $refresh_running ? 'is-refreshing' : '' ?>"
            <?= $refresh_running ? 'disabled aria-busy="true"' : '' ?>>
      <?= View::icon('refresh-cw') ?>
      <?= $refresh_running ? 'Actualisation en cours…' : 'Actualiser le catalogue' ?>
    </button>
  </form>
</div>
