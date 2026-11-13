<?php
/**
 * Partial — APT (composantes/services) + Bootloader GRUB + Finish.
 *
 * @var array $advApt        section 'apt' (non_free_firmware, contrib, non_free, services_select[], security_host, hw_detect_load_firmware)
 * @var array $advBootloader section 'bootloader' (only_debian, with_other_os, bootdev)
 * @var array $advFinish     section 'finish' (reboot, eject_cdrom)
 */
use Admin\View;
$apt = $advApt;
$bl  = $advBootloader;
$fin = $advFinish;
?>
<h4 style="margin:0 0 0.5rem 0;">Composantes APT</h4>
<div class="form-row form-row--3col">
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[apt][non_free_firmware]" value="1" <?= !empty($apt['non_free_firmware']) ? 'checked' : '' ?>>
    <span><code>non-free-firmware</code></span>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[apt][contrib]" value="1" <?= !empty($apt['contrib']) ? 'checked' : '' ?>>
    <span><code>contrib</code></span>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[apt][non_free]" value="1" <?= !empty($apt['non_free']) ? 'checked' : '' ?>>
    <span><code>non-free</code></span>
  </label>
</div>
<label style="display:flex; align-items:center; gap:.5rem; margin-top:0.5rem;">
  <input type="checkbox" name="adv[apt][hw_detect_load_firmware]" value="1" <?= !empty($apt['hw_detect_load_firmware']) ? 'checked' : '' ?>>
  <span>Charger les firmwares détectés (<code>hw-detect/load_firmware</code>)</span>
</label>
<div class="form-row form-row--2col" style="margin-top:0.5rem;">
  <label>
    Services APT activés <span class="muted">(CSV)</span>
    <input type="text" name="adv[apt][services_select]" value="<?= View::e(implode(',', $apt['services_select'] ?? [])) ?>" placeholder="security,updates">
    <small class="form-hint">Champ <code>apt-setup/services-select</code> — ex. <code>security,updates</code>.</small>
  </label>
  <label>
    Host de sécurité <span class="muted">(optionnel)</span>
    <input type="text" name="adv[apt][security_host]" value="<?= View::e($apt['security_host'] ?? '') ?>" placeholder="security.debian.org">
  </label>
</div>
<label style="display:flex; align-items:center; gap:.5rem; margin-top:0.5rem;">
  <input type="checkbox" name="adv[apt][disable_services]" value="1"
         <?= is_array($apt['services_select'] ?? null) && $apt['services_select'] === [] ? 'checked' : '' ?>>
  <span>Désactiver les services APT supplémentaires (ignore la liste ci-dessus)</span>
</label>

<h4 style="margin:1rem 0 0.5rem 0;">Bootloader GRUB</h4>
<div class="form-row form-row--3col">
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[bootloader][only_debian]" value="1" <?= !empty($bl['only_debian']) ? 'checked' : '' ?>>
    <span>Installer GRUB si Debian est le seul système</span>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[bootloader][with_other_os]" value="1" <?= !empty($bl['with_other_os']) ? 'checked' : '' ?>>
    <span>Installer GRUB même si un autre système est détecté</span>
  </label>
  <label>
    Disque du chargeur de démarrage
    <input type="text" name="adv[bootloader][bootdev]" value="<?= View::e($bl['bootdev'] ?? 'default') ?>" placeholder="default ou /dev/sda">
  </label>
</div>

<h4 style="margin:1rem 0 0.5rem 0;">Fin d'installation</h4>
<div class="form-row form-row--2col">
  <label>
    Après l’installation
    <select name="adv[finish][reboot]">
      <?php foreach (['note','quiet','none'] as $r): ?>
        <option value="<?= $r ?>" <?= ($fin['reboot'] ?? 'note') === $r ? 'selected' : '' ?>><?= ['note' => 'Afficher la fin puis redémarrer', 'quiet' => 'Redémarrer sans dialogue', 'none' => 'Ne pas redémarrer'][$r] ?></option>
      <?php endforeach; ?>
    </select>
    <small class="form-hint"><code>note</code> = affiche le dialogue de fin avant reboot.</small>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[finish][eject_cdrom]" value="1" <?= !empty($fin['eject_cdrom']) ? 'checked' : '' ?>>
    <span>Éjecter le CD/USB en fin d'install</span>
  </label>
</div>
