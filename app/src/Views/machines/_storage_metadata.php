<?php
/** Informational media metadata; this partial never offers disk target controls. */
use Admin\View;
use Admin\Util\DiskIdentity;

$metadata = DiskIdentity::storageMetadata($disk);
$mmc = $metadata['mmc'];
$collection = $metadata['metadata_collection'];
$nativeMmc = preg_match('~^(?:/dev/)?mmcblk[0-9]+$~D', is_string($disk['name'] ?? null) ? $disk['name'] : '') === 1;
$mediaLabel = match (strtoupper(trim((string)($mmc['type'] ?? '')))) {
    'MMC' => 'eMMC',
    'SD' => 'SD natif',
    default => $nativeMmc ? 'MMC/SD natif · type indisponible' : 'Support',
};
$valueHtml = static fn(?string $value): string => $value !== null
    ? '<code>' . View::e($value) . '</code>' : '<span class="muted">Indisponible</span>';
$partitions = $metadata['partitions'];
$rawFilesystem = $metadata['filesystem'];
$hasRawFilesystem = count(array_filter($rawFilesystem, static fn($value) => $value !== null)) > 0;
?>
<details class="disk-storage-metadata">
  <summary><?= View::e($mediaLabel) ?> · partitions, UUID et métadonnées</summary>
  <?php if ($collection === null): ?>
    <p class="muted text-small">Ancien inventaire : re-collecte nécessaire pour obtenir les UUID et les champs MMC.</p>
  <?php endif; ?>
  <?php if ($nativeMmc): ?>
    <dl class="text-small">
      <dt>Type natif</dt><dd><?= $valueHtml($mmc['type'] ?? null) ?></dd>
      <dt>Nom MMC/SD</dt><dd><?= $valueHtml($mmc['name'] ?? null) ?></dd>
      <dt>CID complet</dt><dd><?= $valueHtml($mmc['cid'] ?? null) ?></dd>
      <dt>CSD</dt><dd><?= $valueHtml($mmc['csd'] ?? null) ?></dd>
      <dt>Fabricant (manfid)</dt><dd><?= $valueHtml($mmc['manfid'] ?? null) ?></dd>
      <dt>OEM (oemid)</dt><dd><?= $valueHtml($mmc['oemid'] ?? null) ?></dd>
      <dt>Date</dt><dd><?= $valueHtml($mmc['date'] ?? null) ?></dd>
      <dt>Révision (fwrev)</dt><dd><?= $valueHtml($mmc['fwrev'] ?? null) ?></dd>
      <dt>life_time · valeur brute</dt><dd><?= $valueHtml($mmc['life_time'] ?? null) ?></dd>
      <dt>pre_eol_info · valeur brute</dt><dd><?= $valueHtml($mmc['pre_eol_info'] ?? null) ?></dd>
    </dl>
    <p class="muted text-small">Ces indicateurs bruts ne constituent pas un résultat de test de santé. Le CID est distinct du numéro de série et des UUID de systèmes de fichiers.</p>
    <?php if (($collection['mmc_status'] ?? '') === 'unavailable'): ?>
      <p class="muted text-small">Métadonnées MMC/SD indisponibles lors de la collecte.</p>
    <?php endif; ?>
  <?php elseif (strtolower(trim((string)($disk['transport'] ?? ''))) === 'usb'): ?>
    <p class="muted text-small">CID natif non exposé par le transport USB. Le lecteur ne permet pas d’établir le type SD/eMMC du support.</p>
  <?php endif; ?>

  <?php if (($collection['filesystem_status'] ?? '') === 'unavailable'): ?>
    <p class="muted text-small">UUID et systèmes de fichiers indisponibles lors de la collecte.</p>
  <?php elseif (($collection['filesystem_status'] ?? '') === 'error'): ?>
    <p class="muted text-small">La lecture des métadonnées de partitions a échoué.</p>
  <?php endif; ?>
  <?php if ($partitions !== [] || $hasRawFilesystem): ?>
    <div class="table-scroll">
      <table class="text-small">
        <thead><tr><th>Partition / support</th><th>Taille</th><th>Format</th><th>UUID</th><th>PARTUUID</th><th>Libellé</th></tr></thead>
        <tbody>
          <?php if ($hasRawFilesystem): ?>
            <tr>
              <td>Disque entier</td><td>—</td>
              <td><?= $valueHtml($rawFilesystem['fstype']) ?></td><td><?= $valueHtml($rawFilesystem['uuid']) ?></td>
              <td><?= $valueHtml($rawFilesystem['partuuid']) ?></td><td><?= $valueHtml($rawFilesystem['label']) ?></td>
            </tr>
          <?php endif; ?>
          <?php foreach ($partitions as $partition): ?>
            <tr>
              <td><?= $valueHtml($partition['path'] ?? $partition['name']) ?><?php if ($partition['number'] !== null): ?> · n° <?= (int)$partition['number'] ?><?php endif; ?></td>
              <td><?= $partition['size_bytes'] !== null ? View::e(View::formatBytes($partition['size_bytes'])) : '<span class="muted">Indisponible</span>' ?></td>
              <td><?= $valueHtml($partition['fstype']) ?></td><td><?= $valueHtml($partition['uuid']) ?></td>
              <td><?= $valueHtml($partition['partuuid']) ?></td><td><?= $valueHtml($partition['label']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php elseif (($collection['filesystem_status'] ?? '') === 'available'): ?>
    <p class="muted text-small">Aucune partition ni signature de système de fichiers identifiée par la collecte.</p>
  <?php endif; ?>
  <?php if (!empty($mmc['hardware_regions'])): ?>
    <p class="text-small"><strong>Zones matérielles exclues des cibles · information</strong></p>
    <ul class="text-small">
      <?php foreach ($mmc['hardware_regions'] as $region): ?>
        <li><code><?= View::e($region['path']) ?></code><?php if ($region['parent_path'] !== null): ?> · parent : <code><?= View::e($region['parent_path']) ?></code><?php endif; ?> · <?= $region['size_bytes'] !== null ? View::e(View::formatBytes($region['size_bytes'])) : 'taille indisponible' ?> · force_ro : <?= $valueHtml($region['force_ro'] !== null ? (string)$region['force_ro'] : null) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</details>
