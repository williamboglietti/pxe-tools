<?php
/**
 * Partial — bloc partitionnement v2 (multi-disques + LVM multi-VG, RAID stub Phase B).
 * Variables injectées par View::partial() via extract().
 *
 * @var array  $p          config courante (partition_mode, partition_custom_json)
 * @var array  $plan       plan arborescent parsé (target_disks, disks, vgs, logical_volumes, raid_arrays)
 *
 * Optionnels :
 * @var array|null $disksInventory  liste de disques détectés (pour multi-checkbox)
 * @var int|null   $ramKb           RAM kB (pour calcul swap auto)
 * @var bool|null  $isUefi          true si UEFI (auto-layout EFI)
 */
use Admin\View;
use Admin\Models\Preset;

$EXT_FS = ['ext2', 'ext3', 'ext4'];
$STD_FS = Preset::ALLOWED_FS_STANDARD; // pour <select> partitions standards
$LV_FS  = Preset::ALLOWED_FS_LV;       // pour <select> LV (pas d'efi)

// Libellé d'affichage pour le <option> du select fs. Override les valeurs où strtoupper
// ne donne pas un rendu propre (ex: lvm-pv → LVM, raid-member → RAID).
$fsLabel = static fn(string $fs): string => match ($fs) {
    'lvm-pv'      => 'LVM',
    'raid-member' => 'RAID',
    default       => strtoupper($fs),
};

$optionLabels = [
    'noatime' => 'Pas de mise à jour atime (perf)',
    'nodev'   => 'Pas de fichiers device',
    'nosuid'  => 'Pas de setuid (sécurité)',
    'noexec'  => 'Pas d\'exécution (sécurité)',
    'discard' => 'TRIM SSD (discard)',
];

$_inv = $disksInventory ?? [];
$_diskLabel = static function (array $d): string {
    $vendorModel = trim(((string)($d['vendor'] ?? '')) . ' ' . ((string)($d['model'] ?? '')));
    $identity = is_array($d['identity'] ?? null) ? $d['identity'] : [];
    $serial = trim((string)(($identity['kind'] ?? '') === 'wwid' ? ($identity['value'] ?? '') : ($d['serial'] ?? $identity['value'] ?? '')));
    $identityStatus = (string)($d['identity_status'] ?? $identity['status'] ?? 'missing');
    $source = (string)($d['identity_source'] ?? $identity['source'] ?? 'unknown');
    $sourceLabel = match ($source) {
        'sysfs' => 'sysfs', 'sysfs-nvme' => 'sysfs NVMe', 'sysfs-wwid' => 'WWID SCSI Hyper-V', 'smartctl' => 'smartctl', 'legacy' => 'ancien inventaire', default => '',
    };
    $identityLabel = $identityStatus === 'valid' && $serial !== ''
        ? (($identity['kind'] ?? '') === 'wwid' ? 'WWID ' : 'N° série ') . $serial . ($sourceLabel !== '' ? ' · ' . $sourceLabel : '')
        : ($identityStatus === 'ambiguous' ? 'N° série ambigu' : 'N° série indisponible');
    $type = isset($d['is_ssd']) ? ($d['is_ssd'] === true ? 'SSD' : ($d['is_ssd'] === false ? 'HDD' : '')) : '';
    return implode(' · ', array_filter([$vendorModel, $type, $identityLabel], static fn($s) => $s !== ''));
};
$_diskPath = static function (array $d): string {
    $path = (string)($d['name'] ?? '');
    if ($path !== '' && !str_starts_with($path, '/dev/')) $path = '/dev/' . $path;
    return $path;
};

$targetDisks = $plan['target_disks'] ?? [];
$disksData   = $plan['disks'] ?? [];
$vgsData     = $plan['vgs'] ?? [];
$lvsData     = $plan['logical_volumes'] ?? [];

// Index path → disque inventory (pour pré-remplir la capacité).
$_invByPath = [];
foreach ($_inv as $_d) {
    $p_ = $_diskPath($_d);
    if ($p_ !== '') $_invByPath[$p_] = $_d;
}
// Index path → capacité assumée stockée dans plan.disks[i].assumed_size_gb
// (fallback quand pas d'inventory réel — preset générique).
$_assumedByPath = [];
foreach ($disksData as $_d) {
    $p_ = (string)($_d['path'] ?? '');
    if ($p_ !== '' && isset($_d['assumed_size_gb']) && $_d['assumed_size_gb']) {
        $_assumedByPath[$p_] = (int)$_d['assumed_size_gb'];
    }
}
// Liste unifiée des disques à afficher en lignes :
//   - inventory : tous les disques détectés (cochés si dans target_disks)
//   - sinon : tous les paths déclarés dans target_disks (depuis le plan SQL)
$_rows = [];
if (!empty($_inv)) {
    foreach ($_inv as $_d) {
        $_path = $_diskPath($_d);
        if ($_path === '') continue;
        $_rows[] = [
            'path'        => $_path,
            'checked'     => in_array($_path, $targetDisks, true),
            'info'        => $_diskLabel($_d),
            'identity'    => is_array($_d['identity'] ?? null) ? (string)($_d['identity']['value'] ?? '') : '',
            'identity_status' => (string)($_d['identity_status'] ?? (is_array($_d['identity'] ?? null) ? ($_d['identity']['status'] ?? 'missing') : 'missing')),
            'capacity_gb' => isset($_d['size_bytes']) && $_d['size_bytes']
                ? (int)round((float)$_d['size_bytes'] / 1e9)
                : ($_assumedByPath[$_path] ?? null),
            'path_locked' => true,
        ];
    }
} else {
    foreach ($targetDisks as $_path) {
        $_path = (string)$_path;
        if ($_path === '') continue;
        $_rows[] = [
            'path'        => $_path,
            'checked'     => true,
            'info'        => '',
            'capacity_gb' => $_assumedByPath[$_path] ?? null,
            'path_locked' => false,
        ];
    }
}

