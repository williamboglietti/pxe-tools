<?php
/**
 * Détail d'une machine.
 *
 * Variables injectées par MachineController::show via View::render() / extract() :
 * @var array            $machine
 * @var array<int,array> $presets
 * @var array|null       $latestInstall
 * @var array|null       $latestWipe
 * @var array|null       $latestTest
 * @var array            $latestTestPerDisk
 * @var array|null       $inventory
 * @var array            $wizardConfig
 * @var array<int,string> $wizardAllowedDistros
 * @var bool             $wizardHasExistingConfig
 * @var array|null       $wizardMachineHw
 * @var array<int,array> $wizardAllPresets
 */
use Admin\View;
use Admin\Models\Machine;
use Admin\Models\DiskTest;
use Admin\Models\TaskPhase;
use Admin\Util\MachineOperation;
use Admin\Util\AnsibleRedeployEnqueuer;
use Admin\Util\DiskIdentity;

$installConfig = Machine::installConfig($machine);
$operationSummary = \Admin\Util\MachineOperationSummary::forMachine($machine);
$operationRule = $operationSummary['state']['rules']['operation'];
$powerRule = $operationSummary['state']['rules']['power'];
$ansibleRule = ['ok'=>$operationSummary['state']['rules']['ansible']['allowed'],'message'=>$operationSummary['state']['rules']['ansible']['reason']];
$headerState = View::stateLabel($machine);
$isBusy = !$operationRule['allowed'];
$deleteConfirmation = 'Supprimer la fiche de ' . Machine::displayLabel($machine) . ' (' . $machine['mac'] . ') ? Statut actuel : ' . $machine['status'] . '. Les inventaires, configurations enregistrées et historiques liés seront supprimés. Cette suppression n’efface pas les disques et ne garantit pas l’arrêt des processus distants.';

// Détecte la tâche active (wipe pending/running OU installation in_progress).
// Si présente, les onglets #install et #storage masquent leur wizard et
// affichent le panel de suivi temps réel à la place.
$activeTask = TaskPhase::activeForMachine((int)$machine['id']);

// Pré-calculs hardware partagés entre les onglets (évite duplication)
$hw = [];
$cap_badges = [];
// Renvoie null si transport vide/inconnu (=> pas de badge),
// sinon le libellé propre (pour les types bien identifiés) ou la valeur brute uppercase.
$transport_label = static function (?string $t): ?string {
    $tn = strtolower(trim((string)$t));
    if ($tn === '') return null;
    return match ($tn) {
        'sata'   => 'SATA',
        'nvme'   => 'NVMe',
        'usb'    => 'USB',
        'virtio' => 'VirtIO',
        'mmc'    => 'MMC/eMMC',
        'scsi'   => 'SCSI',
        'sas'    => 'SAS',
        'ata'    => 'ATA',
        'iscsi'  => 'iSCSI',
        'fc'     => 'Fibre Channel',
        default  => strtoupper($tn),
    };
};
$disk_type_label = static function (array $disk): string {
    $metadata = DiskIdentity::storageMetadata($disk);
    $mmcType = strtoupper(trim((string)($metadata['mmc']['type'] ?? '')));
    if ($mmcType === 'MMC') return 'eMMC';
    if ($mmcType === 'SD') return 'SD natif';
    if (preg_match('~^(?:/dev/)?mmcblk[0-9]+$~D', (string)($disk['name'] ?? '')) === 1) return 'MMC/SD natif';
    $transport = strtolower(trim((string)($disk['transport'] ?? '')));
    $genericUsb = $transport === 'usb' && preg_match('/^(?:n\/a|unknown|storage device|generic(?: storage device)?|usb storage)$/iD', trim((string)($disk['model'] ?? ''))) === 1;
    if ($genericUsb) return 'Support USB';
    if (($disk['rota'] ?? null) === false) return $transport === 'usb' ? 'Support non rotatif' : 'SSD';
    return ($disk['rota'] ?? null) === true ? 'HDD' : 'Non renseigné';
};
if ($inventory) {
    $hw = $inventory['inventory'] ?? [];
    if (($hw['boot_mode'] ?? null) === 'UEFI')      $cap_badges[] = ['UEFI',             'badge-success'];
    elseif (($hw['boot_mode'] ?? null) === 'BIOS')  $cap_badges[] = ['BIOS Legacy',      'badge-neutral'];
    if (($hw['secure_boot'] ?? null) === true)      $cap_badges[] = ['Secure Boot ON',  'badge-success'];
    if (($hw['secure_boot'] ?? null) === false)     $cap_badges[] = ['Secure Boot OFF', 'badge-neutral'];
    if (($hw['tpm_present'] ?? null) === true)      $cap_badges[] = ['TPM ' . ($hw['tpm_version'] ?? '?'), 'badge-success'];
    if (($hw['cpu_virt'] ?? null) === 'vmx')        $cap_badges[] = ['Intel VT-x',       'badge-success'];
    if (($hw['cpu_virt'] ?? null) === 'svm')        $cap_badges[] = ['AMD-V',            'badge-success'];
}
?>

<?php View::partial('machines/_operation_header', ['machine'=>$machine, 'summary'=>$operationSummary]); ?>
<script src="/machine-operations.js?v=<?= @filemtime(__DIR__.'/../../../public/machine-operations.js') ?: time() ?>" defer></script>
<?php
$installVerb = in_array($machine['status'], ['installed','provisioned'], true) ? 'Réinstaller' : 'Installer';
// Onglet par défaut : toujours Aperçu. Le bandeau d'onboarding informe quand des
// attributs restent à renseigner — pas de redirection automatique.
$defaultTab = 'overview';
$tabActive = static fn(string $t): string => $t === $defaultTab ? ' active' : '';
$tabHidden = static fn(string $t): string => $t === $defaultTab ? '' : ' hidden';

?>

<nav class="tabs" aria-label="Sections de la machine">
  <a href="#overview" class="tab<?= $tabActive('overview') ?>" data-tab="overview">Aperçu</a>
  <a href="#storage"  class="tab<?= $tabActive('storage') ?>" data-tab="storage">Stockage</a>
  <a href="#install"  class="tab<?= $tabActive('install') ?>" data-tab="install"><?= $installVerb ?></a>
  <a href="#events"   class="tab<?= $tabActive('events') ?>" data-tab="events">Activité</a>
  <a href="#settings" class="tab<?= $tabActive('settings') ?>" data-tab="settings">Paramètres</a>
</nav>

<!-- ============================================================ -->
<!-- ONGLET : APERÇU — panel style provider serveur dédié          -->
<!-- ============================================================ -->
<div id="overview" tabindex="-1" data-tab-content="overview"<?= $tabHidden('overview') ?>>
<?php
// Calculs panel pour le bandeau d'identité + cards
$state = View::stateLabel($machine);
$ipDisplay = $inventory['discovery_ip'] ?? null;
$osDisplay = $installConfig ? 'Prévu : ' . ($installConfig['distro'] ?? '—') . ' ' . ($installConfig['codename'] ?? '') : 'Aucune configuration prévue';

// CPU
$cpuModel = trim((string)($hw['cpu_model'] ?? ''));
$cpuCores = isset($hw['cpu_cores']) ? (int)$hw['cpu_cores'] : null;
$cpuMaxMhz     = isset($hw['cpu_max_mhz'])     && $hw['cpu_max_mhz']     !== null ? (int)$hw['cpu_max_mhz']     : null;
$cpuCurrentMhz = isset($hw['cpu_current_mhz']) && $hw['cpu_current_mhz'] !== null ? (int)$hw['cpu_current_mhz'] : null;
$cpuFreqLabel  = View::formatCpuFreq($cpuMaxMhz);    // null si pas dispo
$cpuSubParts   = [];
if ($cpuCores !== null) $cpuSubParts[] = $cpuCores . ' cœurs';
if ($cpuFreqLabel !== null) $cpuSubParts[] = $cpuFreqLabel;
$cpuSub = $cpuSubParts !== [] ? implode(' · ', $cpuSubParts) : '—';

// RAM : snap à la taille canonique de la barrette. Le kernel rapporte 2-15% de moins
// que la taille physique (mémoire réservée firmware/kernel + VRAM iGPU partagée)
// → on remonte à la taille commerciale la plus proche dans une fenêtre [-15%, +5%].
// Exemples : 7,7 GiB (kernel) → 8 Go (barrette), 7,2 GiB (8 GiB - iGPU) → 8 Go,
// 14,5 GiB → 16 Go, 31,2 GiB → 32 Go.
$ramGb = null;
if (isset($hw['memory_kb'])) {
    $rawGib = (int)$hw['memory_kb'] / 1024 / 1024;
    $canonical = [1, 2, 4, 6, 8, 12, 16, 24, 32, 48, 64, 96, 128, 192, 256, 384, 512, 768, 1024, 1536, 2048];
    $ramGb = round($rawGib, 1);
    foreach ($canonical as $c) {
        if ($rawGib >= $c * 0.85 && $rawGib <= $c * 1.05) { $ramGb = $c; break; }
    }
}

// Type + fréquence RAM majoritaires (depuis memory_modules / SMBIOS Type 17).
// Affichés en sub-line de la spec-card RAM si dispo, sinon fallback "Mémoire vive".
$ramType = '';
$ramSpeedMts = 0;
if (!empty($inventory['memory_modules'])) {
    $typeCounts = $speedCounts = [];
    foreach ($inventory['memory_modules'] as $m) {
        $t = trim((string)($m['type'] ?? ''));
        if ($t !== '' && $t !== 'Unknown') $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
        $s = (int)($m['configured_speed_mts'] ?? 0);
        if ($s <= 0) $s = (int)($m['speed_mts'] ?? 0);
        if ($s > 0) $speedCounts[$s] = ($speedCounts[$s] ?? 0) + 1;
    }
    arsort($typeCounts);
    arsort($speedCounts);
    $ramType = $typeCounts ? (string)array_key_first($typeCounts) : '';
    $ramSpeedMts = $speedCounts ? (int)array_key_first($speedCounts) : 0;
}
$ramSubParts = [];
if ($ramType !== '')   $ramSubParts[] = $ramType;
if ($ramSpeedMts > 0)  $ramSubParts[] = $ramSpeedMts . "\u{00A0}MT/s";
$ramSub = $ramSubParts !== [] ? implode(' · ', $ramSubParts) : 'Mémoire vive';

