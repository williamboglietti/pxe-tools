<?php
use Admin\View;
use Admin\Models\Machine;
$operationRule = $summary['state']['rules']['operation'];
$powerRule = $summary['state']['rules']['power'];
$ansibleRule = ['ok'=>$summary['state']['rules']['ansible']['allowed'], 'message'=>$summary['state']['rules']['ansible']['reason']];
$inventory = $summary['state']['has_inventory'];
?>
<div id="machine-operation-header" data-poll-scope data-sig="<?= View::e($summary['signature']) ?>"
     data-operation-state="<?= View::e(json_encode($summary['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
     hx-get="/machines/<?= (int)$machine['id'] ?>/operation-fragment" hx-trigger="every 5s" hx-swap="outerHTML">
<?php
$headerState = View::stateLabel($machine);
// Un boot PXE peut commencer depuis n'importe quel ancien statut : le badge
// doit suivre aussi wiped → discovered et l'entrée en attente post-discovery.
?>
<header class="row-flex-spread mb-1">
  <hgroup>
    <h1><?= View::e(Machine::displayLabel($machine)) ?></h1>
    <p class="text-mono"><?= View::e($machine['mac']) ?></p>
    <p id="machine-operation-state">Suivi : <?= View::e(View::statusLabel($machine['status'])) ?> · <?= View::e(match ($machine['next_action'] ?? '') { 'reboot'=>'Redémarrage demandé', 'poweroff'=>'Extinction demandée', default=>'Aucun ordre d’alimentation en attente' }) ?></p>
  </hgroup>
  <div class="actions">
    <?php if ($operationRule['allowed']): ?>
      <a href="/machines/<?= (int)$machine['id'] ?>/install" class="btn btn-primary" id="machine-primary-action">Configurer l’installation</a>
    <?php elseif ($operationRule['action_id']): ?>
      <a href="/tasks/<?= (int)$operationRule['action_id'] ?>" class="btn btn-primary" id="machine-primary-action">Voir la progression</a>
    <?php else: ?>
      <a href="#events" data-tab="events" class="btn btn-primary" id="machine-primary-action">Voir l’activité</a>
    <?php endif; ?>
    <a href="/machines" class="btn btn-secondary">
      <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>Retour</a>

    <details class="actions-dropdown" id="machine-actions-menu">
      <summary class="btn btn-secondary" id="machine-actions-toggle">
        <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>Actions
        <svg class="btn-icon btn-icon-after" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg></summary>
      <ul>
        <?php
          // Statuts "occupés" : machine en train de tourner sur une opération.
          // Les raccourcis d'action (install/wipe/disktest) sont masqués pour
          // éviter de proposer une opération concurrente — l'admin doit
          // attendre la fin ou forcer un reset via "Réinitialiser le statut".
          $busyStatuses = ['inventorying', 'installing', 'wiping', 'testing'];
          $isBusy = !$operationRule['allowed'];
          // `unknown` = machine jamais bootée → pas d'inventaire, donc pas de
          // disques pour wipe/disktest. Install reste possible via wizard.
          $hasInventory = !empty($inventory);
        ?>
        <?php if ($machine['status'] !== 'unknown'):
          $isConfigured = $machine['status'] === 'configured';
          $wakeLabel    = $isConfigured ? 'Réveiller et installer' : 'Réveiller (WoL)';
          $wakeConfirm  = $isConfigured
            ? 'Réveiller la machine et lancer l\'install ?'
            : 'Envoyer un magic packet WoL à cette machine ?';
        ?>
          <li>
            <form method="post" action="/machines/<?= (int)$machine['id'] ?>/wake" id="machine-menu-wake" onsubmit="return confirm(<?= View::e(json_encode($wakeConfirm)) ?>);">
              <button type="submit" class="actions-dropdown__item" <?= $powerRule['allowed'] ? '' : 'disabled' ?> title="<?= View::e($powerRule['reason']) ?>" id="machine-command-wake"><?= View::e($wakeLabel) ?></button>
            </form>
          </li>
        <?php endif; ?>
        <?php if (!$isBusy): ?>
          <li>
            <a href="/machines/<?= (int)$machine['id'] ?>/install" class="actions-dropdown__item" id="machine-menu-install">
              <?= !empty($machine['install_config_json']) ? 'Réinstaller…' : 'Installer…' ?>
            </a>
          </li>
          <?php if ($hasInventory): ?>
            <li>
              <a href="/machines/<?= (int)$machine['id'] ?>?operation=<?= View::e($summary['signature']) ?>#storage" class="actions-dropdown__item" id="machine-menu-wipe">Effacer les disques…</a>
            </li>
            <li>
              <a href="/machines/<?= (int)$machine['id'] ?>?operation=<?= View::e($summary['signature']) ?>#storage" class="actions-dropdown__item" id="machine-menu-disktest">Lancer des tests disque…</a>
            </li>
          <?php endif; ?>
        <?php endif; ?>
        <li>
          <form method="post" action="/machines/<?= (int)$machine['id'] ?>/reboot" id="machine-menu-reboot" onsubmit="return confirm('Redémarrer cette machine ?');">
            <button type="submit" class="actions-dropdown__item" <?= $powerRule['allowed'] ? '' : 'disabled' ?> title="<?= View::e($powerRule['reason']) ?>" id="machine-command-reboot">Redémarrer</button>
          </form>
        </li>
        <li>
          <form method="post" action="/machines/<?= (int)$machine['id'] ?>/shutdown" id="machine-menu-shutdown" onsubmit="return confirm('Arrêter cette machine ?');">
            <button type="submit" class="actions-dropdown__item" <?= $powerRule['allowed'] ? '' : 'disabled' ?> title="<?= View::e($powerRule['reason']) ?>" id="machine-command-shutdown">Arrêter</button>
          </form>
        </li>
        <li>
          <form method="post" action="/machines/<?= (int)$machine['id'] ?>/ansible/redeploy" id="machine-menu-ansible"
                onsubmit="return confirm('Récupérer la dernière version du dépôt Ansible et redéployer la configuration sur cette machine ?');">
            <button type="submit" class="actions-dropdown__item" <?= $ansibleRule['ok'] ? '' : 'disabled' ?> title="<?= View::e($ansibleRule['message']) ?>" id="machine-command-ansible">Appliquer Ansible à cette machine</button>
          </form>
        </li>
        <?php if (!empty($machine['rescue_mode'])): ?>
        <li><form method="post" action="/machines/<?= (int)$machine['id'] ?>/rescue/exit"><button type="submit" class="actions-dropdown__item" id="machine-command-rescue-exit">Quitter le mode récupération</button></form></li>
        <?php endif; ?>
        <li><a href="#settings" data-tab="settings" class="actions-dropdown__item">Maintenance et récupération Expert…</a></li>
      </ul>
    </details>
    </div>
