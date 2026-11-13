<?php
use Admin\View;
use Admin\Models\OsImage;
$refresh_running = $refresh_running ?? false;
$active = $refresh_running || count(array_filter($images, static fn(array $image): bool => (in_array($image['status'], ['pending', 'downloading'], true) && (int)$image['enabled'] === 1) || OsImage::deletePending((int)$image['id']))) > 0;
?>
<section id="images-list" aria-label="Images demandées" data-refreshed-at="<?= time() ?>"
         hx-get="/api/images-status" <?= $active ? 'hx-trigger="every 3s"' : '' ?> hx-swap="outerHTML">
<?php if ($refresh_running): ?><p class="flash flash-info" role="status">Actualisation du catalogue demandée ou en cours. Les téléchargements sont suivis séparément ci-dessous.</p><?php endif; ?>
<?php if ($images === []): ?>
<div class="card empty-state">
  <h3>Aucune image demandée</h3>
  <p class="muted">Le catalogue recense les versions disponibles chez les éditeurs. Ajoutez une image pour demander son téléchargement sur l’appliance.</p>
  <a href="/settings/images/add" class="btn btn-primary">Parcourir le catalogue</a>
</div>
<?php else: ?>
<div class="grid gap-4">
<?php foreach ($images as $img):
    $id = (int)$img['id'];
    $deleting = OsImage::deletePending($id);
    $status = (string)$img['status'];
    $label = ucfirst($img['distro']) . ' ' . ($img['version_full'] ?: $img['version']) . ' · ' . $img['arch'];
    $dependencies = $img['dependencies'] ?? OsImage::dependencies($id);
    $available = $status === 'downloaded' && $dependencies['available'];
    $blocked = $dependencies['blocking'];
    $enabled = (int)$img['enabled'] === 1;
    $toggleLabel = $enabled ? 'Désactiver' : 'Activer';
    $toggleBlock = OsImage::activationBlock($img, $dependencies, !$enabled);
    $downloadBlock = OsImage::downloadBlock($img, $dependencies);
    $downloadLabel = $status === 'pending' ? 'Demander le téléchargement' : 'Relancer le téléchargement';
    $restrictions = $blocked !== [] ? $blocked : array_values(array_unique(array_filter([$toggleBlock, $downloadBlock])));
    $pct = max(0, min(100, (int)($img['progress_percent'] ?? 0)));
    $statusLabel = match ($status) {
        'downloaded' => $available ? ((int)$img['enabled'] === 1 ? 'Disponible' : 'Fichiers présents · désactivée') : 'Fichiers absents ou illisibles',
        'downloading' => 'Téléchargement en cours', 'failed' => 'Échec du téléchargement',
        default => (int)$img['enabled'] === 1 ? 'Téléchargement en attente' : 'Téléchargement désactivé',
    };