// Stockage : agrège les disques pour l'affichage card
$disks = $inventory['disks'] ?? [];
$diskCount = count($disks);
$totalDiskBytes = 0;
$diskTypes = [];
$diskSizes = [];
foreach ($disks as $d) {
    $sz = (int)($d['size_bytes'] ?? 0);
    $totalDiskBytes += $sz;
    $diskSizes[] = $sz;
    $tr = strtoupper((string)($d['transport'] ?? ''));
    $mediaType = $disk_type_label($d);
    $type = ($mediaType === 'Non renseigné' ? '' : $mediaType) . ($tr ? " $tr" : '');
    $type = trim($type);
    if ($type !== '') $diskTypes[$type] = true;
}
$diskTypeLabel = implode(' · ', array_keys($diskTypes)) ?: '—';

// Détection disques uniformes : tolérance 1% pour absorber les différences mineures
// entre fabricants. Si tous identiques → "N× <per-disk>", sinon → "<total>".
$diskUniform = $diskCount > 1;
if ($diskUniform) {
    $first = $diskSizes[0];
    $tolerance = max(1, (int)($first * 0.01));
    foreach ($diskSizes as $sz) {
        if (abs($sz - $first) > $tolerance) { $diskUniform = false; break; }
    }
}
$diskPerDiskBytes = $diskUniform ? $diskSizes[0] : 0;

// Réseau : interface la plus rapide
$ifaces = $inventory['interfaces'] ?? [];
$bestSpeed = 0;
foreach ($ifaces as $i) {
    $s = (int)($i['speed_mbps'] ?? 0);
    if ($s > $bestSpeed) $bestSpeed = $s;
}
$netSpeed = $bestSpeed > 0
    ? ($bestSpeed >= 1000 ? ($bestSpeed / 1000) . ' Gbps' : $bestSpeed . ' Mbps')
    : '—';
$netCount = count($ifaces);

// An observed discovery address is distinct from the next installation plan.
$netIp = $inventory['discovery_ip'] ?? null;

// Identifiants admin
$adminLogin = $installConfig['admin_user_login'] ?? null;
$adminPubkeys = trim((string)($installConfig['admin_user_pubkeys'] ?? ''));

// Mode rescue
$inRescue = (int)($machine['rescue_mode'] ?? 0) === 1;

// $inventory['inventory'] = sous-objet hardware (CPU, firmware, TPM, etc.) capté
// par discover.sh. Cf. ligne 48 où $hw = $inventory['inventory'] est extrait
// pour les cap_badges. On le ré-extrait localement pour ne pas dépendre du
// branchement plus haut (qui est conditionnel sur $inventory non vide).
$hwInfo = is_array($inventory['inventory'] ?? null) ? $inventory['inventory'] : [];

// Spec D : arch détectée au discovery — priorité boot_arch (nomenclature Debian
// netboot : amd64/arm64/i386/armhf) puis fallback inventory.cpu_arch (uname -m brut).
$archDisplay = $machine['boot_arch'] ?? null;
if (!$archDisplay && !empty($hwInfo['cpu_arch'])) {
    $archDisplay = $hwInfo['cpu_arch'];
}

// Firmware (BIOS/UEFI) — capté par discover.sh via /sys/firmware/efi/.
// Ajout " · SB" si Secure Boot actif.
$firmwareDisplay = null;
if (!empty($hwInfo['boot_mode'])) {
    $firmwareDisplay = (string)$hwInfo['boot_mode'];
    if (!empty($hwInfo['secure_boot'])) {
        $firmwareDisplay .= ' · SB';
    }
}
?>

<section class="card mb-4" aria-label="Observation et configuration">
  <div class="grid">
    <div><strong>Dernière observation</strong><p><?= View::e($machine['last_seen_at'] ?? 'Aucune observation') ?><?= !empty($machine['last_seen_at']) ? ' UTC' : '' ?></p><small>Adresse observée : <?= View::e($inventory['discovery_ip'] ?? 'non remontée') ?>. L’état de suivi ne prouve pas que la machine est joignable.</small></div>
    <div><strong>Système observé</strong><p>Non remonté par l’inventaire PXE</p><small>La configuration prévue et les indices de démarrage ne constituent pas une détection de l’OS.</small></div>
    <div><strong>Dernière installation</strong><p><?php if ($latestInstall): ?><a href="/installations/<?= (int)$latestInstall['id'] ?>"><?= View::e(View::statusLabel($latestInstall['install_status'])) ?></a> · <?= View::e($latestInstall['ended_at'] ?? $latestInstall['started_at'] ?? '') ?><?php else: ?>Aucune installation enregistrée<?php endif; ?></p></div>
  </div>
  <p><strong>Prochaine instruction :</strong> <?= View::e(match ($machine['next_action'] ?? '') { 'reboot'=>'Redémarrer à réception par l’agent.', 'poweroff'=>'Éteindre à réception par l’agent.', default=>($machine['status']==='configured'?'Installation au prochain démarrage PXE.':'Aucune instruction de redémarrage ou d’arrêt en attente.') }) ?></p>
</section>

<!-- Bandeau d'identité — icônes SVG lucide-style (24×24, stroke currentColor) -->
<section class="machine-identity">
  <div class="machine-identity__field">
    <span class="machine-identity__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>
    </span>
    <strong><?= View::e(Machine::displayLabel($machine)) ?></strong>
  </div>
  <?php if ($archDisplay): ?>
  <div class="machine-identity__field">
    <span class="machine-identity__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/></svg>
    </span>
    <code><?= View::e($archDisplay) ?></code>
  </div>
  <?php endif; ?>
  <?php if ($firmwareDisplay): ?>
  <div class="machine-identity__field">
    <span class="machine-identity__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
    </span>
    <?= View::e($firmwareDisplay) ?>
  </div>
  <?php endif; ?>
  <?php if ($ipDisplay): ?>
  <div class="machine-identity__field">
    <span class="machine-identity__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
    </span>
    <code><?= View::e($ipDisplay) ?></code>
  </div>
  <?php endif; ?>
  <div class="machine-identity__field">
    <span class="machine-identity__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/></svg>
    </span>
    <?= View::e($osDisplay) ?>
  </div>
  <div class="machine-identity__state"
       hx-get="/machines/<?= (int)$machine['id'] ?>/status"
       hx-trigger="every 5s"
       hx-swap="innerHTML">
    <?php View::partial('machines/_status_badge', ['state' => $headerState]); ?>
  </div>
</section>

<!-- Informations de la machine : 2 colonnes (Identifiants à gauche · Notes à droite) -->
<article class="panel">
  <header><h3>Informations de la machine</h3></header>
  <div class="machine-info-grid">

    <!-- Colonne gauche : identifiants de connexion (lecture seule) -->
    <section>
      <h4 style="margin:0 0 .35rem 0; font-size:.95rem; font-weight:600;">Accès de la configuration prévue</h4>
      <p class="muted" style="margin-top:0;">Ces identifiants seront appliqués lors de l’installation. Ils ne prouvent pas que l’accès au système actuellement installé fonctionne.</p>
      <?php if ($adminLogin): ?>
        <dl class="panel__dl">
          <dt>Nom d'utilisateur</dt>
          <dd><code><?= View::e($adminLogin) ?></code></dd>
        </dl>
        <?php if ($adminPubkeys !== ''): ?>
          <details>
            <summary class="muted">Voir les clés SSH publiques (<?= count(array_filter(explode("\n", $adminPubkeys), 'trim')) ?>)</summary>
            <pre class="panel__pre"><?= View::e($adminPubkeys) ?></pre>
          </details>
        <?php else: ?>
          <p class="muted">Aucune clé SSH configurée — authentification par mot de passe uniquement.</p>
        <?php endif; ?>
        <?php View::partial('machines/_reveal_password', ['machine' => $machine, 'installConfig' => $installConfig]); ?>
      <?php else: ?>
        <p class="muted">Aucune configuration d'installation. <a href="/machines/<?= (int)$machine['id'] ?>/install">Lancer l'assistant d'installation →</a></p>
      <?php endif; ?>
    </section>

    <!-- Colonne droite : Notes (textarea éditable, dédiée — POST partial sur /machines/{id}) -->
    <section>
      <h4 style="margin:0 0 .35rem 0; font-size:.95rem; font-weight:600;">Notes</h4>
      <p class="muted" style="margin-top:0;">Annotations libres sur cette machine (localisation, propriétaire, contexte…). Modifications sauvegardées indépendamment des autres attributs.</p>
      <?php $notesValue = (string)($machine['notes'] ?? ''); ?>
      <form method="post" action="/machines/<?= (int)$machine['id'] ?>" style="margin:0;">
        <input type="hidden" name="_method" value="PUT">
        <label for="machine-notes" style="position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0;">Notes</label>
        <textarea id="machine-notes" name="notes" rows="<?= $notesValue === '' ? 2 : 6 ?>" style="width:100%; resize:vertical;"><?= View::e($notesValue) ?></textarea>
        <div class="actions mt-3 justify-end" style="margin-top:.5rem;">
          <button type="submit" class="btn btn-secondary">Enregistrer les notes</button>
        </div>
      </form>
      <?php if ($notesValue === ''): ?>
      <script>
      (function () {
        // Aligne le BAS du textarea avec le BAS du cadre clés SSH à gauche
        // quand les notes sont vides (cf. mockup design). Sans cette ligne,
        // un textarea rows=2 laisse un décalage visuel entre les deux colonnes.
        const ta = document.getElementById('machine-notes');
        if (!ta || ta.value !== '') return;
        const grid = ta.closest('.machine-info-grid');
        if (!grid) return;
        const leftSection = grid.querySelector(':scope > section:first-child');
        if (!leftSection) return;
        const align = () => {
          // Cible : bas du dernier élément visible de la colonne gauche
          // (généralement le <details> clés SSH, ou le bloc reveal-password si présent).
          const candidates = [...leftSection.children].filter(el => el.offsetHeight > 0 && !el.hidden);
          if (!candidates.length) return;
          const targetBottom = candidates[candidates.length - 1].getBoundingClientRect().bottom;
          const taTop = ta.getBoundingClientRect().top;
          const desired = Math.max(36, Math.round(targetBottom - taTop));
          ta.style.height = desired + 'px';
        };
        align();
        // Réajuste en cas de toggle d'un <details> à gauche (ex: clé SSH déroulée).
        leftSection.addEventListener('toggle', align, true);
        // Et au resize de la fenêtre (changement de breakpoint colonne).
        window.addEventListener('resize', align);
      })();
      </script>
      <?php endif; ?>
    </section>

  </div>
