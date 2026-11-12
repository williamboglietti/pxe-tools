<?php
/**
 * View : liste des modèles (presets).
 *
 * @var array<int,array> $presets
 */
use Admin\View;
use Admin\Models\Setting;

$hasRepo = trim((string) (Setting::get(Setting::KEY_ANSIBLE_REPO_URL, '') ?? '')) !== '';
?>

<header class="page-heading row-flex-spread mb-1">
  <h2>Modèles (<?= count($presets) ?>)</h2>
  <div class="actions" style="flex-wrap:wrap;">
    <?php if ($hasRepo): ?>
      <form method="post" action="/presets/pull-github" style="display:inline" onsubmit="return confirm('Récupérer tous les modèles depuis le dépôt Git configuré ? Les modèles existants seront mis à jour, les nouveaux créés.');">
        <button type="submit" class="btn btn-secondary" title="Actualiser les modèles depuis le dossier déclaré par le profil Git">↻ Synchroniser depuis Git</button>
      </form>
    <?php endif; ?>
    <a href="/presets/import" class="btn btn-secondary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
      Importer
    </a>
    <?php if (!empty($presets)): ?>
    <a href="/presets/duplicate" class="btn btn-secondary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      Dupliquer
    </a>
    <?php endif; ?>
    <a href="/presets/new" class="btn btn-primary">+ Nouveau modèle</a>
  </div>
</header>

<p class="form-hint">Un modèle générique décrit un système réutilisable. Une référence matérielle aide à préparer les disques et les cartes réseau ; chaque cible sera vérifiée lors de l’application. Les copies déjà appliquées restent indépendantes.</p>

<?php if (empty($presets)): ?>
  <article>
    <p>Aucun modèle défini.</p>
    <p class="muted">Créez un modèle pour pouvoir l'assigner aux machines (distro, réseau, partition, utilisateur admin, rôle Ansible) via le bouton <strong>+ Nouveau modèle</strong> en haut à droite.</p>
  </article>
<?php else: ?>
<div class="table-scroll" role="region" aria-label="Modèles d’installation" tabindex="0" style="max-width:100%;overflow-x:auto;">
<table>
  <thead>
    <tr>
      <th>Nom</th>
      <th>Système d'exploitation</th>
      <th>Réseau</th>
      <th>Partition</th>
      <th>Utilisateur admin</th>
      <th>Rôle Ansible</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($presets as $p): ?>
    <tr>
      <td>
        <a href="/presets/<?= (int)$p['id'] ?>"><strong><?= View::e($p['name']) ?></strong></a><br><small class="muted"><?= !empty($p['based_on_machine_id']) ? 'Référence matérielle' : 'Générique' ?></small>
      </td>
      <td>
        <?= View::e($p['distro']) ?>
        <?php if (($p['variant'] ?? 'debian') === 'proxmox-ve'): ?>
          <span class="status-badge badge-neutral">Proxmox VE</span>
        <?php endif; ?>
        <?php if (!empty($p['version_full'])): ?>
          <br><small class="muted"><?= View::e($p['version_full']) ?></small>
        <?php else: ?>
          <br><small class="muted">Dernière disponible</small>
        <?php endif; ?>
      </td>
      <td>
        <?= View::e($p['network_mode']) ?>
        <?php if ($p['network_mode'] === 'static' && !empty($p['static_ip'])): ?>
          <br><small class="muted"><?= View::e($p['static_ip']) ?><?= !empty($p['static_cidr']) ? '/' . (int)$p['static_cidr'] : '' ?></small>
        <?php endif; ?>
      </td>
      <td><?= View::e($p['partition_mode'] === 'custom' ? 'Personnalisé' : 'Automatique') ?></td>
      <td><?= View::e($p['admin_user_login']) ?></td>
      <td><?= View::e($p['ansible_role'] ?? '–') ?></td>
      <td><a href="/presets/<?= (int)$p['id'] ?>" class="btn-detail" aria-label="Éditer">→</a></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