// Index disque path => partitions (depuis $plan)
$partitionsByDisk = [];
foreach ($disksData as $d) {
    $partitionsByDisk[$d['path'] ?? ''] = $d['partitions'] ?? [];
}
?>
<div class="storage-overview">
  <h4>Disques identifiés</h4>
  <?php if ($_rows): ?><ul><?php foreach ($_rows as $_r): ?><li><code><?= View::e($_r['path']) ?></code> · <?= View::e($_r['info'] ?: 'identité non renseignée') ?> · <?= $_r['capacity_gb'] !== null ? (int)$_r['capacity_gb'] . ' Go' : 'capacité inconnue' ?></li><?php endforeach; ?></ul>
  <?php else: ?><p>Inventaire absent. Le disque sera choisi par l’installateur en mode automatique ; déclarez un plan personnalisé pour cibler des chemins précis.</p><?php endif; ?>
  <?php if (count($_invByPath) > 1): ?><p class="flash flash-warning" data-persistent>Plusieurs disques détectés : choisissez explicitement les disques dans Plan personnalisé. Vous pouvez ensuite appliquer le schéma LVM proposé.</p><?php endif; ?>
  <p class="form-hint">Le mode automatique utilise le premier disque détecté et remplace ses données. Le mode personnalisé limite le partitionnement aux disques cochés.</p>
</div>
<div role="group" style="margin-bottom:0.75rem;">
  <label><input type="radio" name="partition_mode" value="default" <?= $p['partition_mode'] === 'default' ? 'checked' : '' ?>> Automatique · LVM sur le premier disque</label>
  <label><input type="radio" name="partition_mode" value="custom" <?= $p['partition_mode'] === 'custom' ? 'checked' : '' ?>> Plan personnalisé · choisir les disques</label>
</div>