</article>

<!-- 4 cards hardware -->
<?php if ($inventory): ?>
<div class="machine-specs">
  <article class="spec-card spec-card--cpu">
    <div class="spec-card__icon"><?= View::icon('cpu') ?></div>
    <header>CPU</header>
    <div class="spec-card__value"><?= View::e($cpuModel ?: '—') ?></div>
    <div class="spec-card__sub"<?php
        // Tooltip : si current ≠ max, on indique la fréquence courante au moment du discovery
        if ($cpuCurrentMhz !== null && $cpuMaxMhz !== null && $cpuCurrentMhz !== $cpuMaxMhz) {
            $cur = View::formatCpuFreq($cpuCurrentMhz);
            if ($cur !== null) echo ' title="Vitesse au moment du discovery : ' . View::e($cur) . '"';
        }
    ?>><?= View::e($cpuSub) ?></div>
  </article>
  <article class="spec-card spec-card--ram">
    <div class="spec-card__icon"><?= View::icon('memory-stick') ?></div>
    <header>RAM</header>
    <div class="spec-card__value"><?= $ramGb !== null ? View::e(str_replace('.', ',', (string)$ramGb)) . "\u{00A0}Go" : '—' ?></div>
    <div class="spec-card__sub"><?= View::e($ramSub) ?></div>
  </article>
  <article class="spec-card spec-card--storage">
    <div class="spec-card__icon"><?= View::icon('hard-drive') ?></div>
    <header>Stockage</header>
    <div class="spec-card__value">
      <?php
      if ($diskCount === 0) {
          echo '—';
      } elseif ($diskCount === 1) {
          echo View::e(View::formatBytes($totalDiskBytes));
      } elseif ($diskUniform) {
          echo $diskCount . '×' . "\u{00A0}" . View::e(View::formatBytes($diskPerDiskBytes));
      } else {
          echo View::e(View::formatBytes($totalDiskBytes)) . ' (' . $diskCount . ' disques)';
      }
      ?>
    </div>
    <div class="spec-card__sub"><?= View::e($diskTypeLabel) ?></div>
  </article>
  <article class="spec-card spec-card--network">
    <div class="spec-card__icon"><?= View::icon('ethernet') ?></div>
    <header>Réseau</header>
    <div class="spec-card__value"><?= View::e($netSpeed) ?></div>
    <div class="spec-card__sub"><?= $netCount > 0 ? View::e($netCount . ' interface' . ($netCount > 1 ? 's' : '')) : '—' ?></div>
  </article>
</div>
<?php else: ?>
<article>
  <p class="muted">Aucun inventaire matériel disponible pour le moment. Démarrez la machine en PXE pour qu'elle effectue sa phase d'inventaire automatique.</p>
</article>
<?php endif; ?>

