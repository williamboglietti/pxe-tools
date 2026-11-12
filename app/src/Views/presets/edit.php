<?php
/**
 * View : création/édition d'un modèle (preset).
 *
 * @var array|null $preset
 * @var array<int,array{id:int,label:string}> $reference_machines
 * @var array $machineHw  HardwareContext de la machine de réf (ou empty())
 * @var array|null $cloneSeed  Si non-null, on est en mode "dupliquer depuis un modèle"
 */
use Admin\View;
use Admin\Models\Preset;
use Admin\Models\CatalogEntry;
use Admin\Util\InstallConfigBuilder;

$isNew = $preset === null;
$action = $isNew ? '/presets' : '/presets/' . (int)$preset['id'];
$p = $preset ?? [
    'name'=>'','description'=>'','distro'=>'trixie','version_full'=>null,
    'network_mode'=>'dhcp','static_ip'=>'','static_cidr'=>24,'static_gateway'=>'','static_dns'=>null,
    'partition_mode'=>'default','partition_custom_json'=>null,
    'admin_user_login'=>'','admin_user_pwhash'=>'','admin_user_password_cipher'=>null,'admin_user_groups'=>'sudo','admin_user_pubkeys'=>'',
    'admin_user_fullname'=>'','root_login_mode'=>'disabled',
    'advanced_config_json'=>null,
    'ansible_role'=>'','ansible_extra_vars'=>'',
    'based_on_machine_id'=>null,'hostname_pattern'=>null,'hostname_random'=>0,'domain'=>null,'pre_wipe_mode_json'=>null,
];

// Mode duplication depuis un modèle existant : on merge tous les champs du
// preset source dans $p. Le nom reste vide pour forcer un choix (évite la
// collision UNIQUE sur presets.name). Aucune persistance — l'utilisateur valide
// via le submit standard /presets.
if ($cloneSeed !== null) {
    foreach (($cloneSeed['source_data'] ?? []) as $k => $v) {
        $p[$k] = $v;
    }
    $p['name'] = '';
}

if (isset($draft)) $p = array_replace($p, $draft);

// Inventaire sélectionné pour ce rendu : passé par le contrôleur (override
// ?based_on= ou valeur sauvée) ; fallback sur la valeur du preset (duplication).
$selectedBasedOn = (int)($selected_based_on ?? ($p['based_on_machine_id'] ?? 0));

$dnsList = Preset::parseDnsList($p['static_dns'] ?? null);
if ($isNew && $cloneSeed === null && !isset($draft) && empty($dnsList)) $dnsList = Preset::defaultDns();

$cidrCurrent = (int)($p['static_cidr'] ?? 24) ?: 24;
$cidrChoices = Preset::cidrChoices();

// Plan arborescent v2 (target_disks, disks, vgs, logical_volumes)
$plan = Preset::parsePartitioningPlan($p['partition_custom_json'] ?? null);

// Configuration avancée (defaults + override JSON) — drives les 4 fieldsets repliables.
$adv = Preset::parseAdvancedConfig($p['advanced_config_json'] ?? null);

// Phase 2 chantier hardware-aware : si une machine de référence est liée, on
// alimente le partition-builder avec ses disques (inventaire SSR). disksInventory
// = format compatible avec partition-builder.php (vendor/model/size_bytes/is_ssd).
$disksInventory = [];
foreach (($machineHw['disks'] ?? []) as $d) {
    $disksInventory[] = [
        'name'       => (string)($d['name'] ?? ''),
        'size_bytes' => (int)($d['size_bytes'] ?? 0),
        'is_ssd'     => $d['is_ssd'] ?? null,
        'vendor'     => (string)($d['vendor'] ?? ''),
        'model'      => (string)($d['model'] ?? ''),
        'serial'     => (string)($d['serial'] ?? ''),
        'identity'   => $d['identity'] ?? null,
        'identity_status' => (string)($d['identity_status'] ?? 'missing'),
        'transport'  => (string)($d['transport'] ?? ''),
    ];
}
$hasRefMachine = !empty($disksInventory);

// Décodage pre_wipe_mode pour le rendu UI :
//  - pre_wipe_mode_json (Phase 2) prioritaire si présent → dict per-disque
//  - sinon scalar legacy pre_wipe_mode (radio global)
$preWipeScalar = is_string($p['pre_wipe_mode'] ?? null) && !str_starts_with(trim((string)$p['pre_wipe_mode']), '{')
    ? (string)$p['pre_wipe_mode'] : 'aucun';
