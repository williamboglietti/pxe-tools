<?php
/**
 * View : liste des installations (100 dernières).
 *
 * @var array<int,array> $installations
 */
use Admin\View;
?>

<header class="page-heading"><div><p class="eyebrow">Activité</p><h1>Installations</h1><p class="page-intro">Les 100 installations les plus récentes. Retrouvez toutes les opérations dans Activité.</p></div><a href="/activity?type=install" class="btn btn-secondary">Toutes les installations →</a></header>

<?php if (empty($installations)): ?>
  <article>
    <p>Aucune installation enregistrée.</p>
    <p class="muted">Configurez un modèle puis lancez une machine en PXE — les installations apparaîtront ici.</p>
  </article>
<?php else: ?>
<div class="table-card card"><table>
  <thead>
    <tr>
      <th>#</th>
      <th>Machine</th>
      <th>Démarrée</th>
      <th>Terminée</th>
      <th>Installation</th>
      <th>Ansible</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($installations as $i): ?>
    <tr>
      <td><?= (int)$i['id'] ?></td>
      <td><?= View::e($i['hostname'] ?: $i['mac']) ?></td>
      <td class="text-small"><?= View::e($i['started_at'] ?? '–') ?></td>
      <td class="text-small"><?= View::e($i['ended_at'] ?? '–') ?></td>
      <td>
        <?php if (!empty($i['install_status'])): ?>
          <span class="status-badge <?= View::e(View::statusClass($i['install_status'])) ?>"><?= View::e(View::statusLabel($i['install_status'])) ?></span>
        <?php else: ?><span class="muted">–</span><?php endif; ?>
      </td>
      <td>
        <?php if (!empty($i['ansible_status'])): ?>
          <span class="status-badge <?= View::e(View::statusClass($i['ansible_status'])) ?>"><?= View::e(View::statusLabel($i['ansible_status'])) ?></span>
        <?php else: ?><span class="muted">–</span><?php endif; ?>
      </td>
      <td><a href="/installations/<?= (int)$i['id'] ?>" class="btn-detail" aria-label="Voir le journal de <?= View::e($i['hostname'] ?: $i['mac']) ?>">→</a></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
