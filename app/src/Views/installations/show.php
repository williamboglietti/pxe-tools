<?php
/**
 * View : détail d'une installation.
 *
 * @var array $install
 * @var array $machine
 */
use Admin\View;
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2>Installation #<?= (int)$install['id'] ?>
      <span class="htmx-indicator" aria-label="Mise à jour…">⏳</span>
    </h2>
    <p class="muted">
      Machine&nbsp;: <?= View::e($machine['hostname'] ?: $machine['mac']) ?>
    </p>
  </hgroup>
  <div style="display:flex; gap:.5rem;">
    <?php if (($install['install_status'] ?? '') === 'in_progress'): ?>
      <form method="post" action="/installations/<?= (int)$install['id'] ?>/cancel" style="margin:0;"
            onsubmit="return confirm('Clôturer le suivi de cette installation ? Aucun arrêt du processus distant ne peut être garanti. L\'action parente et tous ses sous-jobs (wipe, install, ansible) seront marqués comme annulés. La machine devra être réinstallée manuellement pour repartir.');">
        <button type="submit" class="btn danger">Clôturer le suivi</button>
      </form>
    <?php endif; ?>
    <a href="/installations" class="btn btn-secondary">← Retour</a>
  </div>
</header>

<p class="freshness" data-freshness role="status">Dernière actualisation : <?= gmdate('H:i:s') ?> UTC</p>
<div id="installation-log" data-poll-scope hx-get="/installations/<?= (int)$install['id'] ?>/log"
     hx-trigger="load, every 2s"
     hx-swap="innerHTML"
     hx-indicator=".htmx-indicator">
  <?php Admin\View::partial('installations/_log', ['install' => $install]); ?>
</div>