$preWipePerDisk = [];
$pwjRaw = $p['pre_wipe_mode_json'] ?? null;
if (is_string($pwjRaw) && trim($pwjRaw) !== '') {
    $decoded = json_decode($pwjRaw, true);
    if (is_array($decoded)) $preWipePerDisk = $decoded;
} elseif (is_string($p['pre_wipe_mode'] ?? null) && str_starts_with(trim((string)$p['pre_wipe_mode']), '{')) {
    // Rétro-compat : ancien preset stockait le dict dans la colonne scalar
    $decoded = json_decode((string)$p['pre_wipe_mode'], true);
    if (is_array($decoded)) $preWipePerDisk = $decoded;
}
?>

<header class="row-flex-spread mb-1">
  <h2><?= $isNew ? 'Nouveau modèle' : 'Modèle : ' . View::e($p['name']) ?></h2>
  <a href="/presets" class="btn btn-secondary">← Retour</a>
</header>

<?php if ($cloneSeed !== null): ?>
  <div class="flash flash-info">
    <strong>Modèle dupliqué</strong> — copie de <em><?= View::e($cloneSeed['source_label'] ?? '') ?></em>.
    Choisissez un nouveau nom et ajustez avant d'enregistrer.
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="bulk-warning install-error-summary" role="alert" tabindex="-1" id="preset-errors">
    <strong>Enregistrement impossible. Votre saisie est conservée.</strong>
    <ul><?php foreach (InstallConfigBuilder::localizeErrors($errors) as $error): ?>
      <li><button type="button" class="error-link" data-error-field="<?= View::e($error['field']) ?>"><?= View::e($error['message']) ?></button></li>
    <?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<p class="form-hint">Ce modèle prépare une configuration réutilisable. L’appliquer crée une copie indépendante sur chaque machine ; modifier le modèle ne modifie pas les copies déjà enregistrées.</p>
