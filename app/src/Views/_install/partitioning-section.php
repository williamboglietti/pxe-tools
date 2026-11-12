<?php
/**
 * Partial commun « Partitionnement + Effacement avant install ».
 *
 * Source unique inclus tel quel dans :
 *   - app/src/Views/_install/wizard-form.php (étape 3 du wizard machine)
 *   - app/src/Views/presets/edit.php         (section Partitionnement du modèle)
 *
 * Garantit la parité stricte entre les deux vues : mêmes inputs, mêmes JS,
 * mêmes clés POST. Le caller passe les mêmes variables des deux côtés.
 *
 * @var array              $p                config courante (preset ou install_config)
 * @var array              $plan             plan arborescent parsé (target_disks, disks, vgs, logical_volumes, raid_arrays)
 * @var array|null         $disksInventory   liste de disques détectés (peut être vide)
 * @var int|null           $ramKb            RAM kB (pour calcul swap auto, optionnel)
 * @var bool               $isUefi           true si UEFI (pour auto-layout EFI)
 * @var array<string,string> $preWipePerDisk dict {/dev/sda => 'aucun'|'partial'|'full'}
 * @var bool                 $requireDiskReselection true pour un ancien snapshot sans identité vérifiable
 */
use Admin\View;

// Liste de disques à afficher dans la table pre-wipe :
//   - Priorité 1 : inventory réel (avec size/vendor/model)
//   - Priorité 2 : paths déclarés dans le plan (target_disks) — preset générique
//   - Vide → bandeau d'aide « Aucun disque cible »
$_wipeDisks = $disksInventory ?? [];
if (empty($_wipeDisks)) {
    foreach (($plan['target_disks'] ?? []) as $_tp) {
        $_tp = (string)$_tp;
        if ($_tp === '') continue;
        $_wipeDisks[] = ['name' => $_tp];
    }
}

// Normalise toutes les clés à /dev/X pour cohérence radio POST.
$_normPath = static function (string $p): string {
    return str_starts_with($p, '/dev/') ? $p : '/dev/' . ltrim($p, '/');
};
$_activeWipes = count(array_filter($preWipePerDisk ?? [], static fn($mode) => in_array($mode, ['partial', 'full'], true)));
?>

<input type="hidden" id="disk-identity-selection-confirmed" name="disk_identity_selection_confirmed" value="<?= !empty($requireDiskReselection) ? '0' : '1' ?>">
<?php if (!empty($requireDiskReselection)): ?>
  <div class="builder-notice error" role="alert">Cette ancienne configuration n’a pas d’identité de disque vérifiable. Relancez la découverte si nécessaire, puis choisissez explicitement le disque dans « Plan personnalisé » avant de lancer l’installation.</div>
<?php endif; ?>
<?php if (!empty($disksInventory)): ?>
  <section class="builder-section" aria-labelledby="discovered-disk-identities">
    <h4 id="discovered-disk-identities">Disques détectés et numéros de série</h4>
    <ul class="list-unstyled text-small">
      <?php foreach ($disksInventory as $_identityDisk):
        $_identityName = (string)($_identityDisk['name'] ?? '');
        $_identitySerial = trim((string)($_identityDisk['serial'] ?? ''));
        $_identityStatus = (string)($_identityDisk['identity_status'] ?? 'missing');
        $_identitySource = (string)($_identityDisk['identity_source'] ?? 'unknown');
        $_identityText = $_identityStatus === 'valid' && $_identitySerial !== ''
            ? 'N° série : ' . $_identitySerial . ' · source : ' . $_identitySource
            : ($_identityStatus === 'ambiguous' ? 'Numéro de série ambigu' : ($_identityStatus === 'legacy' ? 'Ancien inventaire · nouveau discovery requis' : 'Numéro de série indisponible'));
      ?>
        <li><code><?= View::e($_identityName) ?></code> · <?= View::e($_identityText) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php View::partial('_install/partition-builder', [
    'p' => $p,
    'plan' => $plan,
    'disksInventory' => $disksInventory ?? [],
    'ramKb' => $ramKb ?? null,
    'isUefi' => $isUefi ?? false,
]); ?>