<div id="partitions-builder" class="partitions-builder" <?= $p['partition_mode'] === 'custom' ? '' : 'hidden' ?>>

  <!-- Suggestions de points de montage (calque Debian d-i partman) — saisie libre conservée. -->
  <datalist id="mount-points-list">
    <option value="/"></option>
    <option value="/boot"></option>
    <option value="/boot/efi"></option>
    <option value="/home"></option>
    <option value="/tmp"></option>
    <option value="/usr"></option>
    <option value="/usr/local"></option>
    <option value="/var"></option>
    <option value="/var/log"></option>
    <option value="/srv"></option>
    <option value="/opt"></option>
  </datalist>

  <!-- Avis de remap disque (modèle ciblait un disque absent → remappé sur le disque
       réel de l'inventaire). Rempli par install-wizard.js applyConfig. -->
  <div id="disk-remap-notice" class="builder-notice" role="status" hidden></div>

  <!-- ======================================================== -->
  <!-- SECTION 1 — Disques cibles (table unifiée)                -->
  <!-- ======================================================== -->
  <fieldset class="builder-section">
    <legend>Disques cibles</legend>
    <p class="muted text-small">
      <?php if (!empty($_inv)): ?>
        Capacité issue de la discovery matérielle (lecture seule). Cochez les disques à partitionner.
      <?php else: ?>
        Mode modèle générique : déclarez manuellement les chemins et capacités estimées des disques
        cibles. La capacité sert au calcul de l'espace libre et au placeholder « Remplir ».
      <?php endif; ?>
    </p>
    <div class="table-scroll"><table class="target-disks-table" style="width:100%;">
      <thead>
        <tr>
          <th scope="col" style="width:4rem;text-align:center;">Inclure</th>
          <th scope="col">Chemin</th>
          <th scope="col" style="width:9rem;">Capacité (Go)</th>
          <th scope="col">Détails</th>
          <th scope="col" style="width:3rem;"></th>
        </tr>
      </thead>
      <tbody id="target-disks-tbody">
        <?php foreach ($_rows as $_idx => $_r): ?>
          <tr class="target-disk-row" data-disk-path="<?= View::e($_r['path']) ?>">
            <td style="text-align:center;">
              <input type="checkbox" name="target_disks[]" data-a11y-label="Inclure le disque" aria-label="Inclure le disque · <?= View::e($_r['path']) ?>" value="<?= View::e($_r['path']) ?>" data-disk-identity="<?= View::e($_r['identity'] ?? '') ?>" data-disk-identity-status="<?= View::e($_r['identity_status'] ?? 'missing') ?>" <?= $_r['checked'] ? 'checked' : '' ?>>
            </td>
            <?php if ($_r['path_locked']): ?>
              <!-- Mode inventaire : path + capa en lecture seule, source = discovery. -->
              <td><code><?= View::e($_r['path']) ?></code></td>
              <td>
                <span class="muted text-small">
                  <?= $_r['capacity_gb'] !== null ? (int)$_r['capacity_gb'] . "\u{00A0}Go" : '—' ?>
                </span>
              </td>
            <?php else: ?>
              <!-- Mode modèle générique : path + capa éditables. -->
              <td>
                <input type="text" class="target-disk-path-input" name="target_disks_paths[<?= $_idx ?>]" data-a11y-label="Chemin du disque" aria-label="Chemin du disque · <?= View::e($_r['path']) ?>" value="<?= View::e($_r['path']) ?>" placeholder="/dev/sda">
              </td>
              <td>
                <input type="number" class="target-disk-capacity-input" name="disk_capacity_gb[<?= View::e($_r['path']) ?>]" data-a11y-label="Capacité (Go) estimée" aria-label="Capacité (Go) estimée · <?= View::e($_r['path']) ?>" value="<?= $_r['capacity_gb'] !== null ? (int)$_r['capacity_gb'] : '' ?>" min="1" step="1" placeholder="120" data-disk-path="<?= View::e($_r['path']) ?>">
              </td>
            <?php endif; ?>
            <td><span class="muted text-small"><?= View::e($_r['info']) ?></span></td>
            <td style="text-align:center;">
              <?php if (!$_r['path_locked']): ?>
                <button type="button" class="btn-icon-only btn-remove btn-target-disk-remove" data-a11y-label="Supprimer ce disque" aria-label="Supprimer ce disque" title="Supprimer">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (empty($_inv)): ?>
      <p class="add-row-bar">
        <button type="button" class="btn-add-target-disk secondary outline" id="add-target-disk">+ Ajouter un disque</button>
        <span class="muted text-small">— preset générique : déclarez manuellement les disques cibles attendus.</span>
      </p>
    <?php endif; ?>
    <small class="form-hint">Les partitions de chaque disque coché peuvent être configurées dans la section ci-dessous.</small>
  </fieldset>

  <!-- Template : ligne disque cible (mode preset générique / ajout dynamique) -->
  <template id="target-disk-row-tpl">
    <tr class="target-disk-row" data-disk-path="">
      <td style="text-align:center;">
        <input type="checkbox" name="target_disks[]" data-a11y-label="Inclure le disque" aria-label="Inclure le disque · disque à ajouter" value="" checked>
      </td>
      <td>
        <input type="text" class="target-disk-path-input" name="target_disks_paths[__I__]" data-a11y-label="Chemin du disque" aria-label="Chemin du disque · disque à ajouter" value="" placeholder="/dev/sda">
      </td>
      <td>
        <input type="number" class="target-disk-capacity-input" name="disk_capacity_gb[]" data-a11y-label="Capacité (Go) estimée" aria-label="Capacité (Go) estimée · disque à ajouter" min="1" step="1" placeholder="120">
      </td>
      <td><span class="muted text-small"></span></td>
      <td style="text-align:center;">
        <button type="button" class="btn-icon-only btn-remove btn-target-disk-remove" data-a11y-label="Supprimer ce disque" aria-label="Supprimer ce disque" title="Supprimer">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </td>
    </tr>
  </template>

  <!-- ======================================================== -->
  <!-- SECTION 2 — Partitions par disque                          -->
  <!-- ======================================================== -->
  <fieldset class="builder-section">
    <legend>Partitions</legend>
    <div class="partitions-toolbar">
      <button type="button" id="partition-auto-layout" class="btn btn-secondary"><?= View::icon('sparkles') ?> Créer un plan LVM</button>
      <span class="muted text-small">Sur le disque cible sélectionné : EFI 512 MB (ou BIOS GRUB), /boot 1 GB, puis LVM avec / 16 GB, swap 3 GB et /var sur l'espace restant.</span>
      <label class="unit-toggle" title="MB / GB = convention SI Debian manuel. Mo / Go = convention binaire française historique.">
        <input type="checkbox" class="js-unit-binary-toggle">
        Afficher en Mo / Go (binaire)
      </label>
    </div>

    <div id="disks-partitions-container">
      <?php
      // Render une "carte" par disque dans target_disks (et au moins une carte template-ready par disque inventaire pour soutenir l'ajout JS).
      $rendered = [];
      foreach ($targetDisks as $diskIdx => $diskPath):
        $rendered[] = $diskPath;
        $partitions = $partitionsByDisk[$diskPath] ?? [];
      ?>
        <article class="disk-card" data-disk-path="<?= View::e($diskPath) ?>" data-disk-idx="<?= $diskIdx ?>">
          <header><strong>Disque <?= View::e($diskPath) ?></strong></header>
          <div class="disk-gauge-slot" data-gauge-slot></div>
          <input type="hidden" name="disks[<?= $diskIdx ?>][path]" value="<?= View::e($diskPath) ?>">
          <details class="progressive-options" <?= empty($partitions) ? 'open' : '' ?>><summary>Modifier les partitions et leurs options</summary>
          <div class="table-scroll"><table class="partitions-table">
            <thead>
              <tr>
                <th scope="col">ID</th>
                <th scope="col">Système de fichier</th>
                <th scope="col">Point de montage</th>
                <th scope="col" data-size-unit-label>Taille (MB)</th>
                <th scope="col">Remplir</th>
                <th scope="col">Réserve %</th>
                <th scope="col"></th>
              </tr>
            </thead>
            <tbody class="partition-rows" data-disk-idx="<?= $diskIdx ?>">
              <?php foreach ($partitions as $pIdx => $pt):
                $opts = $pt['mount_options'] ?? [];
                $knownOpts = array_keys($optionLabels);
                $otherOpts = array_values(array_diff($opts, $knownOpts));
                $isPv = ($pt['fs'] ?? '') === 'lvm-pv';
              ?>
                <tr class="partition-row">
                  <td>
                    <input type="hidden" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][id]" value="<?= View::e($pt['id'] ?? '') ?>">
                    <code class="part-id-display"><?= View::e($pt['id'] ?? '?') ?></code>
                  </td>
                  <td>
                    <select name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][fs]" data-a11y-label="Système de fichier (FS)" aria-label="Système de fichier (FS) · <?= View::e('Disque ' . $diskPath . ' · partition ' . ($pt['id'] ?? ($pIdx + 1))) ?>">
                      <?php foreach (Preset::ALLOWED_FS as $fs): ?>
                        <option value="<?= $fs ?>" <?= $pt['fs'] === $fs ? 'selected' : '' ?>><?= View::e($fsLabel($fs)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="text" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][mount]" data-a11y-label="Point de montage" aria-label="Point de montage · <?= View::e('Disque ' . $diskPath . ' · partition ' . ($pt['id'] ?? ($pIdx + 1))) ?>" value="<?= View::e($pt['mount'] ?? '') ?>" placeholder="/" list="mount-points-list" autocomplete="off" <?= $isPv ? 'disabled' : '' ?>></td>
                  <td><input type="number" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][size_mb]" data-a11y-label="Taille (MB)" aria-label="Taille (MB) · <?= View::e('Disque ' . $diskPath . ' · partition ' . ($pt['id'] ?? ($pIdx + 1))) ?>" data-a11y-size value="<?= (int)$pt['size_mb'] ?>" data-mb-si="<?= (int)$pt['size_mb'] ?>" min="1" <?= !empty($pt['fill']) ? 'disabled' : '' ?>><small class="size-max-hint muted" data-size-max-hint="part" hidden> · max <strong>—</strong></small></td>
                  <td class="cell-center">
                    <input type="hidden" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][fill]" value="0">
                    <input type="checkbox" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][fill]" data-a11y-label="Remplir l’espace restant" aria-label="Remplir l’espace restant · <?= View::e('Disque ' . $diskPath . ' · partition ' . ($pt['id'] ?? ($pIdx + 1))) ?>" value="1" <?= !empty($pt['fill']) ? 'checked' : '' ?>>
                  </td>
                  <td class="cell-reserved">
                    <input type="number" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][reserved_pct]" data-a11y-label="Réserve %" aria-label="Réserve % · <?= View::e('Disque ' . $diskPath . ' · partition ' . ($pt['id'] ?? ($pIdx + 1))) ?>" value="<?= isset($pt['reserved_pct']) && $pt['reserved_pct'] !== null ? (int)$pt['reserved_pct'] : '' ?>" min="0" max="50" step="1" placeholder="—" style="width:4.5rem;" <?= $isPv ? 'disabled' : '' ?>>
                  </td>
                  <td class="cell-center">
                    <button type="button" class="btn-icon-only btn-options-toggle" data-a11y-label="Options de montage" aria-label="Options de montage" title="Options de montage">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    </button>
                    <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer cette partition" aria-label="Supprimer cette partition" title="Supprimer">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </td>
                </tr>
                <tr class="partition-options-row" hidden>
                  <td colspan="7">
                    <fieldset class="partition-options">
                      <legend>Options de montage</legend>
                      <div class="options-checkboxes">
                        <?php foreach ($optionLabels as $key => $lbl): ?>
                          <label>
                            <input type="checkbox" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][mount_options][]" value="<?= $key ?>" <?= in_array($key, $opts, true) ? 'checked' : '' ?>>
                            <code><?= $key ?></code> <span class="muted text-small"><?= View::e($lbl) ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                      <label class="options-other">
                        Autres options <span class="muted">(CSV : <code>relatime,barrier=0</code>)</span>
                        <input type="text" name="disks[<?= $diskIdx ?>][partitions][<?= $pIdx ?>][mount_options_other]" value="<?= View::e(implode(',', $otherOpts)) ?>" placeholder="">
                      </label>
                    </fieldset>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
          <p class="add-row-bar">
            <button type="button" class="btn-add-partition secondary outline" data-a11y-label="Ajouter une partition" data-disk-idx="<?= $diskIdx ?>">+ Ajouter une partition</button>
            <span class="add-row-bar__hint muted" data-free-hint></span>
          </p>
          </details>
        </article>
      <?php endforeach; ?>
    </div>

    <p class="muted text-small disk-empty-hint" <?= empty($targetDisks) ? '' : 'hidden' ?>>Veuillez sélectionner au moins un disque cible ci-dessus pour commencer.</p>
  </fieldset>

  <!-- ======================================================== -->
  <!-- SECTION 3 — Arrays RAID                                    -->
  <!-- ======================================================== -->
  <details class="progressive-options" data-storage-raid <?= !empty($plan['raid_arrays']) ? 'open' : '' ?>><summary>Avancé · RAID <span data-raid-summary></span></summary>
  <fieldset class="builder-section">
    <legend>Arrays RAID</legend>
    <p class="muted text-small">
      Pipeline : partitions <code>raid-member</code> → array MD (mdadm) → soit montage direct, soit PV LVM.
      Marquer une partition <code>raid-member</code> dans la section Partitions pour qu'elle apparaisse ci-dessous.
    </p>
    <div id="raid-arrays-container">
      <?php foreach ($plan['raid_arrays'] ?? [] as $rIdx => $arr): ?>
        <article class="raid-card" data-raid-idx="<?= $rIdx ?>">
          <header class="raid-header">
            <div class="raid-header__main">
              <label class="raid-field">
                <span class="raid-field__label">Nom</span>
                <input type="text" name="raid_arrays[<?= $rIdx ?>][name]" value="<?= View::e($arr['name'] ?? '') ?>" placeholder="md0" class="raid-name-input">
              </label>
              <label class="raid-field">
                <span class="raid-field__label">Niveau</span>
                <select name="raid_arrays[<?= $rIdx ?>][level]" class="raid-level-select">
                  <?php foreach (Preset::RAID_LEVELS as $level): ?>
                    <option value="<?= $level ?>" <?= ($arr['level'] ?? 1) === $level ? 'selected' : '' ?>>RAID <?= $level ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <div class="raid-field raid-field--summary">
                <span class="raid-field__label">Capacité utile</span>
                <span class="raid-capacity" data-raid-capacity>—</span>
              </div>
            </div>
            <button type="button" class="btn-icon-only btn-remove" aria-label="Supprimer cet array" title="Supprimer">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </header>
          <p class="raid-level-hint" data-raid-level-hint></p>
          <div class="raid-section">
            <div class="raid-section__head">
              <strong>Membres actifs</strong>
              <span class="raid-section__count" data-raid-members-count></span>
            </div>
            <div class="raid-members-checkboxes" data-raid-idx="<?= $rIdx ?>">
              <?php foreach ($arr['members'] ?? [] as $m): ?>
                <label class="member-option"><input type="checkbox" name="raid_arrays[<?= $rIdx ?>][members][]" value="<?= View::e($m) ?>" checked> <code><?= View::e($m) ?></code></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="raid-section">
            <div class="raid-section__head">
              <strong>Disques de secours</strong>
              <span class="muted text-small">(optionnel — pris automatiquement à la place d'un membre défaillant)</span>
            </div>
            <div class="raid-spares-checkboxes" data-raid-idx="<?= $rIdx ?>">
              <?php foreach ($arr['spares'] ?? [] as $s): ?>
                <label class="spare-option"><input type="checkbox" name="raid_arrays[<?= $rIdx ?>][spares][]" value="<?= View::e($s) ?>" checked> <code><?= View::e($s) ?></code></label>
              <?php endforeach; ?>
            </div>
          </div>
          <label class="raid-is-pv">
            <input type="hidden" name="raid_arrays[<?= $rIdx ?>][is_pv]" value="0">
            <input type="checkbox" name="raid_arrays[<?= $rIdx ?>][is_pv]" value="1" <?= !empty($arr['is_pv']) ? 'checked' : '' ?>>
            <span>Utiliser comme PV LVM <span class="muted text-small">— l'array devient un PV référençable par un VG ci-dessous</span></span>
          </label>
        </article>
      <?php endforeach; ?>
    </div>
    <button type="button" id="raid-add" class="btn btn-secondary">+ Ajouter un array RAID</button>
  </fieldset>
  </details>

  <!-- ======================================================== -->
  <!-- SECTION 4 — Volume Groups + Logical Volumes                -->
  <!-- ======================================================== -->
  <details class="progressive-options" data-storage-lvm <?= !empty($vgsData) ? 'open' : '' ?>><summary>Avancé · volumes LVM <span data-lvm-summary></span></summary>
  <fieldset class="builder-section">
    <legend>Volume Groups LVM</legend>
    <div class="partitions-toolbar">
      <span class="muted text-small">Configurez vos VGs et leurs LVs ci-dessous.</span>
      <label class="unit-toggle" title="MB / GB = convention SI Debian manuel. Mo / Go = convention binaire française historique.">
        <input type="checkbox" class="js-unit-binary-toggle">
        Afficher en Mo / Go (binaire)
      </label>
    </div>
    <div id="vgs-container">
      <?php foreach ($vgsData as $vgIdx => $vg):
        $vgName = $vg['name'] ?? '';
        $vgPvs  = $vg['pvs'] ?? [];
        $vgLvs  = array_filter($lvsData, fn($lv) => ($lv['vg'] ?? '') === $vgName);
        $vgLvs  = array_values($vgLvs);
      ?>
        <article class="vg-card" data-vg-idx="<?= $vgIdx ?>">
          <header class="vg-header">
            <input type="text" name="vgs[<?= $vgIdx ?>][name]" data-a11y-label="Nom du groupe de volumes" aria-label="Nom du groupe de volumes · <?= View::e($vgName) ?>" value="<?= View::e($vgName) ?>" placeholder="system_VG" class="vg-name-input" data-vg-idx="<?= $vgIdx ?>">
            <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer ce VG" aria-label="Supprimer ce VG" title="Supprimer">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </header>
          <div class="vg-pvs">
            <strong>PV membres :</strong>
            <div class="vg-pvs-checkboxes" data-vg-idx="<?= $vgIdx ?>">
              <?php
              // Liste des PV disponibles : reconstruit côté JS au render. En SSR, on inclut les PVs déjà sélectionnés.
              foreach ($vgPvs as $pvId): ?>
                <label class="pv-option"><input type="checkbox" name="vgs[<?= $vgIdx ?>][pvs][]" value="<?= View::e($pvId) ?>" checked> <code><?= View::e($pvId) ?></code></label>
              <?php endforeach; ?>
            </div>
            <small class="form-hint">Les PV disponibles seront listés ici (partitions <code>lvm-pv</code> + arrays RAID <code>is_pv</code>).</small>
          </div>
          <p class="vg-capacity muted" data-vg-capacity hidden>
            <span class="vg-capacity__used"><strong>— Mo</strong> utilisés</span>
            <span class="vg-capacity__sep">·</span>
            <span class="vg-capacity__free"><strong>— Mo</strong> libres</span>
            <span class="vg-capacity__sep">·</span>
            <span class="vg-capacity__total"><strong>— Mo</strong> total</span>
          </p>
          <div class="vg-lvs">
            <strong>Logical Volumes :</strong>
            <p class="vg-lvs__hint muted" hidden>Sélectionnez au moins un PV membre pour configurer les Logical Volumes.</p>
            <div class="table-scroll"><table class="lv-table">
              <thead>
                <tr>
                  <th scope="col">Nom du LV</th>
                  <th scope="col">FS</th>
                  <th scope="col">Point de montage</th>
                  <th scope="col" data-size-unit-label>Taille (MB)</th>
                  <th scope="col">Remplir</th>
                  <th scope="col">Réserve %</th>
                  <th scope="col"></th>
                </tr>
              </thead>
              <tbody class="lv-rows" data-vg-name="<?= View::e($vgName) ?>">
                <?php foreach ($vgLvs as $lv):
                  // L'index global $lvIdx pour name="logical_volumes[<idx>][...]"
                  $globalLvIdx = array_search($lv, $lvsData, true);
                  $opts = $lv['mount_options'] ?? [];
                  $knownOpts = array_keys($optionLabels);
                  $otherOpts = array_values(array_diff($opts, $knownOpts));
                ?>
                  <tr class="lv-row">
                    <td><input type="hidden" name="logical_volumes[<?= $globalLvIdx ?>][vg]" value="<?= View::e($vgName) ?>"><input type="text" name="logical_volumes[<?= $globalLvIdx ?>][name]" data-a11y-label="Nom du LV (volume logique)" aria-label="Nom du LV (volume logique) · <?= View::e('Groupe ' . $vgName . ' · volume ' . ($lv['name'] ?? 'sans nom')) ?>" value="<?= View::e($lv['name'] ?? '') ?>" placeholder="root"></td>
                    <td>
                      <select name="logical_volumes[<?= $globalLvIdx ?>][fs]" data-a11y-label="Système de fichier (FS)" aria-label="Système de fichier (FS) · <?= View::e('Groupe ' . $vgName . ' · volume ' . ($lv['name'] ?? 'sans nom')) ?>">
                        <?php foreach ($LV_FS as $fs): ?>
                          <option value="<?= $fs ?>" <?= ($lv['fs'] ?? '') === $fs ? 'selected' : '' ?>><?= View::e($fsLabel($fs)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="text" name="logical_volumes[<?= $globalLvIdx ?>][mount]" data-a11y-label="Point de montage" aria-label="Point de montage · <?= View::e('Groupe ' . $vgName . ' · volume ' . ($lv['name'] ?? 'sans nom')) ?>" value="<?= View::e($lv['mount'] ?? '') ?>" placeholder="/" list="mount-points-list" autocomplete="off"></td>
                    <td><input type="number" name="logical_volumes[<?= $globalLvIdx ?>][size_mb]" data-a11y-label="Taille (MB)" aria-label="Taille (MB) · <?= View::e('Groupe ' . $vgName . ' · volume ' . ($lv['name'] ?? 'sans nom')) ?>" data-a11y-size value="<?= (int)($lv['size_mb'] ?? 0) ?>" data-mb-si="<?= (int)($lv['size_mb'] ?? 0) ?>" min="1" <?= !empty($lv['fill']) ? 'disabled' : '' ?>><small class="size-max-hint muted" data-size-max-hint="lv" hidden> · max <strong>—</strong></small></td>
                    <td class="cell-center">
                      <input type="hidden" name="logical_volumes[<?= $globalLvIdx ?>][fill]" value="0">
                      <input type="checkbox" name="logical_volumes[<?= $globalLvIdx ?>][fill]" data-a11y-label="Remplir l’espace restant" aria-label="Remplir l’espace restant · <?= View::e('Groupe ' . $vgName . ' · volume ' . ($lv['name'] ?? 'sans nom')) ?>" value="1" <?= !empty($lv['fill']) ? 'checked' : '' ?>>
                    </td>
                    <td class="cell-reserved">
                      <input type="number" name="logical_volumes[<?= $globalLvIdx ?>][reserved_pct]" data-a11y-label="Réserve %" aria-label="Réserve % · <?= View::e('Groupe ' . $vgName . ' · volume ' . ($lv['name'] ?? 'sans nom')) ?>" value="<?= isset($lv['reserved_pct']) && $lv['reserved_pct'] !== null ? (int)$lv['reserved_pct'] : '' ?>" min="0" max="50" step="1" placeholder="—" style="width:4.5rem;">
                    </td>
                    <td class="cell-center">
                      <button type="button" class="btn-icon-only btn-options-toggle" data-a11y-label="Options de montage" aria-label="Options de montage" title="Options de montage">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                      </button>
                      <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer ce LV" aria-label="Supprimer ce LV" title="Supprimer">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      </button>
                    </td>
                  </tr>
                  <tr class="partition-options-row" hidden>
                    <td colspan="7">
                      <fieldset class="partition-options">
                        <legend>Options de montage</legend>
                        <div class="options-checkboxes">
                          <?php foreach ($optionLabels as $key => $lbl): ?>
                            <label>
                              <input type="checkbox" name="logical_volumes[<?= $globalLvIdx ?>][mount_options][]" value="<?= $key ?>" <?= in_array($key, $opts, true) ? 'checked' : '' ?>>
                              <code><?= $key ?></code> <span class="muted text-small"><?= View::e($lbl) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                        <label class="options-other">
                          Autres options <span class="muted">(CSV : <code>relatime,barrier=0</code>)</span>
                          <input type="text" name="logical_volumes[<?= $globalLvIdx ?>][mount_options_other]" value="<?= View::e(implode(',', $otherOpts)) ?>" placeholder="">
                        </label>
                      </fieldset>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
            <p class="add-row-bar">
              <button type="button" class="btn-add-lv secondary outline" data-a11y-label="Ajouter un LV" data-vg-idx="<?= $vgIdx ?>">+ Ajouter un LV</button>
              <span class="add-row-bar__hint muted" data-free-hint></span>
            </p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <button type="button" id="vg-add" class="btn btn-secondary">+ Ajouter un Volume Group</button>
  </fieldset>
  </details>
</div>

<!-- =========================================================================== -->
<!-- TEMPLATES POUR L'AJOUT JS DYNAMIQUE                                          -->
<!-- =========================================================================== -->

<!-- Template : carte disque complète (utilisée quand on coche un disque cible) -->
<template id="disk-card-tpl">
  <article class="disk-card" data-disk-path="" data-disk-idx="__DI__">
    <header><strong>Disque <span class="disk-card-path"></span></strong></header>
    <div class="disk-gauge-slot" data-gauge-slot></div>
    <input type="hidden" name="disks[__DI__][path]" class="disk-path-input" value="">
    <details class="progressive-options" open><summary>Modifier les partitions et leurs options</summary>
    <div class="table-scroll"><table class="partitions-table">
      <thead>
        <tr>
          <th scope="col">ID</th>
          <th scope="col">Système de fichier</th>
          <th scope="col">Point de montage</th>
          <th scope="col" data-size-unit-label>Taille (MB)</th>
          <th scope="col">Remplir</th>
          <th scope="col">Réserve %</th>
          <th scope="col"></th>
        </tr>
      </thead>
      <tbody class="partition-rows" data-disk-idx="__DI__"></tbody>
    </table></div>
    <p class="add-row-bar">
      <button type="button" class="btn-add-partition secondary outline" data-a11y-label="Ajouter une partition" data-disk-idx="__DI__">+ Ajouter une partition</button>
      <span class="add-row-bar__hint muted" data-free-hint></span>
    </p>
    </details>
  </article>
</template>

<!-- Template : ligne partition -->
<template id="partition-row-tpl">
  <tr class="partition-row">
    <td>
      <input type="hidden" name="disks[__DI__][partitions][__PI__][id]" value="">
      <code class="part-id-display">?</code>
    </td>
    <td>
      <select name="disks[__DI__][partitions][__PI__][fs]" data-a11y-label="Système de fichier (FS)" aria-label="Système de fichier (FS) · partition à ajouter">
        <?php foreach (Preset::ALLOWED_FS as $fs): ?>
          <option value="<?= $fs ?>" <?= $fs === 'ext4' ? 'selected' : '' ?>><?= View::e($fsLabel($fs)) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="disks[__DI__][partitions][__PI__][mount]" data-a11y-label="Point de montage" aria-label="Point de montage · partition à ajouter" placeholder="/" list="mount-points-list" autocomplete="off"></td>
    <td><input type="number" name="disks[__DI__][partitions][__PI__][size_mb]" data-a11y-label="Taille (MB)" aria-label="Taille (MB) · partition à ajouter" data-a11y-size min="1" value="1000" data-mb-si="1000"><small class="size-max-hint muted" data-size-max-hint="part" hidden> · max <strong>—</strong></small></td>
    <td class="cell-center">
      <input type="hidden" name="disks[__DI__][partitions][__PI__][fill]" value="0">
      <input type="checkbox" name="disks[__DI__][partitions][__PI__][fill]" data-a11y-label="Remplir l’espace restant" aria-label="Remplir l’espace restant · partition à ajouter" value="1">
    </td>
    <td class="cell-reserved">
      <input type="number" name="disks[__DI__][partitions][__PI__][reserved_pct]" data-a11y-label="Réserve %" aria-label="Réserve % · partition à ajouter" min="0" max="50" step="1" placeholder="—" style="width:4.5rem;">
    </td>
    <td class="cell-center">
      <button type="button" class="btn-icon-only btn-options-toggle" data-a11y-label="Options de montage" aria-label="Options de montage" title="Options de montage">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </button>
      <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer cette partition" aria-label="Supprimer cette partition" title="Supprimer">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </td>
  </tr>
</template>

<template id="partition-options-row-tpl">
  <tr class="partition-options-row" hidden>
    <td colspan="7">
      <fieldset class="partition-options">
        <legend>Options de montage</legend>
        <div class="options-checkboxes">
          <?php foreach ($optionLabels as $key => $lbl): ?>
            <label>
              <input type="checkbox" name="disks[__DI__][partitions][__PI__][mount_options][]" value="<?= $key ?>">
              <code><?= $key ?></code> <span class="muted text-small"><?= View::e($lbl) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <label class="options-other">
          Autres options <span class="muted">(CSV : <code>relatime,barrier=0</code>)</span>
          <input type="text" name="disks[__DI__][partitions][__PI__][mount_options_other]" placeholder="">
        </label>
      </fieldset>
    </td>
  </tr>
</template>

<!-- Template : carte VG -->
<template id="vg-card-tpl">
  <article class="vg-card" data-vg-idx="__VI__">
    <header class="vg-header">
      <input type="text" name="vgs[__VI__][name]" data-a11y-label="Nom du groupe de volumes" aria-label="Nom du groupe de volumes · groupe à ajouter" value="" placeholder="system_VG" class="vg-name-input" data-vg-idx="__VI__">
      <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer ce VG" aria-label="Supprimer ce VG" title="Supprimer">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </header>
    <div class="vg-pvs">
      <strong>PV membres :</strong>
      <div class="vg-pvs-checkboxes" data-vg-idx="__VI__"></div>
      <small class="form-hint">Les PV disponibles seront listés ici (partitions <code>lvm-pv</code> + arrays RAID <code>is_pv</code>).</small>
    </div>
    <p class="vg-capacity muted" data-vg-capacity hidden>
      <span class="vg-capacity__used"><strong>— Mo</strong> utilisés</span>
      <span class="vg-capacity__sep">·</span>
      <span class="vg-capacity__free"><strong>— Mo</strong> libres</span>
      <span class="vg-capacity__sep">·</span>
      <span class="vg-capacity__total"><strong>— Mo</strong> total</span>
    </p>
    <div class="vg-lvs">
      <strong>Logical Volumes :</strong>
      <p class="vg-lvs__hint muted" hidden>Sélectionnez au moins un PV membre pour configurer les Logical Volumes.</p>
      <div class="table-scroll"><table class="lv-table">
        <thead>
          <tr>
            <th scope="col">Nom du LV</th>
            <th scope="col">FS</th>
            <th scope="col">Point de montage</th>
            <th scope="col" data-size-unit-label>Taille (MB)</th>
            <th scope="col">Remplir</th>
            <th scope="col">Réserve %</th>
            <th scope="col"></th>
          </tr>
        </thead>
        <tbody class="lv-rows" data-vg-name=""></tbody>
      </table></div>
      <p class="add-row-bar">
        <button type="button" class="btn-add-lv secondary outline" data-a11y-label="Ajouter un LV" data-vg-idx="__VI__">+ Ajouter un LV</button>
        <span class="add-row-bar__hint muted" data-free-hint></span>
      </p>
    </div>
  </article>
</template>

<!-- Template : ligne LV -->
<template id="lv-row-tpl">
  <tr class="lv-row">
    <td><input type="hidden" name="logical_volumes[__LI__][vg]" value=""><input type="text" name="logical_volumes[__LI__][name]" data-a11y-label="Nom du LV (volume logique)" aria-label="Nom du LV (volume logique) · volume logique à ajouter" value="" placeholder="root"></td>
    <td>
      <select name="logical_volumes[__LI__][fs]" data-a11y-label="Système de fichier (FS)" aria-label="Système de fichier (FS) · volume logique à ajouter">
        <?php foreach ($LV_FS as $fs): ?>
          <option value="<?= $fs ?>" <?= $fs === 'ext4' ? 'selected' : '' ?>><?= View::e($fsLabel($fs)) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="logical_volumes[__LI__][mount]" data-a11y-label="Point de montage" aria-label="Point de montage · volume logique à ajouter" placeholder="/" list="mount-points-list" autocomplete="off"></td>
    <td><input type="number" name="logical_volumes[__LI__][size_mb]" data-a11y-label="Taille (MB)" aria-label="Taille (MB) · volume logique à ajouter" data-a11y-size min="1" value="0" data-mb-si="0"><small class="size-max-hint muted" data-size-max-hint="lv" hidden> · max <strong>—</strong></small></td>
    <td class="cell-center">
      <input type="hidden" name="logical_volumes[__LI__][fill]" value="0">
      <input type="checkbox" name="logical_volumes[__LI__][fill]" data-a11y-label="Remplir l’espace restant" aria-label="Remplir l’espace restant · volume logique à ajouter" value="1">
    </td>
    <td class="cell-reserved">
      <input type="number" name="logical_volumes[__LI__][reserved_pct]" data-a11y-label="Réserve %" aria-label="Réserve % · volume logique à ajouter" min="0" max="50" step="1" placeholder="—" style="width:4.5rem;">
    </td>
    <td class="cell-center">
      <button type="button" class="btn-icon-only btn-options-toggle" data-a11y-label="Options de montage" aria-label="Options de montage" title="Options de montage">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </button>
      <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer ce LV" aria-label="Supprimer ce LV" title="Supprimer">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </td>
  </tr>
</template>

<template id="lv-options-row-tpl">
  <tr class="partition-options-row" hidden>
    <td colspan="7">
      <fieldset class="partition-options">
        <legend>Options de montage</legend>
        <div class="options-checkboxes">
          <?php foreach ($optionLabels as $key => $lbl): ?>
            <label>
              <input type="checkbox" name="logical_volumes[__LI__][mount_options][]" value="<?= $key ?>">
              <code><?= $key ?></code> <span class="muted text-small"><?= View::e($lbl) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <label class="options-other">
          Autres options <span class="muted">(CSV : <code>relatime,barrier=0</code>)</span>
          <input type="text" name="logical_volumes[__LI__][mount_options_other]" placeholder="">
        </label>
      </fieldset>
    </td>
  </tr>
</template>

<!-- Template : carte RAID -->
<template id="raid-card-tpl">
  <article class="raid-card" data-raid-idx="__RI__">
    <header class="raid-header">
      <div class="raid-header__main">
        <label class="raid-field">
          <span class="raid-field__label">Nom</span>
          <input type="text" name="raid_arrays[__RI__][name]" value="" placeholder="md0" class="raid-name-input">
        </label>
        <label class="raid-field">
          <span class="raid-field__label">Niveau</span>
          <select name="raid_arrays[__RI__][level]" class="raid-level-select">
            <?php foreach (Preset::RAID_LEVELS as $level): ?>
              <option value="<?= $level ?>" <?= $level === 1 ? 'selected' : '' ?>>RAID <?= $level ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="raid-field raid-field--summary">
          <span class="raid-field__label">Capacité utile</span>
          <span class="raid-capacity" data-raid-capacity>—</span>
        </div>
      </div>
      <button type="button" class="btn-icon-only btn-remove" aria-label="Supprimer cet array" title="Supprimer">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </header>
    <p class="raid-level-hint" data-raid-level-hint></p>
    <div class="raid-section">
      <div class="raid-section__head">
        <strong>Membres actifs</strong>
        <span class="raid-section__count" data-raid-members-count></span>
      </div>
      <div class="raid-members-checkboxes" data-raid-idx="__RI__"></div>
    </div>
    <div class="raid-section">
      <div class="raid-section__head">
        <strong>Disques de secours</strong>
        <span class="muted text-small">(optionnel — pris automatiquement à la place d'un membre défaillant)</span>
      </div>
      <div class="raid-spares-checkboxes" data-raid-idx="__RI__"></div>
    </div>
    <label class="raid-is-pv">
      <input type="hidden" name="raid_arrays[__RI__][is_pv]" value="0">
      <input type="checkbox" name="raid_arrays[__RI__][is_pv]" value="1">
      <span>Utiliser comme PV LVM <span class="muted text-small">— l'array devient un PV référençable par un VG ci-dessous</span></span>
    </label>
  </article>
</template>