<form id="edit-preset" method="post" action="<?= View::e($action) ?>">
  <?php View::partial('_install/disk-constraints-fields', ['diskConstraints' => \Admin\Util\DiskIdentity::constraintsForPreset($p)]); ?>
  <?php if (!$isNew): ?>
    <input type="hidden" name="_method" value="PUT">
  <?php endif; ?>
  <?php if (!empty($p['admin_user_pwhash'])): ?>
    <input type="hidden" name="admin_user_pwhash_existing" value="<?= View::e($p['admin_user_pwhash'] ?? '') ?>">
    <?php if (!empty($p['admin_user_password_cipher'] ?? null)): ?>
      <input type="hidden" name="admin_user_password_cipher_existing" value="<?= View::e($p['admin_user_password_cipher']) ?>">
    <?php endif; ?>
  <?php endif; ?>

  <fieldset class="section">
    <legend>Inventaire matériel <span class="muted text-small">(optionnel)</span></legend>
    <p class="muted text-small">
      Importez l'inventaire matériel d'une machine déjà découverte pour ancrer ce
      modèle à un matériel réel : disques, interfaces réseau et mode BIOS/UEFI seront
      présentés depuis l’inventaire, et le partitionnement sera validé contre les capacités
      effectives. Sans inventaire lié, le modèle reste générique et permet l'ajout
      libre de disques et d'interfaces.
    </p>
    <div class="grid">
      <label>
        Importer l'inventaire depuis
        <select name="based_on_machine_id" id="based-on-machine-select" data-current="<?= $selectedBasedOn ?>">
          <option value="">— Aucun (modèle générique)</option>
          <?php foreach (($reference_machines ?? []) as $rm): ?>
            <option value="<?= (int)$rm['id'] ?>" <?= $selectedBasedOn === (int)$rm['id'] ? 'selected' : '' ?>>
              <?= View::e($rm['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="form-hint">
          Choisissez l'inventaire en premier : disques et cartes réseau sont alors
          importés (en lecture seule) et configurables. Cliquez ensuite sur le bouton de plan automatique
          dans la section Partitionnement pour générer un plan initial.
        </small>
      </label>
    </div>
  </fieldset>

  <fieldset class="section">
    <legend>Identité</legend>
    <?php if (($p['variant'] ?? 'debian') === 'proxmox-ve'): ?>
      <input type="hidden" name="variant_proxmox" value="1">
      <p class="flash flash-warning">Ce modèle Proxmox VE historique est conservé en lecture seule : l’installation générique n’est pas encore disponible.</p>
    <?php endif; ?>
    <div class="grid">
      <label>
        Nom <span class="required-marker">*</span>
        <input type="text" name="name" value="<?= View::e($p['name']) ?>" required aria-required="true" placeholder="web-trixie">
      </label>
      <?php
      // Cascade Système d'exploitation (nom OS) → Codename → Version (point release).
      // Modèle distro-name : presets.distro = nom OS, presets.codename = release.
      $osCascade = CatalogEntry::osCascade();
      if (empty($osCascade)) {
          $osCascade = ['debian' => ['label' => 'Debian', 'codenames' => [
              ['codename' => 'trixie', 'label' => 'Trixie (13)', 'major' => 13, 'versions' => []],
          ]]];
      }
      // Pré-sélection (normalise un preset legacy dont distro est un codename).
      $initDistro   = strtolower(trim((string)($p['distro'] ?? 'debian')));
      $initCodename = strtolower(trim((string)($p['codename'] ?? '')));
      $initVersion  = (string)($p['version_full'] ?? '');
      if (!isset($osCascade[$initDistro])) {
          foreach ($osCascade as $dName => $dInfo) {
              foreach ($dInfo['codenames'] as $c) {
                  if ($c['codename'] === $initDistro) { $initCodename = $initDistro; $initDistro = $dName; break 2; }
              }
          }
          if (!isset($osCascade[$initDistro])) $initDistro = array_key_first($osCascade);
      }
      ?>
      <div id="preset-os-fieldset" style="display:contents;"
           data-os-cascade='<?= View::e(json_encode($osCascade, JSON_UNESCAPED_SLASHES)) ?>'
           data-init-codename="<?= View::e($initCodename) ?>"
           data-init-version="<?= View::e($initVersion) ?>">
      <label>
        Système d'exploitation <span class="required-marker">*</span>
        <select name="distro" id="preset-distro" required aria-required="true">
          <?php foreach ($osCascade as $dName => $dInfo): ?>
            <option value="<?= View::e($dName) ?>" <?= $initDistro === $dName ? 'selected' : '' ?>><?= View::e($dInfo['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Codename <span class="required-marker">*</span>
        <select name="codename" id="preset-codename" required aria-required="true"></select>
      </label>
      <label>
        Version
        <select name="version_full" id="preset-version-full" aria-label="Version point release de l'OS">
          <option value="">— latest disponible —</option>
        </select>
        <small class="form-hint">
          Fixe l’installateur sur une version précise (ex 13.2.0). Vide = dernière
          version téléchargée du codename. Une version absente de
          <a href="/settings/images">/settings/images</a> sera téléchargée automatiquement à l’enregistrement.
        </small>
      </label>
      </div>
      <script>
      (function() {
        var box = document.getElementById('preset-os-fieldset');
        if (!box) return;
        var osSel = document.getElementById('preset-distro');
        var cnSel = document.getElementById('preset-codename');
        var vfSel = document.getElementById('preset-version-full');
        if (!osSel || !cnSel || !vfSel) return;
        var cascade = {};
        try { cascade = JSON.parse(box.dataset.osCascade || '{}'); } catch (e) {}
      function fillVersions(keepVf) {
        var entry = cascade[osSel.value] || { codenames: [] };
        var node = entry.codenames.filter(function(c){ return c.codename === cnSel.value; })[0];
        var list = node ? node.versions : [];
        vfSel.replaceChildren(new Option('Dernière version disponible', ''));
        list.forEach(function(v){ vfSel.add(new Option(v, v)); });
        if (keepVf && !list.includes(keepVf)) vfSel.add(new Option(keepVf + ' (à vérifier)', keepVf));
        if (keepVf) vfSel.value = keepVf;
      }
      function fillCodenames(keepCn, keepVf) {
        var entry = cascade[osSel.value] || { codenames: [] };
        cnSel.replaceChildren();
        entry.codenames.forEach(function(c){ cnSel.add(new Option(c.label, c.codename)); });
        if (keepCn && !entry.codenames.some(function(c){ return c.codename === keepCn; })) {
          cnSel.add(new Option(keepCn + ' (à vérifier)', keepCn));
        }
        if (keepCn) cnSel.value = keepCn;
        fillVersions(keepVf);
      }
        osSel.addEventListener('change', function(){ fillCodenames('', ''); });
        cnSel.addEventListener('change', function(){ fillVersions(''); });
        fillCodenames(box.dataset.initCodename || '', box.dataset.initVersion || '');

      })();
      </script>
    </div>
    <label>
      Description
      <textarea name="description" rows="2" placeholder="Description courte du modèle"><?= View::e($p['description'] ?? '') ?></textarea>
    </label>
    <?php $_hostnameRandom = !empty($p['hostname_random']); ?>
    <div class="grid">
      <label>
        Nom d'hôte
        <input type="text" name="hostname" id="preset-hostname-input"
               value="<?= View::e($p['hostname_pattern'] ?? '') ?>"
               placeholder="ex: srv-web-01"
               <?= $_hostnameRandom ? 'disabled' : '' ?>>
        <small class="form-hint">
          Vide = le serveur DHCP attribue le nom (ou <code>debian</code> par défaut côté Debian Installer).
          Saisi = utilisé tel quel à l'installation.
        </small>
      </label>
      <label>
        Domaine <span class="muted text-small">(optionnel)</span>
        <input type="text" name="domain" value="<?= View::e($p['domain'] ?? '') ?>" placeholder="example.test">
        <small class="form-hint">
          FQDN du domaine (<code>netcfg/get_domain</code>). Vide = aucun domaine n'est imposé au preseed.
        </small>
      </label>
    </div>
    <label style="display:flex; align-items:center; gap:.5rem; margin-top:.4rem;">
      <input type="checkbox" name="hostname_random" id="preset-hostname-random" role="switch" value="1"
             <?= $_hostnameRandom ? 'checked' : '' ?>>
      <span>Générer un nom aléatoire à l'installation
        <small class="muted text-small" style="display:block; font-weight:normal;">
          Style Docker, déterministe sur la MAC (ex: <code>relaxed_einstein</code>). Le champ Nom d'hôte est ignoré.
        </small>
      </span>
    </label>
    <script>
    (function () {
      const toggle = document.getElementById('preset-hostname-random');
      const input  = document.getElementById('preset-hostname-input');
      if (!toggle || !input) return;
      const sync = () => {
        input.disabled = toggle.checked;
        if (toggle.checked) input.value = '';
      };
      toggle.addEventListener('change', sync);
    })();
    </script>
  </fieldset>

  <fieldset class="section">
    <legend>Réseau</legend>
    <?php
    // NICs inventaire (mode multi-NIC) : ne sont passées au partial que si un
    // inventaire matériel est lié → verrouillage automatique des identités
    // (mac hidden, name en lecture seule, pas d'ajout/suppression).
    $nicsInventory = [];
    foreach (($machineHw['nics'] ?? []) as $nic) {
        $nicsInventory[] = [
            'mac'        => (string)($nic['mac'] ?? ''),
            'name'       => (string)($nic['name'] ?? ''),
            'speed_mbps' => (int)($nic['speed_mbps'] ?? 0),
            'type'       => (string)($nic['type'] ?? ''),
            'ipv4'       => (string)($nic['ipv4'] ?? ''),
        ];
    }
    $networkPlan = Preset::parseNetwork($p);
    $savedNetwork = json_decode((string)($p['advanced_config_json'] ?? ''), true);
    $hasSavedPrimary = is_array($savedNetwork)
        && isset($savedNetwork['network']['primary'])
        && !empty($savedNetwork['network']['interfaces']);
    View::partial('_install/network-fields', [
      'p' => $p,
      'cidrChoices' => $cidrChoices,
      'dnsList' => $dnsList,
      'nicsInventory' => $nicsInventory,
      'networkPlan' => $networkPlan,
      'bootMac' => (string)($machineHw['boot_mac'] ?? ''),
      'preferBootNic' => !$hasSavedPrimary,
    ]);
    ?>
  </fieldset>

  <fieldset class="section">
    <legend>Partitionnement</legend>

    <?php
    // Normalise les clés de preWipePerDisk à /dev/X pour cohérence avec le partial.
    $_preWipePerDiskNorm = [];
    foreach (($preWipePerDisk ?? []) as $_n => $_m) {
        $_key = str_starts_with((string)$_n, '/dev/') ? (string)$_n : '/dev/' . ltrim((string)$_n, '/');
        $_preWipePerDiskNorm[$_key] = $_m;
    }
    ?>

    <?php View::partial('_install/partitioning-section', [
      'p' => $p,
      'plan' => $plan,
      'disksInventory' => $disksInventory,
      'ramKb'  => $machineHw['ram_kb'] ?? null,
      'isUefi' => $machineHw['is_uefi'] ?? false,
      'preWipePerDisk' => $_preWipePerDiskNorm,
    ]); ?>
  </fieldset>

  <fieldset class="section">
    <legend>Utilisateur admin personnalisé</legend>
    <?php View::partial('_install/admin-user-fields', ['p' => $p, 'isNew' => $isNew]); ?>
  </fieldset>

  <details class="section progressive-options"><summary>Options d’installation · localisation, logiciels et démarrage</summary>
    <details>
      <summary><strong>Localisation &amp; clavier</strong></summary>
      <div style="padding:0.75rem 0;">
        <?php View::partial('_install/localization-fields', ['adv' => $adv['localization']]); ?>
      </div>
    </details>
    <details>
      <summary><strong>Dépôt Debian, horloge et logiciels</strong></summary>
      <div style="padding:0.75rem 0;">
        <?php View::partial('_install/mirror-packages-fields', [
          'advMirror'   => $adv['mirror'],
          'advTimezone' => $adv['timezone'],
          'advPackages' => $adv['packages'],
        ]); ?>
      </div>
    </details>
    <details>
      <summary><strong>Expert · dépôts APT, démarrage et fin d’installation</strong></summary>
      <div style="padding:0.75rem 0;">
        <?php View::partial('_install/apt-bootloader-fields', [
          'advApt'        => $adv['apt'],
          'advBootloader' => $adv['bootloader'],
          'advFinish'     => $adv['finish'],
        ]); ?>
      </div>
    </details>
    <details>
      <summary><strong>Clés SSH téléchargées après installation</strong></summary>
      <div style="padding:0.75rem 0;">
        <?php View::partial('_install/post-install-fields', ['advPostInstall' => $adv['post_install']]); ?>
      </div>
    </details>
  </details>

  <details class="section progressive-options" <?= !empty($p['ansible_role']) ? 'open' : '' ?>><summary>Automatisation après installation<?= !empty($p['ansible_role']) ? ' · ' . View::e($p['ansible_role']) : ' · aucun rôle' ?></summary>
    <?php View::partial('_partials/ansible-playbook-field', ['ansibleRole' => $p['ansible_role'] ?? '', 'fieldId' => 'preset-playbook']); ?>
    <label>
      Extra vars <span class="muted">(YAML/JSON inline, optionnel)</span>
      <textarea name="ansible_extra_vars" rows="3" placeholder='{"nginx_workers":4}'><?= View::e($p['ansible_extra_vars'] ?? '') ?></textarea>
    </label>
  </details>

</form>

<div class="actions" style="justify-content:flex-end;">
  <?php if (!$isNew): ?>
    <form method="post" action="/presets/<?= (int)$preset['id'] ?>/push-github" style="display:inline;"
          onsubmit="return confirm('Publier ce modèle dans le dépôt Git configuré ? Le modèle sera enregistré dans le dossier prévu par son profil.');">
      <button type="submit" class="btn btn-secondary" title="Publier la copie JSON du modèle dans le dossier déclaré par le profil">
        <?= View::icon('shield') ?> Sauvegarder sur GitHub
      </button>
    </form>
    <form method="post" action="/presets/<?= (int)$preset['id'] ?>" style="display:inline;">
      <input type="hidden" name="_method" value="DELETE">
      <button type="submit" class="btn btn-danger" onclick="return confirm('Supprimer ce modèle ? Les machines qui l\'utilisent perdront leur association.')">Supprimer</button>
    </form>
  <?php endif; ?>
  <button type="submit" form="edit-preset" class="btn btn-primary"><?= $isNew ? 'Créer' : 'Enregistrer' ?></button>
</div>

<?php
// Expose le HardwareContext de la machine de référence (si liée) au JS partagé :
// builder-shared.js lit ce script pour alimenter diskMetaByPath() qui sert au
// calcul des placeholders "Remplir" (taille calculée = capa disque − somme fixes).
// Sans ça, les LV/partitions en fill ont une taille vide même quand le preset
// est lié à une machine de référence. Format aligné sur extractHardwareHints().
$_hwForJs = [
    'is_uefi' => (bool)($machineHw['is_uefi'] ?? false),
    'ram_kb'  => (int)($machineHw['ram_kb'] ?? 0),
    'disks'   => $disksInventory,
];
?>
<script type="application/json" id="machine-hw"><?= json_encode($_hwForJs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="/builder-shared.js?v=<?= @filemtime(__DIR__ . '/../../../public/builder-shared.js') ?: time() ?>" defer></script>
<script src="/preset-builder.js?v=<?= @filemtime(__DIR__ . '/../../../public/preset-builder.js') ?: time() ?>" defer></script>

<script>
// Phase 2 chantier hardware-aware : interactions form preset
(function() {
  'use strict';

  // Changement de l'inventaire lié : on RECHARGE le form avec le HardwareContext
  // de la machine choisie (?based_on=) → disques + cartes réseau importés (en
  // lecture seule) par le rendu serveur, comme le wizard d'install. Le rechargement
  // réinitialise les champs non sauvés → garde confirm() si le form est « sale »
  // (on ignore le select lui-même dans la détection pour ne pas confirmer sur un
  // form vierge). Choisir « — Aucun » recharge en mode générique.
  const refSelect = document.getElementById('based-on-machine-select');
  if (refSelect) {
    const initial = refSelect.dataset.current || '';
    const reloadBase = <?= json_encode($isNew ? '/presets/new' : '/presets/' . (int)$preset['id'], JSON_UNESCAPED_SLASHES) ?>;
    const form = refSelect.closest('form');
    let dirty = false;
    if (form) form.addEventListener('input', (e) => { if (e.target !== refSelect) dirty = true; });
    refSelect.addEventListener('change', () => {
      const newVal = refSelect.value;
      if (newVal === initial) return;
      if (dirty && !window.confirm("Recharger le formulaire pour importer le matériel de cet inventaire ? Les champs non enregistrés seront réinitialisés.")) {
        refSelect.value = initial;
        return;
      }
      window.location.href = reloadBase + '?based_on=' + encodeURIComponent(newVal);
    });
  }
})();
</script>

<script type="application/json" id="preset-submitted-draft"><?= json_encode($submitted ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('edit-preset');
  const draft = JSON.parse(document.getElementById('preset-submitted-draft').textContent);
  if (draft) {
    function restore(value, name) {
      if (value && typeof value === 'object') {
        Object.entries(value).forEach(([key, child]) => restore(child, name ? name + '[' + key + ']' : key));
        return;
      }
      let fields = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
      if (!fields.length && /\[\d+\]$/.test(name)) {
        const base = name.replace(/\[\d+\]$/, '[]');
        const index = Number(name.match(/\[(\d+)\]$/)[1]);
        const el = form.querySelectorAll('[name="' + CSS.escape(base) + '"]')[index];
        fields = el ? [el] : [];
      }
      fields.forEach(el => {
        if (el.type === 'checkbox' || el.type === 'radio') el.checked = String(value) === el.value;
        else if (!el.name.endsWith('[size_mb]')) el.value = value ?? '';
      });
    }
    restore(draft, '');
  }
  function focusField(name) {
    const el = form.querySelector('[name="' + CSS.escape(name) + '"]');
    if (!el) { document.getElementById('preset-errors')?.focus(); return; }
    for (let p = el.parentElement; p && p !== form; p = p.parentElement) if (p.tagName === 'DETAILS') p.open = true;
    el.focus(); el.scrollIntoView({block: 'center'});
  }
  document.querySelectorAll('[data-error-field]').forEach(button => button.addEventListener('click', () => focusField(button.dataset.errorField)));
  const first = document.querySelector('[data-error-field]');
  if (first) focusField(first.dataset.errorField);
  form.addEventListener('invalid', event => {
    for (let p = event.target.parentElement; p && p !== form; p = p.parentElement) if (p.tagName === 'DETAILS') p.open = true;
  }, true);
});
</script>