</header>

<?php
// Onboarding (hostname à renseigner) vs opération en cours : calculés ensemble
// pour éviter d'empiler les deux bandeaux pendant l'inventaire.
$needsAttributes  = empty(trim((string)($machine['hostname'] ?? '')));
$_busyStatuses    = ['inventorying', 'installing', 'wiping', 'testing'];
$_isBusyForBanner = !$operationRule['allowed'];
?>

<?php if ($needsAttributes && !$_isBusyForBanner): ?>
<article class="flash flash-info" data-persistent style="margin-bottom:1rem;">
  <strong>À configurer.</strong>
  Choisissez le nom d’hôte dans <a href="#install" class="tab-link" data-tab="install" data-install-step="2" id="machine-identity-setup">Installer → Identité et réseau</a>. Il sera appliqué lors de l’installation.
</article>
<?php endif; ?>

<?php
// Bandeau "opération en cours" : explique pourquoi les actions sont masquées.
// La procédure de déblocage (« Forcer la clôture ») vit dans l'onglet Événements,
// à côté du bouton — pas dans ce bandeau.
if ($_isBusyForBanner):
  $_busyLabel = match ($machine['status']) {
    'inventorying' => 'Inventaire en cours',
    'installing'   => 'Installation en cours',
    'wiping'       => 'Effacement en cours',
    'testing'      => 'Tests disque en cours',
    default        => 'Action programmée ou indisponible',
  };
?>
<article class="flash flash-info" data-persistent style="margin-bottom:1rem;">
  <strong><?= View::e($_busyLabel) ?>.</strong>
  <?= View::e($operationRule['reason']) ?>
  <?php if ($operationRule['action_id']): ?><a href="/tasks/<?= (int)$operationRule['action_id'] ?>">Voir la progression</a><?php else: ?><a href="#events" data-tab="events">Voir l’activité</a><?php endif; ?>
</article>
<?php endif; ?>

</div>
