<?php
/**
 * Partial : formulaire wizard 4 étapes (Logiciel / Identifiants / Partitionnement / Confirmation).
 * Inclus depuis :
 *   - machines/install.php (page autonome)
 *   - machines/show.php — onglet "Installer" (inline)
 *
 * Variables injectées par View::partial() via extract() :
 * @var array              $machine
 * @var array              $config
 * @var array<int,array>   $allPresets
 * @var array<int,string>  $allowed_distros
 * @var bool               $hasExistingConfig
 * @var array|null         $machineHw
 * @var array<int,string>  $errors
 */
use Admin\View;
use Admin\Models\Preset;
use Admin\Models\Machine;
use Admin\Models\CatalogEntry;
use Admin\Models\Setting;
use Admin\Catalog\DistroCatalog;
use Admin\Util\HardwareContext;
use Admin\Util\InstallConfigBuilder;

$cidrChoices = Preset::cidrChoices();
$dnsList     = $config['static_dns'] ?? [];
if (!is_array($dnsList)) $dnsList = [];
// Plan arborescent v2 (target_disks, disks, vgs, logical_volumes)
$plan = is_string($config['partition_custom_json'] ?? null)
    ? Preset::parsePartitioningPlan($config['partition_custom_json'])
    : (is_array($config['partition_custom_json'] ?? null) ? Preset::parsePartitioningPlan(json_encode($config['partition_custom_json'])) : Preset::emptyPartitioningPlan());
$rawInstallPlan = json_decode((string)($config['partition_custom_json'] ?? ''), true);
$storedDiskTargets = is_array($rawInstallPlan['target_disks'] ?? null) ? $rawInstallPlan['target_disks'] : [];
$storedDiskIdentities = is_array($rawInstallPlan['target_disk_identities'] ?? null) ? $rawInstallPlan['target_disk_identities'] : [];
$hasStoredDiskIdentity = ($config['partition_mode'] ?? 'default') === 'custom'
    ? ($storedDiskTargets !== [] && count(array_filter($storedDiskTargets, static fn($path) => is_array($storedDiskIdentities[$path] ?? null) && !empty($storedDiskIdentities[$path]['value']))) === count($storedDiskTargets))
    : (is_array($config['system_disk_identity'] ?? null) && !empty($config['system_disk_identity']['value']));
$diskIdentitySelectionConfirmed = !$hasExistingConfig || $hasStoredDiskIdentity;

// Paramètres avancés du preseed : source = $config['advanced'] (snapshot machine
// ou preset chargé), sinon defaults. defaultInstallConfig() pose déjà la clé,
// mais on retombe sur defaultAdvancedConfig si elle a été perdue par un snapshot
// antérieur à l'ajout de la section dans le wizard machine (rétro-compat).
$adv = is_array($config['advanced'] ?? null)
    ? $config['advanced'] + Preset::defaultAdvancedConfig()
    : Preset::defaultAdvancedConfig();

$p = $config;
$isNew = !$hasExistingConfig;
$verb = $hasExistingConfig ? 'Réinstaller' : 'Installer';
$restorableConfig = $hasExistingConfig ? (Machine::installConfig($machine) ?? $config) : $config;
$restorableConfig['disk_identity_selection_confirmed'] = $diskIdentitySelectionConfirmed;
$restorableConfig += ['hostname' => $machine['hostname'] ?? '', 'domain' => $machine['domain'] ?? ''];
$restoreLabel = $hasExistingConfig ? 'Copie enregistrée pour cette machine' : (!empty($errors) ? 'Saisie reçue, non enregistrée' : 'Valeurs initiales');
?>