<details class="card mb-4"><summary>Détails de l’inventaire matériel</summary>
<?php if (!empty($inventory['memory_modules'])): ?>
<article>
  <?php
    $modules = $inventory['memory_modules'];
    // Type majoritaire pour le résumé summary (ex: "DDR4")
    $typeCounts = [];
    foreach ($modules as $m) {
        $t = trim((string)($m['type'] ?? ''));
        if ($t !== '' && $t !== 'Unknown') $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
    }
    arsort($typeCounts);
    $primaryType = $typeCounts ? array_key_first($typeCounts) : '';
  ?>
  <details<?= count($modules) <= 4 ? ' open' : '' ?>>
    <summary><strong>Mémoire</strong> <span class="muted text-small">· <?= count($modules) ?> module(s)<?= $primaryType !== '' ? ' · ' . View::e($primaryType) : '' ?></span></summary>
    <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Slot</th><th>Taille</th><th>Type</th><th>Vitesse</th><th>Part Number</th><th>Serial</th></tr>
      </thead>
      <tbody>
        <?php foreach ($modules as $m):
          $sizeMb = (int)($m['size_mb'] ?? 0);
          // RAM : convention binaire (1 Go = 1024 MiB), pas décimale comme les
          // disques. SMBIOS retourne size_mb en MiB → on divise par 1024 pour Go.
          // 4096 MiB → 4 Go (et non 4,3 Go que donnerait formatBytes décimal).
          if ($sizeMb >= 1024) {
              $sizeGib = $sizeMb / 1024;
              $sizeLabel = (fmod($sizeGib, 1) === 0.0 ? (string)(int)$sizeGib : str_replace('.', ',', (string)round($sizeGib, 1))) . "\u{00A0}Go";
          } elseif ($sizeMb > 0) {
              $sizeLabel = $sizeMb . "\u{00A0}Mo";
          } else {
              $sizeLabel = '—';
          }
          $speed = isset($m['speed_mts']) && $m['speed_mts'] !== null ? (int)$m['speed_mts'] : null;
          $cspeed = isset($m['configured_speed_mts']) && $m['configured_speed_mts'] !== null ? (int)$m['configured_speed_mts'] : null;
          $speedLabel = $speed ? ($speed . "\u{00A0}MT/s") : '—';
          if ($cspeed && $cspeed !== $speed) $speedLabel .= ' (configurée : ' . $cspeed . "\u{00A0}MT/s)";
        ?>
          <tr>
            <td><code class="text-small"><?= View::e((string)($m['locator'] ?? '?')) ?></code></td>
            <td><strong><?= View::e($sizeLabel) ?></strong></td>
            <td><?php if (!empty($m['type']) && $m['type'] !== 'Unknown'): ?><span class="status-badge badge-info"><?= View::e($m['type']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="text-small"><?= View::e($speedLabel) ?></td>
            <td class="text-small"><?= !empty($m['part_number']) ? '<code>' . View::e($m['part_number']) . '</code>' : '<span class="muted">—</span>' ?></td>
            <td class="text-small"><?= !empty($m['serial']) ? '<code>' . View::e($m['serial']) . '</code>' : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
</article>
<?php endif; ?>

<?php if (!empty($inventory['interfaces'])): ?>
<article>
  <details<?= count($inventory['interfaces']) <= 2 ? ' open' : '' ?>>
    <summary><strong>Interfaces réseau</strong> <span class="muted text-small">· <?= count($inventory['interfaces']) ?></span></summary>
    <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Type</th><th>Nom</th><th>Adresse IP</th><th>MAC</th><th>Modèle</th><th>Vitesse</th><th>État</th></tr>
      </thead>
      <tbody>
        <?php foreach ($inventory['interfaces'] as $i):
            $type = (string)($i['type'] ?? 'ethernet');  // fallback pour les fiches pré-fix
            $vendorLabel = View::pciVendorLabel($i['pci_vendor_id'] ?? null);
            $deviceId    = (string)($i['pci_device_id'] ?? '');
            $linkClass   = ($i['link'] ?? 'unknown') === 'up' ? 'badge-success' : 'badge-neutral';
            $ipv4List    = is_array($i['ipv4'] ?? null) ? $i['ipv4'] : [];
        ?>
          <tr>
            <td style="white-space:nowrap;"><?= View::ifaceTypeIcon($type) ?></td>
            <td><code><?= View::e($i['name'] ?? '?') ?></code></td>
            <td>
              <?php if ($ipv4List): ?>
                <?php foreach ($ipv4List as $idx => $cidr): ?>
                  <?= $idx > 0 ? '<br>' : '' ?><code><?= View::e((string)$cidr) ?></code>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><code class="text-small muted"><?= View::e($i['mac'] ?? '') ?></code></td>
            <td><?= $vendorLabel !== '' ? View::e($vendorLabel) : '<span class="muted">—</span>' ?><?php if ($deviceId !== ''): ?> <span class="text-small muted">(<?= View::e($deviceId) ?>)</span><?php endif; ?></td>
            <td>
              <?= View::e(View::formatSpeedMbps(isset($i['speed_mbps']) ? (int)$i['speed_mbps'] : null)) ?>
              <?php if (isset($i['mtu']) && $i['mtu'] !== null): ?>
                <br><span class="muted text-small">MTU <?= (int)$i['mtu'] ?></span>
              <?php endif; ?>
            </td>
            <td><span class="status-badge <?= $linkClass ?>"><?= View::e((string)($i['link'] ?? '—')) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
</article>
<?php endif; ?>

<?php
// Résolution écran : déduite du framebuffer au discovery
// (display_probe.fb0_virtual_size, format "1366,768"). Fiable ≈ native en UEFI
// (efifb adopte le mode GOP du firmware = la résolution de l'écran branché) ; en
// BIOS c'est le placeholder vesafb (vga=788) → non significatif. La voie EDID/DRM
// n'est pas exploitable en discovery : l'initrd omet le firmware non-libre → KMS
// non chargé → /sys/class/drm vide (connectors=[]). Mesuré 2026-06-07.
// 800×600 (et moins) = le mode framebuffer générique au boot (vesafb vga=788 en
// BIOS, ou fallback efifb sans signal en UEFI) → pas un vrai écran. Au-dessus,
// c'est le mode GOP adopté par efifb ≈ la résolution native de l'écran branché.
$screenRes = null; $screenResNote = null;
$dp = $inventory['display_probe'] ?? null;
$fb = is_array($dp) ? trim((string)($dp['fb0_virtual_size'] ?? '')) : '';
if (preg_match('/^(\d+),(\d+)$/', $fb, $mRes)) {
    $w = (int)$mRes[1]; $h = (int)$mRes[2];
    if ($w <= 800 && $h <= 600) {
        $screenResNote = 'non détectée (mode générique au démarrage)';
    } else {
        $screenRes = $w . ' × ' . $h;
    }
}
$gpus = $inventory['gpus'] ?? [];
if (!empty($gpus) || $screenRes !== null || $screenResNote !== null): ?>
<article>
  <details>
    <summary><strong>Affichage / GPU</strong><?php if (!empty($gpus)): ?> <span class="muted text-small">· <?= count($gpus) ?></span><?php endif; ?></summary>
    <?php if ($screenRes !== null): ?>
      <p style="margin-top:0.5rem;">Résolution écran : <strong><?= View::e($screenRes) ?></strong> <span class="muted text-small">(framebuffer au démarrage)</span></p>
    <?php elseif ($screenResNote !== null): ?>
      <p class="muted text-small" style="margin-top:0.5rem;">Résolution écran : <?= View::e($screenResNote) ?></p>
    <?php endif; ?>
    <?php if (!empty($gpus)): ?>
    <ul style="margin-top:0.5rem;">
      <?php foreach ($gpus as $g): ?>
        <li>
          <code><?= View::e($g['slot'] ?? '?') ?></code>
          · <?= View::e(trim(($g['vendor'] ?? '') . ' ' . ($g['model'] ?? ''))) ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </details>
</article>
<?php endif; ?>

<?php
// Section USB toujours visible si l'inventaire est récent (clé `usb_devices`
// présente même si tableau vide). Si la clé est absente, c'est une fiche
// pré-fix discovery — on affiche un hint pour cliquer sur Re-discover.
$usbCollected = is_array($inventory) && array_key_exists('usb_devices', $inventory);
$usbList = $usbCollected ? ($inventory['usb_devices'] ?? []) : [];
?>
<article>
  <details<?= !empty($usbList) && count($usbList) <= 4 ? ' open' : '' ?>>
    <summary><strong>Périphériques USB</strong> <span class="muted text-small">· <?= $usbCollected ? count($usbList) : 'non collecté' ?></span></summary>
    <?php if (!$usbCollected): ?>
      <p class="muted">L'inventaire de cette machine date d'avant la collecte USB. Cliquez sur <strong>Re-collecter l'inventaire</strong> dans l'onglet Aperçu pour rafraîchir.</p>
    <?php elseif (empty($usbList)): ?>
      <p class="muted">Aucun périphérique USB détecté lors du dernier discovery (hors hubs root).</p>
    <?php else: ?>
      <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Classe</th><th>VID:PID</th><th>Vendor</th><th>Produit</th><th>Serial</th><th>Vitesse</th></tr>
        </thead>
        <tbody>
          <?php foreach ($usbList as $u):
              $vid = (string)($u['vid'] ?? '');
              $pid = (string)($u['pid'] ?? '');
              $vendorName = trim((string)($u['vendor_name'] ?? ''));
              if ($vendorName === '') $vendorName = View::pciVendorLabel($vid);
              $speed = isset($u['speed_mbps']) ? (int)$u['speed_mbps'] : null;
          ?>
            <tr>
              <td><span class="status-badge badge-neutral text-small"><?= View::e(View::usbClassLabel($u['class'] ?? null)) ?></span></td>
              <td><code class="text-small"><?= View::e($vid . ':' . $pid) ?></code></td>
              <td><?= $vendorName !== '' ? View::e($vendorName) : '<span class="muted">—</span>' ?></td>
              <td><?= !empty($u['product_name']) ? View::e($u['product_name']) : '<span class="muted">—</span>' ?></td>
              <td class="text-small"><?= !empty($u['serial']) ? '<code>' . View::e($u['serial']) . '</code>' : '<span class="muted">—</span>' ?></td>
              <td class="text-small"><?= $speed ? View::e(View::formatSpeedMbps($speed)) : '<span class="muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </details>
</article>
</details>
</div>

<!-- ============================================================ -->
<!-- ONGLET : STOCKAGE                                            -->
<!-- ============================================================ -->
<div id="storage" tabindex="-1" data-tab-content="storage"<?= $tabHidden('storage') ?>>
<?php if ($inventory): ?>

<!-- Helper rendu du badge "dernier résultat de test" pour un disque -->
<?php
$renderDiskTestBadge = static function (?array $r) {
    if ($r === null) return '';
    $verdict = (string)($r['verdict'] ?? '');
    $cls = match ($verdict) {
        'passed' => 'badge-success',
        'failed' => 'badge-danger',
        default  => 'badge-neutral',
    };
    // Libellé de RÉSULTAT : l'intention du mode seulement — l'avertissement du
    // menu (« (EFFACEMENT !) ») n'a pas de sens sur un test déjà effectué.
    $mode = (string)($r['mode'] ?? '');
    $kind = str_starts_with($mode, 'smart_') ? 'SMART'
          : (str_contains($mode, '_rw') ? 'Réparation' : 'Vérification');
    $verdictTxt = match ($verdict) {
        'passed' => 'OK',
        'failed' => 'défauts détectés',
        default  => 'indéterminé',
    };
    $bb = isset($r['bad_blocks']) ? (int)$r['bad_blocks'] : null;
    $when = '';
    if (!empty($r['completed_at'])) {
        $ts = strtotime((string)$r['completed_at']);
        if ($ts) {
            $diff = time() - $ts;
            if ($diff < 60)        $when = 'à l\'instant';
            elseif ($diff < 3600)  $when = 'il y a ' . (int)floor($diff/60) . ' min';
            elseif ($diff < 86400) $when = 'il y a ' . (int)floor($diff/3600) . ' h';
            else                   $when = 'il y a ' . (int)floor($diff/86400) . ' j';
        }
    }
    $subParts = [];
    if ($bb !== null && ($bb > 0 || $verdict === 'passed')) {
        $subParts[] = $bb > 0
            ? $bb . ' secteur' . ($bb > 1 ? 's' : '') . ' défectueux'
            : 'aucun défaut';
    }
    if ($when !== '') $subParts[] = $when;
    return '<a href="/disktests/' . (int)$r['test_id'] . '" class="disk-test-badge" style="display:inline-block; text-decoration:none;">'
         . '<span class="status-badge ' . $cls . '" style="white-space:nowrap;">' . htmlspecialchars($kind . ' ' . $verdictTxt) . '</span>'
         . ($subParts !== []
             ? '<span class="text-small muted" style="display:block; white-space:nowrap; margin-top:.25rem;">' . htmlspecialchars(implode(' · ', $subParts)) . '</span>'
             : '')
         . '</a>';
};
?>

<article>
  <header class="row-flex-spread"><strong>Disques</strong></header>
  <?php
    // Réparation proposée seulement hors test actif (pendant un test, le suivi
    // de la section « Santé des disques » prend le relais).
    $dtRepairable = $operationRule['allowed'];
  ?>
  <?php if (empty($inventory['disks'])): ?>
    <p class="muted">Aucun disque détecté.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Transport</th><th>Nom</th><th>Taille</th><th>Type</th>
          <th>Modèle</th><th>N° de série</th><th>Firmware</th>
          <th>SMART</th><th>Fiabilité</th><th>Dernier test</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inventory['disks'] as $d):
          $mediaType = $disk_type_label($d);
          $diskName = $d['name'] ?? '';
          $diskSerial = DiskIdentity::normalize($d['identity']['value'] ?? $d['serial'] ?? null);
          $lastReliability = $diskSerial !== null ? ($diskReliability[$diskSerial]['latest'] ?? null) : null;
          $r = is_array($lastReliability['result'] ?? null)
              ? $lastReliability['result'] + ['test_id' => $lastReliability['test_id']] : null;
          if ($r !== null && ($lastReliability['verdict']['grade'] ?? '?') === '?' && ($r['verdict'] ?? '') === 'passed') {
              $r['verdict'] = 'unknown';
          }
          $tr = $transport_label($d['transport'] ?? null);
          // « ATA » n'est pas un fabricant (c'est le bus, valeur sysfs constante
          // des disques SATA) : on ne montre que le modèle dans ce cas.
          $dVendor = (string)($d['vendor'] ?? '');
          if (strtoupper(trim($dVendor)) === 'ATA') $dVendor = '';
          $vendorModel = trim($dVendor . ' ' . ($d['model'] ?? ''));
          if ($vendorModel === '') $vendorModel = '—';
          $diskIdentity = is_array($d['identity'] ?? null) ? $d['identity'] : null;
          $diskIdentityStatus = (string)($diskIdentity['status'] ?? (DiskIdentity::fromDisk($d) !== null ? 'legacy' : 'missing'));
          $diskIdentitySource = (string)($diskIdentity['source'] ?? 'legacy');
        ?>
          <tr>
            <td><?php if ($tr !== null): ?><span class="status-badge badge-info"><?= View::e($tr) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><code><?= View::e($diskName ?: '?') ?></code></td>
            <td><strong><?= View::e(View::formatBytes(isset($d['size_bytes']) ? (int)$d['size_bytes'] : 0)) ?></strong></td>
            <td><span class="status-badge <?= $mediaType === 'SSD' ? 'badge-success' : 'badge-neutral' ?>"><?= View::e($mediaType) ?></span></td>
            <td><?= View::e($vendorModel) ?></td>
            <td class="text-small">
              <?= !empty($d['serial']) ? '<code>' . View::e($d['serial']) . '</code>' : (($diskIdentity['kind'] ?? '') === 'wwid' && !empty($diskIdentity['value']) ? 'WWID <code>' . View::e($diskIdentity['value']) . '</code>' : '<span class="muted">—</span>') ?>
              <span class="muted" style="display:block;"><?= $diskIdentityStatus === 'valid' ? 'Vérifié · ' . View::e($diskIdentitySource) : ($diskIdentityStatus === 'ambiguous' ? 'Ambigu' : ($diskIdentityStatus === 'legacy' ? 'Ancien inventaire · discovery requis' : 'Identité indisponible')) ?></span>
            </td>
            <td class="text-small"><?= !empty($d['firmware']) ? '<code>' . View::e($d['firmware']) . '</code>' : '<span class="muted">—</span>' ?></td>
            <td><?= View::smartBadge($d['smart'] ?? null) ?></td>
            <td>
              <?php $healthGrade = (string)($lastReliability['verdict']['grade'] ?? '?');
                $healthClass = match ($healthGrade) { 'A'=>'badge-success', 'B'=>'badge-info', 'C'=>'badge-warning', 'D'=>'badge-danger', default=>'badge-neutral' }; ?>
              <span class="status-badge <?= $healthClass ?>" title="<?= View::e((string)($lastReliability['verdict']['placement'] ?? 'Test complet requis')) ?>"><?= View::e($healthGrade) ?></span>
            </td>
            <td style="white-space:nowrap;">
              <?= $renderDiskTestBadge($r) ?: '<span class="muted">—</span>' ?>
              <?php if ($dtRepairable && $r !== null && ($r['verdict'] ?? '') === 'failed' && $diskName !== '' && $diskIdentityStatus === 'valid'): ?>
                <form method="post" action="/machines/<?= (int)$machine['id'] ?>/disktest" data-operation-kind="operation"
                      style="margin:.3rem 0 0 0;"
                      onsubmit="return dtConfirmRepair('<?= View::e($diskName) ?>');">
                  <input type="hidden" name="disks[]" value="<?= View::e($diskName) ?>">
                  <input type="hidden" name="disk_serials[<?= View::e($diskName) ?>]" value="<?= View::e((string)$diskSerial) ?>">
                  <input type="hidden" name="modes[<?= View::e($diskName) ?>]" value="<?= ($d['rota'] ?? null) === true ? 'badblocks_fixed_rw' : 'badblocks_quick_rw' ?>">
                  <input type="hidden" name="post_action" value="">
                  <button type="submit" class="btn btn-danger"
                          style="width:auto; padding:.15rem .55rem; font-size:.78rem;"
                          title="Réécrit tout le disque pour forcer le remplacement des secteurs défectueux — efface les données">
                    Réparer…
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td colspan="10"><?php View::partial('machines/_storage_metadata', ['disk' => $d]); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <script>
      function dtConfirmRepair(disk) {
        var typed = prompt(
          'Attention — Réparation de ' + disk + ' :\n\n' +
          'La réparation réécrit tout le disque pour forcer le remplacement des secteurs ' +
          'défectueux par la réserve interne du disque.\n' +
          'TOUTES LES DONNÉES de ' + disk + ' seront EFFACÉES.\n\n' +
          'Saisissez "DETRUIRE" (en majuscules) pour confirmer :'
        );
        return typed === 'DETRUIRE';
      }
    </script>
  <?php endif; ?>
