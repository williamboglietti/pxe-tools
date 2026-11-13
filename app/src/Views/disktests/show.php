<?php
/**
 * Page de suivi d'un job de tests de disque.
 *
 * @var array $test    DiskTest::find($id)
 * @var array $machine machine résumée (hostname, mac)
 */
use Admin\View;
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2>Test de disque #<?= (int)$test['id'] ?>
      <span class="htmx-indicator" aria-label="Mise à jour…">⏳</span>
    </h2>
    <p class="muted">
      Machine&nbsp;: <?= View::e($machine['hostname'] ?: $machine['mac']) ?>
      &nbsp;·&nbsp; <?= ((int)($test['parallel'] ?? 0) === 1) ? 'parallèle' : 'séquentiel' ?>
      &nbsp;·&nbsp; après tests : <strong><?= View::e($test['post_action'] ?? 'poweroff') ?></strong>
    </p>
  </hgroup>
  <a href="/machines" class="btn btn-secondary">← Retour</a>
</header>

<?php /* Le fragment se re-poll lui-même (2 s actif / 5 s pending / stop terminal). */ ?>
<?php Admin\View::partial('disktests/_log', ['test' => $test]); ?>
