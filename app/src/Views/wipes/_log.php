<?php
/**
 * Partial — log streaming d'un wipe disque.
 *
 * @var array $wipe
 */
use Admin\View;
$disks = json_decode($wipe['disks_json'] ?? '[]', true) ?: [];
// disks_json est [{name, mode}] depuis le passage au mode per-disque (commit c66d1de) ;
// implode direct sortait "Array" parce que chaque entrée est un sous-array.
$disksLabel = implode(', ', array_map(
    static fn(array $d) => ($d['name'] ?? '?') . ' (' . ($d['mode'] ?? '?') . ')',
    $disks
));
?>

<article>
  <header>
    Statut&nbsp;:
    <span class="status-badge <?= View::e(View::statusClass($wipe['wipe_status'] ?? null)) ?>">
      <?= View::e(View::statusLabel($wipe['wipe_status'])) ?>
    </span>
    &nbsp;·&nbsp; Disques&nbsp;: <code><?= View::e($disksLabel) ?></code>
    <br>
    <small class="muted">
      Démarré <?= View::e($wipe['started_at'] ?? '–') ?>
      · Terminé <?= View::e($wipe['ended_at'] ?? '–') ?>
    </small>
  </header>
  <pre class="log"><?= View::e($wipe['log'] ?? '(en attente du démarrage de l\'effacement sur la machine cible…)') ?></pre>
</article>