<form id="install-wizard-form" method="post" action="/machines/<?= (int)$machine['id'] ?>/install" novalidate data-preview-url="/machines/<?= (int)$machine['id'] ?>/install-preview" data-restore-label="<?= View::e($restoreLabel) ?>">
  <?php View::partial('_install/disk-constraints-fields', ['diskConstraints' => $config['disk_target_constraints'] ?? []]); ?>
  <div id="install-errors" class="bulk-warning install-error-summary" role="alert" tabindex="-1" hidden></div>
  <?php if ($hasExistingConfig || !empty($config['admin_user_pwhash'])): ?>
    <input type="hidden" name="admin_user_pwhash_existing" value="<?= View::e($config['admin_user_pwhash'] ?? '') ?>">
    <?php if (!empty($config['admin_user_password_cipher'] ?? null)): ?>
      <input type="hidden" name="admin_user_password_cipher_existing" value="<?= View::e($config['admin_user_password_cipher']) ?>">
    <?php endif; ?>
  <?php endif; ?>

  <div class="wizard-stepper" aria-label="Progression">
    <div class="wizard-step-indicator active" data-step-indicator="1">
      <span class="dot">1</span><span class="label">Système d'exploitation</span>
    </div>
    <div class="wizard-step-indicator" data-step-indicator="2">
      <span class="dot">2</span><span class="label">Identité et réseau</span>
    </div>
    <div class="wizard-step-indicator" data-step-indicator="3">
      <span class="dot">3</span><span class="label">Stockage</span>
    </div>
    <div class="wizard-step-indicator" data-step-indicator="4">
      <span class="dot">4</span><span class="label">Vérification</span>
    </div>
  </div>

  <fieldset class="section wizard-form-step" data-step="1">
    <label>
      Partir d’une configuration
      <?php
      // Groupes presets : compatibles (inventaire lié à cette machine) vs autres.
      // Si aucun fournisseur n'a passé $presetsGrouped, fallback sur $allPresets dans "Autres".
      $_pg = $presetsGrouped ?? ['compatible' => [], 'others' => $allPresets ?? []];
      $_defaultPresetId = (empty($errors) && !$hasExistingConfig && !empty($_pg['compatible']))
        ? (int)$_pg['compatible'][0]['id']
        : 0;
      ?>
      <select id="preset-loader" data-default-preset-id="<?= $_defaultPresetId ?>">
        <option value="" selected>Restaurer · <?= View::e($restoreLabel) ?></option>
        <option value="__reset__">Nouvelle configuration (valeurs par défaut)</option>
        <?php if (!empty($_pg['compatible'])): ?>
          <optgroup label="Modèles pour cette machine">
            <?php foreach ($_pg['compatible'] as $preset): ?>
              <option value="<?= (int)$preset['id'] ?>"><?= View::e($preset['name']) ?> (<?= View::e($preset['distro']) ?>)</option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php if (!empty($_pg['others'])): ?>
          <optgroup label="Autres modèles">
            <?php foreach ($_pg['others'] as $preset): ?>
              <option value="<?= (int)$preset['id'] ?>"><?= View::e($preset['name']) ?> (<?= View::e($preset['distro']) ?>)</option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
      </select>
      <small class="form-hint">
        Changer de source remplace la saisie. La copie enregistrée reste indépendante du modèle.
      </small>
    </label>
    <p class="config-source-badge" data-config-source-badge>
      Configuration en cours :
      <strong class="config-source-badge__label" data-config-source-label><?= !empty($errors) ? 'Saisie à corriger (non enregistrée)' : (!empty($hasExistingConfig) ? 'Copie enregistrée pour cette machine' : 'Valeurs initiales') ?></strong>
    </p>

    <?php
    // Modèle distro-name : cascade OS (nom) → Codename → Version (point release).
    // Filtré sur les distros réellement INSTALLABLES (pipeline d-i/preseed). Les
    // autres se téléchargent mais ne s'installent pas encore → exclues de la
    // cascade (donc non sélectionnables / non submittables) et présentées
    // dans le détail secondaire du catalogue.
    $osCascade = array_filter(
        CatalogEntry::osCascade(),
        static fn (string $code): bool => DistroCatalog::supportsInstall($code),
        ARRAY_FILTER_USE_KEY
    );
    if (empty($osCascade)) {
        $osCascade = ['debian' => ['label' => 'Debian', 'codenames' => [
            ['codename' => 'trixie', 'label' => 'Trixie (13)', 'major' => 13, 'versions' => []],
        ]]];
    }
    // Pré-sélection (normalise les snapshots legacy : distro=codename → dériver).
    $initDistro   = strtolower(trim((string)($config['distro'] ?? 'debian')));
    $initCodename = strtolower(trim((string)($config['codename'] ?? '')));
    $initVersion  = (string)($config['version_full'] ?? '');
    if (!isset($osCascade[$initDistro])) {
        foreach ($osCascade as $dName => $dInfo) {
            foreach ($dInfo['codenames'] as $c) {
                if ($c['codename'] === $initDistro) { $initCodename = $initDistro; $initDistro = $dName; break 2; }
            }
        }
        if (!isset($osCascade[$initDistro])) $initDistro = array_key_first($osCascade);
    }

    // Logos de marque partagés (source unique pour les 2 pickers d'OS — install
    // single-select + wizard init multi-select). $debianLogo conservé pour le
    // fallback `?? $debianLogo` plus bas.
    $osLogos    = require __DIR__ . '/../_partials/os-logos.php';
    $debianLogo = $osLogos['debian'];
    ?>

    <div class="os-fieldset" id="install-os-fieldset"
         data-os-cascade='<?= View::e(json_encode($osCascade, JSON_UNESCAPED_SLASHES)) ?>'
         data-init-distro="<?= View::e($initDistro) ?>"
         data-init-codename="<?= View::e($initCodename) ?>"
         data-init-version="<?= View::e($initVersion) ?>">
      <!-- Source de vérité de la distro : piloté par le clic des tuiles (JS). -->
      <input type="hidden" name="distro" id="install-distro" value="<?= View::e($initDistro) ?>">

      <!-- Seuls les systèmes installables occupent le parcours courant. -->
      <div class="os-grid">
        <?php foreach (DistroCatalog::all() as $code => $cat):
            $downloaded = isset($osCascade[$code]);
            if (!$downloaded) continue;
            $isSelected = $downloaded && $code === $initDistro;
            $logo       = $osLogos[$code] ?? $debianLogo;
        ?>
          <?php if ($downloaded): ?>
            <div class="os-tile os-tile--pickable<?= $isSelected ? ' is-selected' : '' ?>"
                 data-distro="<?= View::e($code) ?>" role="button" tabindex="0"
                 aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"
                 aria-label="<?= View::e($cat->label()) ?>">
              <span class="os-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.2 4.2L19 7" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
              <span class="os-logo" aria-hidden="true"><?= $logo ?></span>
              <span class="os-name"><?= View::e($cat->label()) ?></span>
              <span class="os-meta"><?= $isSelected ? 'Sélectionné' : 'Disponible' ?></span>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Bande de configuration de version (sous la grille → aucun reflow) -->
      <div class="os-config">
        <div class="os-config-os">
          <span class="os-mini-logo" id="install-os-mini-logo" aria-hidden="true"></span>
          <span class="os-config-os-text">
            <span class="os-config-label">Image sélectionnée</span>
            <span class="os-config-name" id="install-os-config-name"></span>
          </span>
        </div>
        <div class="os-selects">
          <label class="os-select-group">
            <span class="os-select-label">Version du système</span>
            <span class="os-select-wrap">
              <select name="codename" id="install-codename" required></select>
              <span class="os-chev" aria-hidden="true"><svg viewBox="0 0 12 12" fill="none"><path d="M2.5 4.5L6 8l3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            </span>
          </label>
          <label class="os-select-group">
            <span class="os-select-label">Révision</span>
            <span class="os-select-wrap">
              <select name="version_full" id="install-version-full"><option value="">Dernière disponible</option></select>
              <span class="os-chev" aria-hidden="true"><svg viewBox="0 0 12 12" fill="none"><path d="M2.5 4.5L6 8l3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            </span>
          </label>
        </div>
      </div>
    </div>

    <?php if (($config['variant'] ?? 'debian') === 'proxmox-ve'): ?>
      <input type="hidden" name="variant_proxmox" value="1">
      <p class="flash flash-warning">Cette configuration Proxmox VE existante ne peut pas être lancée : l’installation générique n’est pas encore disponible.</p>
    <?php endif; ?>

    <?php $_otherCatalog = array_diff_key(DistroCatalog::all(), $osCascade); ?>
    <?php if ($_otherCatalog): ?>
      <details class="progressive-options">
        <summary>Autres distributions du catalogue</summary>
        <p class="form-hint">Leurs images peuvent être téléchargées. Leur installation automatisée n’est pas encore prise en charge.</p>
        <ul><?php foreach ($_otherCatalog as $_catalog): ?><li><?= View::e($_catalog->label()) ?></li><?php endforeach; ?></ul>
        <a href="/settings/images">Gérer les images</a>
      </details>
    <?php endif; ?>

    <script>
    // Cascade OS (nom) → Codename → Version. data-os-cascade contient
    // {distro:{label,codenames:[{codename,label,versions:[vf]}]}}.
    (function() {
      var box = document.getElementById('install-os-fieldset');
      if (!box) return;
      var osSel = document.getElementById('install-distro');
      var cnSel = document.getElementById('install-codename');
      var vfSel = document.getElementById('install-version-full');
      var miniLogo = document.getElementById('install-os-mini-logo');
      var configName = document.getElementById('install-os-config-name');
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
      // Reflète la distro courante (osSel = hidden) sur les tuiles (classe + ARIA
      // + meta) et sur la bande de config (logo + nom de l'OS choisi). Les selects
      // restent dans la bande fixe → aucun reflow. Appelé au 'change' du hidden,
      // donc marche aussi quand applyConfig (Source de config) écrit la distro.
      function syncTiles() {
        var selected = null;
        box.querySelectorAll('.os-tile--pickable').forEach(function(t) {
          var on = t.dataset.distro === osSel.value;
          t.classList.toggle('is-selected', on);
          t.setAttribute('aria-pressed', on ? 'true' : 'false');
          var meta = t.querySelector('.os-meta');
          if (meta) meta.textContent = on ? 'Sélectionné' : 'Disponible';
          if (on) selected = t;
        });
        if (selected) {
          if (miniLogo) miniLogo.innerHTML = selected.querySelector('.os-logo').innerHTML;
          if (configName) configName.textContent = selected.querySelector('.os-name').textContent;
        }
      }
      function selectDistro(code) {
        if (!code || osSel.value === code) return;
        osSel.value = code;
        osSel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      box.querySelectorAll('.os-tile--pickable').forEach(function(t) {
        t.addEventListener('click', function(){ selectDistro(t.dataset.distro); });
        t.addEventListener('keydown', function(e){
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectDistro(t.dataset.distro); }
        });
      });
      osSel.addEventListener('change', function(){ syncTiles(); fillCodenames('', ''); });
      cnSel.addEventListener('change', function(){ fillVersions(''); });
      syncTiles();
      fillCodenames(box.dataset.initCodename || '', box.dataset.initVersion || '');

    })();
    </script>

