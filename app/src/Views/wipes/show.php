<?php
/**
 * View : détail d'un wipe disque.
 *
 * @var array $wipe
 * @var array $machine
 */
use Admin\View;
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2>Effacement #<?= (int)$wipe['id'] ?>
      <span class="htmx-indicator" aria-label="Mise à jour…">⏳</span>
    </h2>
    <p class="muted">
      Machine&nbsp;: <?= View::e($machine['hostname'] ?: $machine['mac']) ?>
      &nbsp;·&nbsp; Mode&nbsp;: <strong><?= View::e($wipe['wipe_mode']) ?></strong>
    </p>
  </hgroup>
  <a href="/machines" class="btn btn-secondary">← Retour</a>
</header>

<div hx-get="/wipes/<?= (int)$wipe['id'] ?>/log"
     hx-trigger="load, every 2s"
     hx-swap="innerHTML"
     hx-indicator=".htmx-indicator">
  <?php Admin\View::partial('wipes/_log', ['wipe' => $wipe]); ?>
</div>