?>
<article class="card" id="image-<?= $id ?>">
  <header class="flex flex-wrap items-start justify-between gap-3 mb-3">
    <div><h3 class="m-0"><?= View::distroLogo($img['distro']) ?> <?= View::e($label) ?></h3>
      <?php if (!empty($img['codename'])): ?><p class="muted text-small"><?= View::e($img['codename']) ?></p><?php endif; ?>
    </div>
    <span class="badge <?= $available ? 'badge-success' : ($status === 'failed' || $status === 'downloaded' ? 'badge-warning' : 'badge-neutral') ?>"><?= View::e($statusLabel) ?></span>
  </header>
  <p class="muted text-small">Activation : <strong><?= $enabled ? 'activée' : 'désactivée' ?></strong>. Les fichiers téléchargés sont conservés lorsque l’image est désactivée.</p>
  <?php if ($status === 'downloading'): ?>
    <progress max="100" value="<?= $pct ?>" aria-label="Téléchargement de <?= View::e($label) ?>"><?= $pct ?> %</progress>
    <p class="muted text-small"><?= $pct ?> % · <?= View::e((string)($img['message'] ?? 'En attente d’information du worker')) ?></p>
    <?php if (!empty($img['stale'])): ?><p class="flash flash-warning">Aucune progression reçue depuis <?= (int)$img['age_s'] ?> secondes. Consultez l’état du worker avant de demander une relance.</p><?php endif; ?>
  <?php elseif ($status === 'failed'): ?>
    <p class="flash flash-error"><?= View::e($img['error_msg'] ?: 'Le worker n’a pas fourni de détail.') ?></p>
  <?php elseif ($status === 'downloaded'): ?>
    <p class="muted text-small">Le worker a enregistré un téléchargement le <?= View::frenchDateTime($img['downloaded_at'] ?? null) ?>.
      <?= $dependencies['available'] ? 'Kernel et initrd lisibles par le portail.' : 'Le portail ne peut pas lire le kernel ou l’initrd ; vérifiez le volume ou relancez le téléchargement.' ?>
      <?= !empty($img['size_bytes']) ? View::formatBytes((int)$img['size_bytes']) : '' ?></p>
  <?php endif; ?>
  <?php if ($restrictions !== []): ?><p id="image-restrictions-<?= $id ?>" class="muted text-small"><strong>Actions protégées :</strong> <?= View::e(implode(' ', $restrictions)) ?></p><?php endif; ?>
  <details id="image-uses-<?= $id ?>" class="mb-4">
    <summary>Usages connus (<?= count($dependencies['usages']) ?>)</summary>
    <?php if ($dependencies['usages'] === []): ?><p class="muted">Aucun modèle ni configuration de machine ne référence cette image.</p>
    <?php else: ?><ul><?php foreach ($dependencies['usages'] as $use): ?><li>
      <?php if ($use['url']): ?><a href="<?= View::e($use['url']) ?>"><?= View::e($use['label']) ?></a><?php else: ?><?= View::e($use['label']) ?><?php endif; ?>
    </li><?php endforeach; ?></ul><?php endif; ?>
    <p class="muted text-small">Les configurations sans version figée peuvent utiliser cette image. Une suppression ne modifie pas leurs réglages.</p>
  </details>
  <div class="actions flex-wrap">
    <form method="post" action="/settings/images/<?= $id ?>/toggle">
      <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
      <button type="submit" id="image-toggle-<?= $id ?>" class="btn btn-secondary" aria-label="<?= View::e($toggleLabel . ' ' . $label) ?>"
        <?= $toggleBlock !== null ? 'disabled aria-describedby="image-restrictions-' . $id . '"' : '' ?>><?= View::e($toggleLabel) ?></button>
    </form>
    <?php if ($status !== 'downloading' || !empty($img['stale'])): ?>
    <form method="post" action="/settings/images/<?= $id ?>/download">
      <button type="submit" id="image-download-<?= $id ?>" class="btn btn-secondary" aria-label="<?= View::e($downloadLabel . ' · ' . $label) ?>"
        <?= $downloadBlock !== null ? 'disabled aria-describedby="image-restrictions-' . $id . '"' : '' ?>><?= View::e($downloadLabel) ?></button>
    </form>
    <?php endif; ?>
    <form method="post" action="/settings/images/<?= $id ?>"
      onsubmit="return confirm(<?= View::e(json_encode('Supprimer ' . $label . ' et ses fichiers locaux ? ' . count($dependencies['usages']) . ' usage(s) enregistré(s). Consultez les usages avant de continuer.')) ?>);">
      <input type="hidden" name="_method" value="DELETE">
      <button type="submit" id="image-delete-<?= $id ?>" class="btn btn-danger" <?= $blocked !== [] || $deleting ? 'disabled' : '' ?> aria-label="<?= View::e('Supprimer ' . $label) ?>"><?= $deleting ? 'Suppression en cours…' : 'Supprimer' ?></button>
    </form>
  </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