<details class="progressive-options" <?= $_activeWipes ? 'open' : '' ?>><summary>Pré-effacement supplémentaire <span data-wipe-summary><?= $_activeWipes ? ' · ' . $_activeWipes . ' disque(s) à effacer' : ' · aucun' ?></span></summary>
<p class="form-hint">Le partitionnement remplace déjà les données des disques cibles. Un pré-effacement peut aussi toucher un autre disque : vérifiez chaque choix.</p>
<fieldset class="pre-wipe-block" style="margin-bottom:1rem;">
  <legend class="muted text-small">Avant l'installation</legend>
  <?php if (!empty($_wipeDisks)): ?>
    <p class="muted text-small">
      Choisissez le mode d'effacement par disque cible. Si au moins un disque est en Partiel/Complet,
      l'effacement sera lancé avant l'installation et celle-ci s'enchaînera automatiquement
      (un seul redémarrage manuel suffit).
    </p>
    <table class="pre-wipe-per-disk-table" style="width:100%;">
      <thead>
        <tr>
          <th>Disque</th>
          <th style="text-align:center;">Aucun</th>
          <th style="text-align:center;">Partiel <span class="muted text-small">(~14 Go)</span></th>
          <th style="text-align:center;">Complet <span class="muted text-small">(tout)</span></th>
        </tr>
      </thead>
      <tbody id="pre-wipe-per-disk-tbody">
        <?php foreach ($_wipeDisks as $_d):
          $_dname = (string)($_d['name'] ?? '');
          if ($_dname === '') continue;
          $_dkey  = $_normPath($_dname);
          $_current = $preWipePerDisk[$_dkey] ?? $preWipePerDisk[$_dname] ?? 'aucun';
          if (!in_array($_current, ['aucun','partial','full'], true)) $_current = 'aucun';
          $_size = isset($_d['size_bytes']) && $_d['size_bytes']
              ? number_format((float)$_d['size_bytes'] / 1e9, 1, ',', "\u{202F}") . "\u{00A0}Go" : '';
          $_vendorModel = trim(((string)($_d['vendor'] ?? '')) . ' ' . ((string)($_d['model'] ?? '')));
          $_meta = trim(implode(' · ', array_filter([$_vendorModel, $_size], static fn($s) => $s !== '')));
        ?>
          <tr data-disk-key="<?= View::e($_dkey) ?>">
            <td>
              <code><?= View::e($_dkey) ?></code>
              <?php if ($_meta !== ''): ?><span class="muted text-small"> · <?= View::e($_meta) ?></span><?php endif; ?>
            </td>
            <td style="text-align:center;"><input type="radio" name="pre_wipe_mode[<?= View::e($_dkey) ?>]" aria-label="Aucun pré-effacement <?= View::e($_dkey) ?>" value="aucun" <?= $_current === 'aucun' ? 'checked' : '' ?>></td>
            <td style="text-align:center;"><input type="radio" name="pre_wipe_mode[<?= View::e($_dkey) ?>]" aria-label="Pré-effacement partiel <?= View::e($_dkey) ?>" value="partial" <?= $_current === 'partial' ? 'checked' : '' ?>></td>
            <td style="text-align:center;"><input type="radio" name="pre_wipe_mode[<?= View::e($_dkey) ?>]" aria-label="Pré-effacement complet <?= View::e($_dkey) ?>" value="full" <?= $_current === 'full' ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <small class="form-hint muted" id="pre-wipe-empty-hint" hidden>
      Aucun disque cible déclaré. Ajoutez au moins un disque dans la section ci-dessous.
    </small>
  <?php else: ?>
    <p class="muted text-small" id="pre-wipe-empty-hint">
      Aucun disque cible déclaré. Ajoutez au moins un disque dans la section ci-dessous pour
      configurer l'effacement par disque.
    </p>
    <table class="pre-wipe-per-disk-table" style="width:100%;" hidden>
      <thead>
        <tr>
          <th>Disque</th>
          <th style="text-align:center;">Aucun</th>
          <th style="text-align:center;">Partiel <span class="muted text-small">(~14 Go)</span></th>
          <th style="text-align:center;">Complet <span class="muted text-small">(tout)</span></th>
        </tr>
      </thead>
      <tbody id="pre-wipe-per-disk-tbody"></tbody>
    </table>
  <?php endif; ?>
</fieldset>

</details>