</article>

<?php if (!empty($inventory['disks'])): ?>
<?php View::partial('machines/_disk_reliability', ['diskReliability' => $diskReliability ?? []]); ?>
<!-- ============================================================ -->
<!-- Section : Effacement des disques (wipe)                       -->
<!-- ============================================================ -->
<?php if (!$operationRule['allowed']): ?>
  <p class="flash flash-info"><?= View::e($operationRule['reason']) ?> <a href="#events" data-tab="events">Voir l’activité</a></p>
<?php elseif ($activeTask !== null && $activeTask['type'] === 'wipe'): ?>
  <?php
    $phases = TaskPhase::listForTask($activeTask['type'], $activeTask['id']);
    $progress = TaskPhase::progress($phases);
    View::partial('machines/_task_progress', [
        'machine'  => $machine,
        'task'     => $activeTask,
        'phases'   => $phases,
        'progress' => $progress,
    ]);
  ?>
<?php else: ?>
<article>
  <details><summary>Effacer des disques — action destructive</summary>
  <form method="post" action="/machines/<?= (int)$machine['id'] ?>/wipe-disks" id="wipe-form" data-operation-kind="operation">
    <table class="wipe-disks-table" style="margin:0;">
      <thead>
        <tr>
          <th>Disque</th>
          <th style="text-align:center;">Aucun</th>
          <th style="text-align:center;">Partiel <span class="muted text-small">(~14 GB)</span></th>
          <th style="text-align:center;">Complet <span class="muted text-small">(tout)</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inventory['disks'] as $d):
          $size_gb = isset($d['size_bytes']) ? round($d['size_bytes'] / 1e9, 1) : '?';
          $diskName = $d['name'] ?? '';
          $diskKey = View::e($diskName);
          $radioName = 'disks_modes[' . $diskKey . ']';
          $wipeIdentity = is_array($d['identity'] ?? null) ? $d['identity'] : [];
          $wipeSerial = ($wipeIdentity['status'] ?? '') === 'valid' ? (string)($wipeIdentity['value'] ?? '') : '';
        ?>
          <tr>
            <td>
              <code><?= View::e($diskName ?: '?') ?></code>
              <span class="muted text-small"> · <?= $size_gb ?> GB</span>
              <span class="muted text-small" style="display:block;">N° série : <?= $wipeSerial !== '' ? View::e($wipeSerial) : 'indisponible ou ambigu' ?></span>
              <input type="hidden" name="disk_serials[<?= $diskKey ?>]" value="<?= View::e($wipeSerial) ?>">
            </td>
            <td style="text-align:center;">
              <input type="radio" name="<?= $radioName ?>" value="" checked style="margin:0;">
            </td>
            <td style="text-align:center;">
              <input type="radio" name="<?= $radioName ?>" value="partial" style="margin:0;" <?= $wipeSerial === '' ? 'disabled' : '' ?>>
            </td>
            <td style="text-align:center;">
              <input type="radio" name="<?= $radioName ?>" value="full" style="margin:0;" <?= $wipeSerial === '' ? 'disabled' : '' ?>>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="display:flex; gap:1rem; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; margin-top:0.75rem;">
      <fieldset style="flex:1 1 0; min-width:14rem; margin:0; padding:0; border:none;">
        <legend style="padding:0; font-size:0.875rem;">Action après effacement</legend>
        <label style="display:flex; align-items:center; gap:0.5rem; margin:0.25rem 0;">
          <input type="radio" name="post_action" value="poweroff" checked style="margin:0;">
          <span>Éteindre</span>
        </label>
        <label style="display:flex; align-items:center; gap:0.5rem; margin:0.25rem 0;">
          <input type="radio" name="post_action" value="reboot" style="margin:0;">
          <span>Redémarrer</span>
        </label>
      </fieldset>
      <div class="actions" style="margin:0; flex:0 0 auto;">
        <button type="submit" class="btn btn-danger" onclick="return wipeConfirm()">
          Lancer l'effacement
        </button>
      </div>
    </div>
    <small class="form-hint" style="display:block; margin-top:0.5rem;">
      Sélectionnez au moins un disque, puis choisissez le mode d'effacement. La machine sera
      redémarrera ou sera réveillée, puis démarrera en PXE pour exécuter l'opération.
    </small>
  </form>
  <script>
    function wipeConfirm() {
      // Collecte les radios cochés à value != '' (= disques avec un mode partial/full)
      var checked = document.querySelectorAll('#wipe-form input[type="radio"][name^="disks_modes["]:checked');
      var selected = [];
      Array.from(checked).forEach(function(r) {
        if (r.value === '') return;
        // name = disks_modes[/dev/sda] → extraire le nom entre [ et ]
        var m = r.name.match(/^disks_modes\[(.+)\]$/);
        if (m) selected.push({ name: m[1], mode: r.value });
      });
      if (!selected.length) {
        alert('Veuillez sélectionner au moins un disque (Partiel ou Complet).');
        return false;
      }
      var summary = selected.map(function(d) { return d.name + ' → ' + d.mode; }).join('\n');
      var typed = prompt(
        'Attention — Effacement disque(s) :\n\n' + summary +
        '\n\nLes données seront PERDUES.\nSaisissez "EFFACER" (en majuscules) pour confirmer :'
      );
      return typed === 'EFFACER';
    }
  </script>
  </details>
</article>
<?php endif; ?>

