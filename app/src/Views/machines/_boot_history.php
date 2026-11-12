<?php
use Admin\Models\BootSession;
use Admin\View;

/** @var array $machine */
$boots = BootSession::recentForMachine((int)$machine['id']);
$latest = $boots[0] ?? null;
?>
<article class="panel">
  <header><h3>Dernier menu GRUB</h3></header>
  <?php if ($latest === null): ?>
    <p class="muted">Aucun menu suivi depuis l'activation de l'historique.</p>
  <?php else: ?>
    <?php $selected = $latest['selected_menu'] !== null; ?>
    <p style="margin-bottom:.35rem;">
      <strong><?= View::e(BootSession::label((string)($selected ? $latest['selected_menu'] : $latest['default_menu']))) ?></strong>
      · <?= $selected ? 'entrée lancée' : 'menu servi par défaut, sélection non confirmée' ?>
    </p>
    <p class="muted text-small" style="margin:0;">
      Configuration servie le <?= View::e(View::frenchDateTime((string)$latest['served_at'])) ?>
      <?php if ($selected): ?> · entrée lancée le <?= View::e(View::frenchDateTime((string)$latest['selected_at'])) ?><?php endif; ?>
    </p>
    <details style="margin-top:.75rem;">
      <summary>Historique récent des menus GRUB</summary>
      <div style="overflow-x:auto;">
        <table style="margin-top:.75rem;">
          <thead><tr><th>Configuration servie</th><th>Menu par défaut</th><th>Entrée lancée</th></tr></thead>
          <tbody>
          <?php foreach ($boots as $boot): ?>
            <tr>
              <td><?= View::e(View::frenchDateTime((string)$boot['served_at'])) ?></td>
              <td><?= View::e(BootSession::label((string)$boot['default_menu'])) ?></td>
              <td><?= $boot['selected_menu'] !== null
                  ? View::e(BootSession::label((string)$boot['selected_menu']))
                  : '<span class="muted">Non confirmé</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>
</article>