<details class="progressive-options" data-advanced-options><summary>Options d’installation <span class="advanced-count" data-advanced-count></span></summary>
    <p class="form-hint">Les valeurs par défaut conviennent à la plupart des installations.</p>
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
    <div class="actions" style="justify-content:flex-end;margin-top:2rem;border-top:1px solid var(--color-divider);padding-top:1rem;">
      <button type="button" class="wizard-next btn btn-primary" data-target-step="2">Suivant →</button>
    </div>
  </fieldset>

  <fieldset class="section wizard-form-step" data-step="2" hidden>
    <legend>Identifiants &amp; réseau</legend>

    <div class="grid">
      <?php
      $_hostnameDefault = Machine::generateHostname($machine['mac'] ?? (string)microtime(true));
      $_hostnameValue = trim((string)(array_key_exists('hostname', $config) ? $config['hostname'] : ($machine['hostname'] ?? '')));
      ?>
      <label>
        Nom d'hôte
        <input type="text" name="hostname" value="<?= View::e($_hostnameValue) ?>" placeholder="<?= View::e($_hostnameDefault) ?>">
        <input type="hidden" name="hostname_default" value="<?= View::e($_hostnameDefault) ?>">
        <?php if ($_hostnameValue === ''): ?>
          <small class="form-hint">Si laissé vide, l’installateur utilise le nom fourni par DHCP (ou son nom par défaut).</small>
        <?php endif; ?>
      </label>
      <label>
        Domaine
        <input type="text" name="domain" value="<?= View::e(array_key_exists('domain', $config) ? $config['domain'] : ($machine['domain'] ?? '')) ?>" placeholder="example.test">
        <small class="form-hint">Vide : aucun domaine n'est imposé à l’installateur.</small>
      </label>
    </div>

    <h4>Utilisateur admin</h4>
    <?php View::partial('_install/admin-user-fields', ['p' => $p, 'isNew' => $isNew]); ?>

    <?php
    $hasExistingRootPwd = !empty($config['root_password_hash']);
    ?>
    <section id="root-password-section" <?= ($p['root_login_mode'] ?? 'disabled') === 'disabled' ? 'hidden' : '' ?>>
      <h4>Mot de passe root <span class="muted text-small">(optionnel)</span></h4>
      <label>
        Mot de passe root pour cette machine
        <input type="password" name="root_password" minlength="8" autocomplete="new-password" <?= ($p['root_login_mode'] ?? 'disabled') === 'disabled' ? 'disabled' : '' ?>
               placeholder="<?= $hasExistingRootPwd ? '••• (déjà défini, modifiable)' : 'Vide = compte root verrouillé (recommandé)' ?>">
        <small class="form-hint">
          <?php if ($hasExistingRootPwd): ?>
            Mot de passe spécifique défini pour cette machine. Vide = conserver l'existant.
          <?php else: ?>
            Vide : root reste verrouillé. Utilisez le compte administrateur avec sudo. Au moins 8 caractères si renseigné.
          <?php endif; ?>
        </small>
      </label>
      <?php if ($hasExistingRootPwd): ?>
        <input type="hidden" name="root_password_hash_existing" value="<?= View::e($config['root_password_hash']) ?>">
      <?php endif; ?>
      <?php if (!empty($config['root_password_cipher'] ?? null) || !empty($machine['root_password_cipher'] ?? null)): ?>
        <input type="hidden" name="root_password_cipher_existing"
               value="<?= View::e($config['root_password_cipher'] ?? $machine['root_password_cipher'] ?? '') ?>">
      <?php endif; ?>
      <small class="form-hint muted" style="display:block; margin-top:.4rem;">
        Stocké chiffré et consultable depuis la fiche machine.
      </small>
    </section>

    <h4>Réseau</h4>
    <?php
    // Multi-NIC : passer l'inventaire des NICs de la machine cible + le plan
    // courant (snapshot machine OU preset chargé via le dropdown). Sans ces
    // variables, network-fields.php tombe sur le fallback legacy single-NIC
    // (radios DHCP/Static sans cards par NIC) et le JS applyConfig() ne peut
    // pas reporter les valeurs multi-NIC d'un preset chargé.
    $_machineHw = HardwareContext::fromMachine($machine);
    $_nicsInventory = [];
    foreach (($_machineHw['nics'] ?? []) as $_nic) {
        $_nicsInventory[] = [
            'mac'        => (string)($_nic['mac'] ?? ''),
            'name'       => (string)($_nic['name'] ?? ''),
            'speed_mbps' => (int)($_nic['speed_mbps'] ?? 0),
            'type'       => (string)($_nic['type'] ?? ''),
            'ipv4'       => (string)($_nic['ipv4'] ?? ''),
        ];
    }
    // $config['network'] est posé par InstallConfigBuilder ET Preset::toInstallConfig
    // sous la même structure ({interfaces:[…], primary:N}) : on l'utilise direct.
    $_networkPlan = is_array($config['network'] ?? null) ? $config['network'] : [];
    View::partial('_install/network-fields', [
      'p'             => $p,
      'cidrChoices'   => $cidrChoices,
      'dnsList'       => $dnsList,
      'nicsInventory' => $_nicsInventory,
      'networkPlan'   => $_networkPlan,
      'bootMac'       => (string)($_machineHw['boot_mac'] ?? ''),
      'preferBootNic' => !isset($_networkPlan['primary']) || empty($_networkPlan['interfaces']),
    ]);
    ?>

    <details>
      <summary class="muted">Automatisation après installation</summary>
      <?php View::partial('_partials/ansible-playbook-field', ['ansibleRole' => $config['ansible_role'] ?? '', 'fieldId' => 'machine-playbook']); ?>
      <label>
        Variables <span class="muted">(YAML ou JSON)</span>
        <textarea name="ansible_extra_vars" rows="2" placeholder='{"nginx_workers":4}'><?= View::e($config['ansible_extra_vars'] ?? '') ?></textarea>
      </label>
    </details>

    <div class="actions" style="justify-content:space-between;margin-top:1rem;">
      <button type="button" class="wizard-prev btn btn-secondary" data-target-step="1">← Précédent</button>
      <button type="button" class="wizard-next btn btn-primary" data-target-step="3">Suivant →</button>
    </div>
  </fieldset>

  <fieldset class="section wizard-form-step" data-step="3" hidden>
    <legend>Disques et plan de stockage</legend>

    <?php
    // Construire le dict pre_wipe_per_disk avec clés /dev/X normalisées pour le
    // partial commun. parseWipeMode renvoie un dict {nom_court_inventory => mode} ;
    // on préfixe à /dev/ pour matcher les value des radios HTML.
    $_wipeDisks    = $machineHw['disks'] ?? [];
    $_savedWipeMode = $config['pre_wipe_mode'] ?? 'aucun';
    $_savedDict    = Admin\Models\Preset::parseWipeMode($_savedWipeMode, array_column($_wipeDisks, 'name'));
    $_preWipePerDisk = [];
    foreach ($_savedDict as $_n => $_m) {
        $_key = str_starts_with($_n, '/dev/') ? $_n : '/dev/' . ltrim($_n, '/');
        $_preWipePerDisk[$_key] = $_m;
    }
    ?>

    <?php View::partial('_install/partitioning-section', [
      'p' => $p,
      'plan' => $plan,
      'disksInventory' => $machineHw['disks'] ?? [],
      'ramKb' => $machineHw['ram_kb'] ?? null,
      'isUefi' => $machineHw['is_uefi'] ?? false,
      'requireDiskReselection' => $hasExistingConfig && !$hasStoredDiskIdentity,
      'preWipePerDisk' => $_preWipePerDisk,
    ]); ?>

    <div class="actions" style="justify-content:space-between;margin-top:1rem;">
      <button type="button" class="wizard-prev btn btn-secondary" data-target-step="2">← Précédent</button>
      <button type="button" class="wizard-next btn btn-primary" data-target-step="4">Suivant →</button>
    </div>
  </fieldset>

  <fieldset class="section wizard-form-step" data-step="4" hidden>
    <legend>Vérifier avant de lancer</legend>
    <p class="form-hint">Vérifiez les informations ci-dessous avant de valider l'installation.</p>

    <div id="confirm-summary" aria-live="polite"><!-- récap rempli en JS au moment où on entre dans l'étape 5 --></div>

    <fieldset class="launch-timing">
      <legend>Quand démarrer ?</legend>
      <label><input type="radio" name="wake_now" value="0" <?= empty($config['wake_now']) ? 'checked' : '' ?>> Maintenant — demander un redémarrage SSH ou un réveil réseau</label>
      <label><input type="radio" name="wake_now" value="1" <?= !empty($config['wake_now']) ? 'checked' : '' ?>> Au prochain démarrage PXE — aucun redémarrage demandé</label>
      <p class="form-hint">L’installation remplace les données des disques indiqués. La demande de redémarrage ne garantit pas que la machine démarre immédiatement.</p>
    </fieldset>

    <div class="actions" style="justify-content:space-between;margin-top:1rem;">
      <button type="button" class="wizard-prev btn btn-secondary" data-target-step="3">← Précédent</button>
      <button type="submit" class="btn btn-primary" data-launch-install disabled><?= View::icon('check') ?> <?= $verb ?></button>
    </div>
  </fieldset>
</form>

<script type="application/json" id="install-initial-config"><?= json_encode($restorableConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script type="application/json" id="install-default-advanced"><?= json_encode(Preset::defaultAdvancedConfig(), JSON_HEX_TAG) ?></script>
<script type="application/json" id="install-validation-errors"><?= json_encode(InstallConfigBuilder::localizeErrors($errors ?? []), JSON_HEX_TAG) ?></script>
<script type="application/json" id="machine-hw"><?= json_encode($machineHw ?? ['is_uefi'=>false,'ram_kb'=>0,'disks'=>[]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="/builder-shared.js?v=<?= @filemtime(__DIR__ . '/../../../public/builder-shared.js') ?: time() ?>" defer></script>
<script src="/install-wizard.js?v=<?= @filemtime(__DIR__ . '/../../../public/install-wizard.js') ?: time() ?>" defer></script>