<!-- ============================================================ -->
<!-- Section : Tests de disque (SMART + badblocks)                 -->
<!-- ============================================================ -->
<div class="card mb-6">
  <h3 style="margin:0 0 .25rem 0;">Santé des disques</h3>
  <?php $dtActive = isset($latestTest) && $latestTest !== null
      && in_array((string)($latestTest['job_status'] ?? ''), ['pending', 'running'], true); ?>
  <?php if ($dtActive): ?>
  <p class="muted text-small" style="margin:0 0 1rem 0;">
    Un test est en cours sur cette machine — suivi en direct ci-dessous
    (<a href="<?= !empty($latestTest['action_id']) ? '/tasks/' . (int)$latestTest['action_id'] : '/disktests/' . (int)$latestTest['id'] ?>">page détaillée avec journal et contrôles</a>).
  </p>
  <div hx-get="/disktests/<?= (int)$latestTest['id'] ?>/progress"
       hx-trigger="load, every 3s"
       hx-swap="innerHTML">
    <p class="muted text-small">Chargement du suivi…</p>
  </div>
  <?php elseif (!$operationRule['allowed']): ?>
  <p><?= View::e($operationRule['reason']) ?></p>
  <?php else: ?>
  <p class="muted text-small" style="margin:0 0 1rem 0;">
    Vérifiez la santé des disques avant d'installer : autodiagnostic SMART ou
    vérification complète de la surface. Sélectionnez un mode par disque — les
    modes d'écriture (qui effacent les données) sont masqués par défaut.
  </p>
  <form method="post" action="/machines/<?= (int)$machine['id'] ?>/disktest" id="disktest-form" data-operation-kind="operation">

    <!-- Liste des disques -->
    <div class="field-label" style="display:block; margin-bottom: .5rem;">Disques à tester</div>
    <div style="display:flex; flex-direction:column; gap:.5rem; margin-bottom: 1rem;">
      <?php foreach ($inventory['disks'] as $d):
        $size_gb = isset($d['size_bytes']) ? round($d['size_bytes'] / 1e9, 1) : '?';
        $diskName = $d['name'] ?? '';
        // Un test n'est lié qu'à un disque identifié par son numéro de série (comme l'effacement).
        $testIdentity = is_array($d['identity'] ?? null) ? $d['identity'] : [];
        $testSerial = ($testIdentity['status'] ?? '') === 'valid' ? (string)($testIdentity['value'] ?? '') : '';
        $testable = $testSerial !== '';
      ?>
        <label class="flex items-center gap-3 <?= $testable ? 'cursor-pointer' : '' ?>"
               style="padding: .65rem .85rem; border-radius: var(--radius-card, 0.75rem); background-color: var(--color-surface-2); border: 1px solid var(--color-divider);<?= $testable ? '' : ' opacity:.6;' ?>">
          <input type="checkbox" name="disks[]" value="<?= View::e($diskName) ?>" class="dt-disk-cb" style="margin: 0;" <?= $testable ? '' : 'disabled' ?>>
          <input type="hidden" name="disk_serials[<?= View::e($diskName) ?>]" value="<?= View::e($testSerial) ?>">
          <span style="flex:1; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;">
            <code><?= View::e($diskName ?: '?') ?></code>
            <span class="muted text-small">· <?= $size_gb ?> GB</span>
            <span class="muted text-small">· N° série : <?= $testable ? '<code class="dt-disk-serial">' . View::e($testSerial) . '</code>' : 'indisponible ou ambigu — test impossible, relancez un discovery' ?></span>
          </span>
          <select name="modes[<?= View::e($diskName) ?>]" class="select dt-mode-select" style="max-width: 22rem;" <?= $testable ? '' : 'disabled' ?>>
            <?php foreach (DiskTest::MODES as $m):
              $isDestructive = in_array($m, DiskTest::MODE_DESTRUCTIVE, true);
              $label  = DiskTest::MODE_LABELS[$m] ?? $m;
              $detail = DiskTest::MODE_DETAILS[$m] ?? '';
              $hint   = DiskTest::MODE_DURATION_HINT[$m] ?? '';
            ?>
              <option value="<?= View::e($m) ?>"<?= $isDestructive ? ' data-destructive="1"' : '' ?>>
                <?= View::e($label . ($detail !== '' ? ' — ' . $detail : '') . ($hint ? ' (' . $hint . ')' : '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    </div>

    <!-- Options regroupées : modes destructifs + parallèle + action après tests -->
    <details class="disktest-options" style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: var(--radius-card, 0.75rem); background-color: var(--color-surface-2); border: 1px solid var(--color-divider);">
      <summary class="field-label" style="cursor:pointer; user-select:none;">Options</summary>
      <div style="display:flex; flex-direction:column; gap:.85rem; margin-top:.75rem;">

        <!-- Toggle modes destructifs : callout warning, label inline -->
        <label class="flex items-center gap-3 cursor-pointer"
               style="padding: 0.6rem .85rem; border-radius: var(--radius-card, 0.5rem); background-color: var(--color-danger-bg); border: 1px solid color-mix(in oklab, var(--color-danger) 25%, transparent);">
          <input type="checkbox" id="show-destructive-modes" style="margin:0;">
          <span style="flex:1; color: var(--color-danger); display:inline-flex; align-items:center; gap:.4rem; flex-wrap:wrap;">
            <?= View::icon('warning') ?>
            <strong>Afficher les modes d'écriture</strong>
            <span class="text-small" style="font-weight:normal; opacity:.85;">
              (réécriture complète — EFFACE les disques sélectionnés)
            </span>
          </span>
        </label>

        <div>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="parallel" value="1" style="margin:0;">
            <span>Tests en parallèle</span>
          </label>
          <small class="muted text-small" style="display:block; margin-top: .25rem;">Plus rapide mais contention I/O possible.</small>
        </div>

        <fieldset style="margin:0; padding:0; border:none; background:transparent; border-radius:0;">
          <legend class="field-label" style="padding: 0 0 .35rem 0; display:block;">Action après tests</legend>
          <div style="display:flex; flex-direction:column; gap:.4rem;">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" name="post_action" value="" checked style="margin:0;">
              <span>Auto <span class="muted text-small">(machine en attente : retour à l'écran d'attente · sinon redémarrer si OS installé, ou éteindre)</span></span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" name="post_action" value="reboot" style="margin:0;">
              <span>Redémarrer</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" name="post_action" value="poweroff" style="margin:0;">
              <span>Éteindre</span>
            </label>
          </div>
        </fieldset>

      </div>
    </details>

    <!-- Footer : hint à gauche + bouton à droite -->
    <div class="flex items-center justify-between gap-4 flex-wrap" style="padding-top: .75rem; border-top: 1px solid var(--color-divider);">
      <small class="muted text-small" style="flex:1; min-width: 14rem;">
        Les autodiagnostics SMART et les vérifications n'altèrent pas les données.
        Les écritures <strong style="color: var(--color-danger);">effacent les disques sélectionnés</strong>.
      </small>
      <button type="submit" class="btn btn-primary" onclick="return diskTestConfirm();">Lancer les tests</button>
    </div>
  </form>
  <script>
    (function() {
      // L'attribut HTML `hidden` sur <option> est inconstant selon les navigateurs
      // (notamment Safari l'ignore). On retire/réinjecte physiquement les options
      // destructives selon l'état du toggle pour un comportement fiable partout.
      var toggle = document.getElementById('show-destructive-modes');
      var selects = document.querySelectorAll('#disktest-form select.dt-mode-select');
      // Cache les options destructives à l'init (state OFF par défaut)
      var stash = new Map();
      selects.forEach(function(sel) {
        var opts = Array.from(sel.querySelectorAll('option[data-destructive="1"]'));
        stash.set(sel, opts);
        opts.forEach(function(o) { o.remove(); });
      });
      toggle.addEventListener('change', function() {
        selects.forEach(function(sel) {
          var cached = stash.get(sel) || [];
          if (toggle.checked) {
            // Réinjecte les options destructives à la fin du select
            cached.forEach(function(o) { sel.appendChild(o); });
          } else {
            // Retire les options destructives encore présentes (et reset le select sur la 1ère non-destructive)
            sel.querySelectorAll('option[data-destructive="1"]').forEach(function(o) { o.remove(); });
            sel.selectedIndex = 0;
          }
        });
      });
    })();
    function diskTestConfirm() {
      var form = document.getElementById('disktest-form');
      var checked = form.querySelectorAll('input.dt-disk-cb:checked');
      if (!checked.length) {
        alert('Veuillez sélectionner au moins un disque à tester.');
        return false;
      }
      var hasRw = false;
      var rwDisks = [];
      checked.forEach(function(cb) {
        var sel = form.querySelector('select[name="modes[' + cb.value + ']"]');
        if (sel && sel.value && sel.value.indexOf('_rw') !== -1) {
          hasRw = true;
          var serialInput = form.querySelector('input[name="disk_serials[' + cb.value + ']"]');
          rwDisks.push(cb.value + (serialInput && serialInput.value ? ' (N° série ' + serialInput.value + ')' : ''));
        }
      });
      if (hasRw) {
        var typed = prompt('Attention — Écriture (réécriture complète) sur :\n' + rwDisks.join(', ') + '\n\nLes données seront PERDUES.\nSaisissez "DETRUIRE" (en majuscules) pour confirmer :');
        return typed === 'DETRUIRE';
      }
      return true;
    }
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<article>
  <p class="muted">Aucun inventaire disque disponible. Démarrez d'abord la machine en PXE pour effectuer la phase d'inventaire.</p>
</article>
<?php endif; ?>

<?php if (!empty($latestWipe)):
  $wipeDisksList = json_decode($latestWipe['disks_json'] ?? '[]', true) ?: [];
?>
<article>
  <header><h3>Dernier effacement</h3></header>
  <p>
    Statut&nbsp;:
    <span class="status-badge <?= View::e(View::statusClass($latestWipe['wipe_status'] ?? null)) ?>">
      <?= View::e(View::statusLabel($latestWipe['wipe_status'])) ?>
    </span>
    &nbsp;·&nbsp; Mode&nbsp;: <strong><?= View::e($latestWipe['wipe_mode']) ?></strong>
    &nbsp;·&nbsp; Disques&nbsp;: <code><?= View::e(implode(', ', array_map(
      static fn($d) => is_array($d)
        ? ((string)($d['name'] ?? '?') . (isset($d['mode']) ? ' (' . $d['mode'] . ')' : ''))
        : (string)$d,
      $wipeDisksList
    ))) ?></code>
    &nbsp;·&nbsp; <a href="/wipes/<?= (int)$latestWipe['id'] ?>">Voir le log →</a>
  </p>
</article>
<?php endif; ?>

<?php if (!empty($latestTest)):
  $testJobs = json_decode($latestTest['jobs_json'] ?? '[]', true) ?: [];
  $testCount = count($testJobs);
?>
<article>
  <header><h3>Dernier test de disque</h3></header>
  <p>
    Statut&nbsp;:
    <span class="status-badge <?= View::e(View::statusClass($latestTest['job_status'] ?? null)) ?>">
      <?= View::e(View::statusLabel($latestTest['job_status'])) ?>
    </span>
    &nbsp;·&nbsp; <?= (int)$testCount ?> test<?= $testCount > 1 ? 's' : '' ?>
    &nbsp;·&nbsp; <?= ((int)($latestTest['parallel'] ?? 0) === 1) ? 'parallèle' : 'séquentiel' ?>
    &nbsp;·&nbsp; <a href="/disktests/<?= (int)$latestTest['id'] ?>">Voir le log →</a>
  </p>
</article>
<?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- ONGLET : ÉVÉNEMENTS — contrôle alimentation + terminal logs   -->
<!-- ============================================================ -->
<div id="events" tabindex="-1" data-tab-content="events"<?= $tabHidden('events') ?>>
<div hx-get="/machines/<?= (int)$machine['id'] ?>/boot-fragment"
     hx-trigger="every 5s" hx-swap="innerHTML">
  <?php View::partial('machines/_boot_history', ['machine' => $machine]); ?>
</div>
<?php
// Section "Contrôle alimentation à distance" : visible uniquement quand la
// machine est dans le flow discovery (en attente du poll discover.sh).
$powerVisible = View::isAwaitingAction($machine);
$nextAction = $machine['next_action'] ?? null;
$nextActionLabel = match ($nextAction) {
    'poweroff' => 'Extinction',
    'reboot'   => 'Redémarrage',
    default    => null,
};
?>
<?php if ($powerVisible): ?>
<!-- Polling HTMX 5s : synchronise l'UI quand la machine consomme une action
     (next_action passé à NULL côté DB) ou quand la fenêtre d'attente expire. -->
<div id="power-panel-wrap"
     hx-get="/machines/<?= (int)$machine['id'] ?>/power-fragment"
     hx-trigger="every 5s"
     hx-swap="innerHTML">
  <?php View::partial('machines/_power_panel', ['machine' => $machine]); ?>
</div>
<?php endif; ?>

<?php
// Spec C — Timeline des tâches (discoveries + installations + wipes + disktests).
// Vue unifiée pour suivre les cycles d'opérations métier sur la machine.
$tasks = \Admin\Models\MachineTask::recentByMachine((int)$machine['id'], 30);
?>
<article class="panel">
  <header>
    <h3>Tâches récentes</h3>
  </header>
  <p class="muted text-small">
    Discoveries, installations, effacements et tests disques. Cliquez sur une
    ligne pour le détail (logs, snapshot d'inventaire, jobs unitaires).
  </p>
  <div id="machine-tasks" data-poll-scope hx-get="/machines/<?= (int)$machine['id'] ?>/tasks" hx-trigger="every 5s" hx-swap="innerHTML">
  <?php View::partial('machines/_tasks_list', [
      'machine' => $machine,
      'tasks'   => $tasks,
  ]); ?>
  </div>
</article>

</div>

<!-- ============================================================ -->
<!-- ONGLET : INSTALLER / RÉINSTALLER — wizard intégré inline      -->
<!-- ============================================================ -->
<div id="install" tabindex="-1" data-tab-content="install"<?= $tabHidden('install') ?>>
<?php if (!$operationRule['allowed'] && $activeTask === null): ?>
  <article class="flash flash-info"><?= View::e($operationRule['reason']) ?> <a href="#events" data-tab="events">Voir l’activité</a></article>
<?php elseif ($activeTask !== null && !empty($activeTask['action_id'])): ?>
  <?php
    // Réutilise la route /tasks/{action_id}/checklist (polling adaptatif intégré).
    // Affiche l'action parente complète (wipe + install) identique à /tasks/{id}.
    $activeActionId = (int)$activeTask['action_id'];
  ?>
  <div id="checklist"
       hx-get="/tasks/<?= $activeActionId ?>/checklist"
       hx-trigger="load"
       hx-swap="outerHTML">
    <p class="muted">Chargement de la progression en cours…</p>
  </div>
<?php elseif ($activeTask !== null): ?>
  <?php
    // Fallback legacy : sous-job sans action parente (orphelin), ancien rendu.
    $phases = TaskPhase::listForTask($activeTask['type'], $activeTask['id']);
    $progress = TaskPhase::progress($phases);
    View::partial('machines/_task_progress', [
        'machine'  => $machine,
        'task'     => $activeTask,
        'phases'   => $phases,
        'progress' => $progress,
    ]);
  ?>
<?php else: ?>
  <?php View::partial('_install/wizard-form', [
      'machine'           => $machine,
      'config'            => $wizardConfig,
      'allPresets'        => $wizardAllPresets,
      'presetsGrouped'    => $wizardPresetsGrouped ?? ['compatible' => [], 'others' => $wizardAllPresets],
      'allowed_distros'   => $wizardAllowedDistros,
      'hasExistingConfig' => $wizardHasExistingConfig,
      'machineHw'         => $wizardMachineHw,
      'errors'            => [],
  ]); ?>

  <?php if ($latestInstall): ?>
  <article>
    <header><h3>Dernière installation</h3></header>
    <p>
      Installation&nbsp;:
      <span class="status-badge <?= View::e(View::statusClass($latestInstall['install_status'] ?? null)) ?>">
        <?= View::e(View::statusLabel($latestInstall['install_status'] ?? 'in_progress')) ?>
      </span>
      &nbsp;·&nbsp; Ansible&nbsp;:
      <span class="status-badge <?= View::e(View::statusClass($latestInstall['ansible_status'] ?? null)) ?>">
        <?= View::e(View::statusLabel($latestInstall['ansible_status'])) ?>
      </span>
      &nbsp;·&nbsp; <a href="/installations/<?= (int)$latestInstall['id'] ?>">Voir le log →</a>
    </p>
  </article>
  <?php endif; ?>
<?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- ONGLET : PARAMÈTRES                                          -->
<!-- ============================================================ -->
<div id="settings" tabindex="-1" data-tab-content="settings"<?= $tabHidden('settings') ?>>
<div class="card mb-6">
  <h3 style="margin:0 0 1rem 0;">Démarrage de la machine</h3>
  <form id="machine-attrs-form" method="post" action="/machines/<?= (int)$machine['id'] ?>">
    <input type="hidden" name="_method" value="PUT">
  </form>

  <?php
  // Mode de boot PXE + Modifier le statut côte à côte. Les selects sont attachés
  // au form principal vide via l'attribut HTML `form=` (un seul submit consolidé).
  // Le hostname et le domaine sont configurés via le preset ou le wizard d'install
  // (cf. install-wizard.js applyConfig) — plus éditables directement ici.
  ?>
  <div class="grid">
    <label>
      Mode de boot PXE
      <select name="boot_mode" form="machine-attrs-form">
        <option value="http" <?= ($machine['boot_mode'] ?? 'http') === 'http' ? 'selected' : '' ?>>HTTP (rapide, recommandé)</option>
        <option value="tftp" <?= ($machine['boot_mode'] ?? 'http') === 'tftp' ? 'selected' : '' ?>>TFTP (fiable, pour VMs ou firmwares lents)</option>
      </select>
      <small class="form-hint">
        HTTP est rapide (~10s pour le kernel) mais peut échouer sur certaines VMs (OVMF) ou firmwares anciens.
        En cas de timeout des boots PXE, basculez sur TFTP (plus lent — ~1-2 min — mais très fiable).
      </small>
    </label>

    <label>
      Démarrage sur disque local
      <select name="local_boot_source" form="machine-attrs-form">
        <option value="none" <?= ($machine['local_boot_source'] ?? 'none') === 'none' ? 'selected' : '' ?>>Discovery au prochain boot</option>
        <?php if (($machine['local_boot_source'] ?? 'none') === 'pxe'): ?>
          <option value="pxe" selected>Automatique · installation PXE réussie</option>
        <?php endif; ?>
        <option value="manual" <?= ($machine['local_boot_source'] ?? 'none') === 'manual' ? 'selected' : '' ?>>Démarrer sur le disque · décision manuelle</option>
      </select>
      <small class="form-hint">Le discovery peut détecter des indices de démarrage, sans activer automatiquement le disque local.
        <?php $bootCandidates = $inventory['boot_probe']['candidates'] ?? []; ?>
        <?php if ($bootCandidates): ?>
          Indices trouvés :
          <?= View::e(implode(', ', array_map(static fn($c) => ($c['device'] ?? '?') . ' (' . ($c['kind'] ?? '?') . ')', $bootCandidates))) ?>.
        <?php elseif (isset($inventory['boot_probe']) && !empty($inventory['boot_probe']['available'])): ?>
          Aucun indice trouvé lors du dernier inventaire ; cela ne garantit pas qu'aucun OS n'est présent.
        <?php else: ?>
          Examen des partitions indisponible au dernier inventaire.
        <?php endif; ?>
      </small>
    </label>

  </div>
  <div class="machine-section-action mt-4">
    <small class="form-hint">Les notes libres se modifient dans l'onglet <a href="#overview" data-tab="overview">Aperçu</a>.</small>
    <button type="submit" form="machine-attrs-form" class="btn btn-primary">Enregistrer</button>
  </div>
</div>

<?php
// Détecte si la machine a un ansible_role configuré : si non, le bouton
// "Redéployer maintenant" est désactivé (l'enqueuer refuserait avec
// ansible_role_missing, autant guider l'utilisateur côté UI).
$_ansibleRole = '';
$_machineCfgRaw = $machine['install_config_json'] ?? '';
if (is_string($_machineCfgRaw) && $_machineCfgRaw !== '') {
    $_machineCfg = json_decode($_machineCfgRaw, true);
    if (is_array($_machineCfg)) {
        $_ansibleRole = trim((string)($_machineCfg['ansible_role'] ?? ''));
    }
}
$_redeployDisabled = !$ansibleRule['ok'];
?>
<div class="card mb-6">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:.5rem;">
    <h3 style="margin:0;">Automatisation Ansible</h3>
    <form method="post" action="/machines/<?= (int)$machine['id'] ?>/ansible/pull" style="margin:0;">
      <button type="submit" class="btn btn-secondary" style="margin:0;">Actualiser le dépôt partagé</button>
    </form>
  </div>

  <p class="text-sm">Rôle : <code><?= View::e($_ansibleRole ?: 'aucun') ?></code> · cible : <?= View::e(Machine::displayLabel($machine)) ?>. Actualiser le dépôt concerne toutes les machines ; appliquer concerne uniquement cette cible.</p>
  <form method="post" action="/machines/<?= (int)$machine['id'] ?>/auto-deploy" style="margin:0;">
    <label class="toggle-switch">
      <input type="checkbox" name="auto_deploy" value="1"
             onchange="this.form.submit()"
             <?= ((int)($machine['auto_deploy'] ?? 0) === 1) ? 'checked' : '' ?>>
      <span class="toggle-slider" aria-hidden="true"></span>
      <span class="toggle-label">Redéployer automatiquement à chaque push GitHub</span>
    </label>
  </form>

  <small class="form-hint" style="display:block; margin-top:.5rem;">
    Lorsque vous activez cette option, chaque push sur le dépôt Ansible qui modifie
    un fichier concernant cette machine déclenche un redéploiement automatique
    (<code>git pull</code> + <code>ansible-playbook</code>). Nécessite que le
    webhook GitHub soit configuré et activé dans
    <a href="/settings/ansible">Paramètres → Ansible</a>.
  </small>

  <div class="ansible-actions" style="margin-top:1rem; display:flex; justify-content:flex-end;">
    <form method="post" action="/machines/<?= (int)$machine['id'] ?>/ansible/redeploy" data-operation-kind="ansible" style="margin:0;"
          onsubmit="return confirm('Lancer un redéploiement Ansible sur cette machine maintenant ?');">
      <button type="submit" class="btn btn-primary" <?= $_redeployDisabled ? 'disabled data-operation-locked="1"' : '' ?> title="<?= View::e($ansibleRule['message']) ?>">
        Appliquer à cette machine
      </button>
    </form>
  </div>
  <small class="form-hint" data-operation-reason="ansible" <?= $_redeployDisabled ? '' : 'hidden' ?>><?= View::e($ansibleRule['message']) ?></small>
</div>

<details class="card mb-6"><summary>Maintenance — récupération et nouvel inventaire</summary>
<!-- Mode démarrage + Inventaire (Identifiants de connexion remontée juste sous le bandeau d'identité) -->
<div class="machine-panels">
  <article class="panel">
    <header><h3>Mode de démarrage</h3></header>
    <p>État actuel : <strong data-operation-rescue><?= $inRescue ? 'Mode récupération' : 'Normal' ?></strong></p>
    <p class="muted">Modifiez le mode pour accéder à un environnement de récupération Debian au prochain démarrage PXE.</p>
    <?php if ($inRescue): ?>
      <form method="post" action="/machines/<?= (int)$machine['id'] ?>/rescue/exit" class="mt-3">
        <button type="submit" class="btn btn-secondary">Sortir du mode rescue</button>
      </form>
      <small class="form-hint">Le prochain démarrage PXE proposera le menu normal.</small>
    <?php else: ?>
      <form method="post" action="/machines/<?= (int)$machine['id'] ?>/rescue" data-operation-kind="operation" class="mt-3"
            onsubmit="return confirm('Démarrer la machine en mode rescue (Debian d-i en récupération) ?');">
        <button type="submit" class="btn btn-primary" <?= $operationRule['allowed'] ? '' : 'disabled data-operation-locked="1"' ?> title="<?= View::e($operationRule['reason']) ?>">Démarrer en mode récupération</button>
      </form>
    <?php endif; ?>
  </article>

  <?php
  // Re-discover : disponible sauf pendant une opération active. Permet de
  // rafraîchir l'inventaire (SMART, USB, NICs détaillées) sans toucher la config.
  $rediscoverDisabled = !$operationRule['allowed'];
  ?>
  <article class="panel">
    <header><h3>Inventaire matériel</h3></header>
    <p class="muted">Re-collecte l'inventaire (disques + SMART, NICs, périphériques USB). La machine reboote en mode discovery puis revient à l'état précédent.</p>
    <div class="machine-section-action">
      <small class="form-hint" data-operation-reason="operation" <?= $rediscoverDisabled ? '' : 'hidden' ?>><?= View::e($operationRule['reason']) ?></small>
      <form method="post" action="/machines/<?= (int)$machine['id'] ?>/rediscover" data-operation-kind="operation"
            onsubmit="return confirm('Relancer la collecte d\'inventaire ? La machine va rebooter en mode discovery.');">
        <button type="submit" class="btn btn-primary"<?= $rediscoverDisabled ? ' disabled data-operation-locked="1"' : '' ?>>Re-collecter l'inventaire</button>
      </form>
    </div>
  </article>

</div>

</details>
<details class="card mb-6" id="machine-expert">
  <summary>Expert — corriger le suivi et supprimer la fiche</summary>
  <p>Ces actions modifient le suivi de l’appliance. Elles ne garantissent pas l’arrêt d’une installation, d’un effacement ou d’un processus distant. Vérifiez l’état physique de la machine avant de relancer une opération.</p>
  <form method="post" action="/machines/<?= (int)$machine['id'] ?>/status" onsubmit="return confirm('Corriger le suivi et clôturer ses actions et sous-jobs actifs ? Aucun arrêt physique n’est garanti.');">
    <label for="machine-status">Corriger l’état de suivi</label>
    <select id="machine-status" name="status" class="select">
      <?php foreach (Machine::STATUSES as $status): ?><option value="<?= View::e($status) ?>" <?= $status===$machine['status']?'selected':'' ?>><?= View::e(View::statusLabel($status)) ?></option><?php endforeach; ?>
    </select>
    <p class="field-hint">Clôture les actions, installations, effacements, inventaires et tests actifs dans la base, puis annule les instructions et demandes en attente. Les processus déjà démarrés peuvent continuer.</p>
    <button class="btn btn-secondary" type="submit">Corriger le suivi</button>
  </form>
  <hr>
  <form method="post" action="/machines/<?= (int)$machine['id'] ?>" onsubmit="return confirm(<?= View::e(json_encode($deleteConfirmation)) ?>);">
    <input type="hidden" name="_method" value="DELETE">
    <p>Supprimer cette fiche retire ses inventaires, sa configuration et ses historiques liés. Les données sur disque ne sont pas effacées ; l’opération distante peut continuer.</p>
    <button type="submit" class="btn btn-danger">Supprimer la fiche de <?= View::e(Machine::displayLabel($machine)) ?></button>
  </form>
</details>
<?php // « Modifier le statut » a été fusionné dans la card "Attributs machine"
      // (grid 2 col avec "Mode de boot PXE"), pour éviter d'avoir une card
      // standalone réservée au debug. ?>

<?php if ($inventory): ?>
<article>
  <details>
    <summary class="muted">Expert — inventaire JSON brut</summary>
    <pre class="text-small" style="max-height:18em;overflow:auto;background:var(--color-surface-2);color:var(--color-text-primary);padding:0.75rem;border-radius:0.25rem;"><?= View::e(json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </details>
</article>
<?php endif; ?>
</div>

<script>
(() => {
  const tabs  = document.querySelectorAll('.tab[data-tab]');
  const panes = document.querySelectorAll('[data-tab-content]');
  const activate = (id) => {
    if (![...tabs].some(t => t.dataset.tab === id)) return;
    tabs.forEach(t => { t.classList.toggle('active', t.dataset.tab === id); if (t.dataset.tab === id) t.setAttribute('aria-current','page'); else t.removeAttribute('aria-current'); });
    panes.forEach(p => { p.hidden = p.dataset.tabContent !== id; });
  };
  // Délégation : capture aussi les liens externes type bandeau d'info (data-tab="...")
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-tab]');
    if (!el) return;
    e.preventDefault();
    activate(el.dataset.tab);
    history.replaceState(null, '', '#' + el.dataset.tab);
    if (!el.classList.contains('tab')) document.getElementById(el.dataset.tab)?.focus({preventScroll:true});
    if (el.dataset.installStep) document.dispatchEvent(new CustomEvent('pxe:install-step', {detail:{step:Number(el.dataset.installStep),field:'hostname'}}));
  });
  const hash = location.hash.replace('#', '');
  activate(hash || 'overview');
  window.addEventListener('hashchange', () => activate(location.hash.slice(1) || 'overview'));

  // Ferme les <details class="actions-dropdown"> ouverts au click extérieur ou Escape.
  document.addEventListener('click', e => {
    document.querySelectorAll('details.actions-dropdown[open]').forEach(d => {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.actions-dropdown[open]').forEach(d => d.removeAttribute('open'));
  });
})();
</script>
