<?php
/**
 * Partial — Miroir Debian + sélection packages.
 *
 * @var array $advMirror   section 'mirror' (country, hostname, directory, proxy)
 * @var array $advTimezone section 'timezone' (utc, zone, ntp)
 * @var array $advPackages section 'packages' (tasksel[], pkgsel_include[], install_recommends, upgrade, update_policy, popcon)
 */
use Admin\View;
$mir = $advMirror;
$tz  = $advTimezone;
$pkg = $advPackages;
?>
<h4 style="margin:0 0 0.5rem 0;">Miroir Debian</h4>
<div class="form-row form-row--2col">
  <label>
    Pays
    <input type="text" name="adv[mirror][country]" value="<?= View::e($mir['country'] ?? 'manual') ?>" placeholder="manual ou FR">
    <small class="form-hint"><code>manual</code> = pas de redirection geo ; <code>FR</code>/<code>DE</code>/... = miroir par pays.</small>
  </label>
  <label>
    Hostname du miroir
    <input type="text" name="adv[mirror][hostname]" value="<?= View::e($mir['hostname'] ?? 'deb.debian.org') ?>" placeholder="deb.debian.org">
  </label>
</div>
<div class="form-row form-row--2col">
  <label>
    Path
    <input type="text" name="adv[mirror][directory]" value="<?= View::e($mir['directory'] ?? '/debian') ?>" placeholder="/debian">
  </label>
  <label>
    Proxy HTTP <span class="muted">(optionnel)</span>
    <input type="text" name="adv[mirror][proxy]" value="<?= View::e($mir['proxy'] ?? '') ?>" placeholder="http://proxy:3128">
  </label>
</div>

<h4 style="margin:1rem 0 0.5rem 0;">Horloge</h4>
<div class="form-row form-row--3col">
  <label>
    Timezone
    <input type="text" name="adv[timezone][zone]" value="<?= View::e($tz['zone'] ?? 'Europe/Paris') ?>" placeholder="Europe/Paris">
    <small class="form-hint">Format IANA (<code>Europe/Paris</code>, <code>UTC</code>, <code>America/New_York</code>).</small>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[timezone][utc]" value="1" <?= !empty($tz['utc']) ? 'checked' : '' ?>>
    <span>Horloge matérielle en UTC</span>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[timezone][ntp]" value="1" <?= !empty($tz['ntp']) ? 'checked' : '' ?> data-toggle-target="ntp-server-field">
    <span>NTP activé</span>
  </label>
</div>
<div id="ntp-server-field" style="<?= !empty($tz['ntp']) ? '' : 'display:none;' ?>">
  <label>
    Serveur NTP <span class="muted">(optionnel)</span>
    <input type="text" name="adv[timezone][ntp_server]" value="<?= View::e($tz['ntp_server'] ?? '') ?>" placeholder="ntp.example.com (vide = pool Debian par défaut)">
    <small class="form-hint">Un seul serveur — pour plusieurs, utilisez Ansible (chrony/timesyncd) en post-install.</small>
  </label>
</div>
<script>
(function () {
  // Toggle de visibilité du champ "Serveur NTP" selon la case "NTP activé".
  // Inline minimal — pattern non utilisé ailleurs dans le form, pas la peine d'extraire.
  var cb = document.querySelector('input[name="adv[timezone][ntp]"][data-toggle-target]');
  if (!cb) return;
  var target = document.getElementById(cb.getAttribute('data-toggle-target'));
  if (!target) return;
  cb.addEventListener('change', function () {
    target.style.display = cb.checked ? '' : 'none';
  });
})();
</script>

<h4 style="margin:1rem 0 0.5rem 0;">Packages</h4>
<label>
  Tasksel <span class="muted">(CSV)</span>
  <input type="text" name="adv[packages][tasksel]" value="<?= View::e(implode(',', $pkg['tasksel'] ?? [])) ?>" placeholder="ssh-server,standard">
  <small class="form-hint">Tâches Debian (<code>tasksel/first</code>). Ex. : <code>standard,ssh-server</code>.</small>
</label>
<label>
  Packages additionnels <span class="muted">(CSV)</span>
  <input type="text" name="adv[packages][pkgsel_include]" value="<?= View::e(implode(',', $pkg['pkgsel_include'] ?? [])) ?>" placeholder="sudo,openssh-server,python3,ca-certificates,curl">
  <small class="form-hint">Champ <code>pkgsel/include</code>. Toujours inclure au moins <code>sudo</code> et <code>openssh-server</code>.</small>
</label>
<div class="form-row form-row--3col">
  <label>
    Mode de mise à jour
    <select name="adv[packages][upgrade]">
      <?php foreach (['full-upgrade','safe-upgrade','none'] as $u): ?>
        <option value="<?= $u ?>" <?= ($pkg['upgrade'] ?? 'full-upgrade') === $u ? 'selected' : '' ?>><?= $u ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[packages][install_recommends]" value="1" <?= !empty($pkg['install_recommends']) ? 'checked' : '' ?>>
    <span>Installer les recommandations</span>
  </label>
  <label style="display:flex; align-items:center; gap:.5rem;">
    <input type="checkbox" name="adv[packages][popcon]" value="1" <?= !empty($pkg['popcon']) ? 'checked' : '' ?>>
    <span>Popularity-contest</span>
  </label>
</div>
<label>
  Stratégie de mises à jour automatiques <span class="muted">(optionnel)</span>
  <select name="adv[packages][update_policy]">
    <option value="" <?= empty($pkg['update_policy']) ? 'selected' : '' ?>>— défaut Debian —</option>
    <?php foreach (['none','unattended-upgrades','landscape'] as $up): ?>
      <option value="<?= $up ?>" <?= ($pkg['update_policy'] ?? '') === $up ? 'selected' : '' ?>><?= $up ?></option>
    <?php endforeach; ?>
  </select>
</label>
