<?php
/**
 * Partial — bloc réseau (DHCP/Static + CIDR + DNS rows).
 *
 * Phase 3 chantier preset-hardware-aware — refonte multi-NIC.
 *
 * Deux modes d'affichage :
 *
 *   1. **Fallback single-NIC (legacy)** — si $nicsInventory est vide (preset sans
 *      réf machine, ou form pré-Phase 3). Rend le markup historique avec les
 *      champs flat `network_mode`, `static_ip`, `static_cidr`, `static_gateway`,
 *      `dns[]` (collecté par InstallConfigBuilder::buildFromPost via les colonnes
 *      flat). Garantit la rétrocompat des controllers existants.
 *
 *   2. **Multi-NIC** — si $nicsInventory contient ≥1 entrée. Rend une carte par
 *      interface découverte, en utilisant `network[interfaces][N][...]` qui sera
 *      collecté par `InstallConfigBuilder::extractNetworkPlan()`. La désignation
 *      de la NIC primaire est exclusive (radio).
 *
 * Variables injectées par View::partial() via extract() :
 * @var array               $p              config courante (network_mode, static_ip, static_cidr, static_gateway)
 * @var array<int,string>   $cidrChoices    [int => string]  cf. Preset::cidrChoices()
 * @var array<int,string>   $dnsList        liste de DNS pré-remplie (mode legacy)
 * @var array<int,array<string,mixed>> $nicsInventory  optionnel — HardwareContext['nics']
 * @var array               $networkPlan    optionnel — plan multi-NIC pré-rempli (Preset::parseNetwork())
 */
use Admin\View;
use Admin\Models\Preset;
use Admin\Util\HardwareContext;

// Variables optionnelles : default safe
$nicsInventory = isset($nicsInventory) && is_array($nicsInventory) ? $nicsInventory : [];
$networkPlan   = isset($networkPlan) && is_array($networkPlan) ? $networkPlan : null;
$bootMac       = (string)($bootMac ?? '');
$preferBootNic = (bool)($preferBootNic ?? false);

// DNS proposés uniquement s'ils ont été configurés dans les paramètres.
$_defDns = Preset::defaultDns();
// Deux champs visibles, sans compléter silencieusement une valeur existante.
while (count($dnsList) < 2) {
    $dnsList[] = '';
}

// ===== Mode FALLBACK single-NIC (legacy) =====
if (empty($nicsInventory)):
?>
<div role="group" style="margin-bottom:0.75rem;">
  <label><input type="radio" name="network_mode" value="dhcp" <?= $p['network_mode'] === 'dhcp' ? 'checked' : '' ?>> DHCP <span class="muted">(attribué automatiquement)</span></label>
  <label><input type="radio" name="network_mode" value="static" <?= $p['network_mode'] === 'static' ? 'checked' : '' ?>> Statique <span class="muted">(défini dans la configuration)</span></label>
</div>
<div id="static-fields" <?= $p['network_mode'] === 'static' ? '' : 'hidden' ?>>
  <div class="grid">
    <label>
      IP <span class="required-marker">*</span>
      <input type="text" name="static_ip" value="<?= View::e($p['static_ip'] ?? '') ?>" placeholder="192.0.2.50" data-required-if-static>
    </label>
    <label>
      Préfixe (CIDR) <span class="required-marker">*</span>
      <select name="static_cidr">
        <?php foreach ($cidrChoices as $cidr => $libelle): ?>
          <option value="<?= $cidr ?>" <?= $cidr === ((int)($p['static_cidr'] ?? 24) ?: 24) ? 'selected' : '' ?>><?= View::e($libelle) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="grid">
    <label>
      Passerelle <span class="required-marker">*</span>
      <input type="text" name="static_gateway" value="<?= View::e($p['static_gateway'] ?? '') ?>" placeholder="192.0.2.1" data-required-if-static>
    </label>
    <div>
      <label>Serveurs DNS <span class="required-marker">*</span></label>
      <div id="dns-list" data-default-primary="<?= View::e($_defDns[0] ?? '192.0.2.53') ?>" data-default-secondary="<?= View::e($_defDns[1] ?? '192.0.2.54') ?>">
        <?php foreach ($dnsList as $idx => $dns):
          if ($idx === 0) {
            $placeholder = $_defDns[0] ?? '192.0.2.53';
            $required    = ' required';
            $btnAttrs    = ' disabled aria-disabled="true" title="Au moins 1 serveur DNS valide est requis" data-dns-role="primary"';
          } elseif ($idx === 1) {
            $placeholder = $_defDns[1] ?? '192.0.2.54';
            $required    = '';
            $btnAttrs    = ' data-dns-warn-redundancy data-dns-role="secondary" aria-label="Supprimer ce DNS"';
          } else {
            $placeholder = '192.0.2.53';
            $required    = '';
            $btnAttrs    = ' aria-label="Supprimer ce DNS"';
          }
        ?>
          <div class="dns-row">
            <input type="text" name="dns[]" data-a11y-label="Serveur DNS" aria-label="Serveur DNS <?= $idx + 1 ?> · interface PXE" value="<?= View::e($dns) ?>" placeholder="<?= View::e($placeholder) ?>"<?= $required ?>>
            <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer le serveur DNS"<?= $btnAttrs ?>><?= View::icon('trash') ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="dns-add" class="btn btn-secondary">+ Ajouter un DNS</button>
    </div>
  </div>
</div>

<template id="dns-row-tpl">
  <div class="dns-row">
    <input type="text" name="dns[]" data-a11y-label="Serveur DNS" aria-label="Serveur DNS supplémentaire · interface PXE" placeholder="192.0.2.53">
    <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer le serveur DNS" aria-label="Supprimer ce DNS"><?= View::icon('trash') ?></button>
  </div>
</template>

<script>
// Legacy single-NIC : sans cet écouteur, le bloc #static-fields reste sur sa
// valeur initiale (rendue côté PHP avec $p['network_mode']) même quand le radio
// network_mode change — y compris quand install-wizard.js applyConfig() coche
// programmatiquement le radio après chargement d'un preset (setRadioValue
// dispatch un Event 'change' bubbles). Pattern déjà présent pour le multi-NIC
// plus bas dans ce fichier.
(function () {
  var staticBlock = document.getElementById('static-fields');
  if (!staticBlock) return;
  document.querySelectorAll('input[type="radio"][name="network_mode"]')
    .forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (!radio.checked) return;
        staticBlock.hidden = (radio.value !== 'static');
      });
    });
})();
</script>

<?php
// ===== Mode MULTI-NIC =====
else:
    // Plan pré-existant indexé par MAC pour pré-remplir les cartes inventaire.
    // Si pas de plan ou plan vide : par défaut, chaque NIC découverte démarre en DHCP,
    // et la NIC qui a effectué le boot PXE est désignée primaire.
    $existingByMac = [];
    $existingPlanInterfaces = $networkPlan['interfaces'] ?? [];
    foreach ($existingPlanInterfaces as $iface) {
        if (isset($iface['match']['mac'])) {
            $existingByMac[strtolower((string)$iface['match']['mac'])] = $iface;
        }
    }
    $primaryIdx = HardwareContext::primaryNicIndex($nicsInventory, $networkPlan ?? [], $bootMac, $preferBootNic);
?>
<div class="network-multi-nic" data-nics-count="<?= count($nicsInventory) ?>" data-boot-mac="<?= View::e($bootMac) ?>">
  <p class="muted" style="margin-bottom:.75rem;">
    <?= count($nicsInventory) ?> interface(s) détectée(s) dans cet inventaire.
    La carte primaire porte la route par défaut. Chaque carte peut utiliser DHCP, une adresse statique ou rester désactivée.
  </p>

  <?php foreach (array_values($nicsInventory) as $i => $nic):
    $mac      = strtolower(trim((string)($nic['mac'] ?? '')));
    $name     = (string)($nic['name'] ?? '');
    $speed    = (int)($nic['speed_mbps'] ?? 0);
    $type     = (string)($nic['type'] ?? '');
    $ipv4Hint = (string)($nic['ipv4'] ?? '');
    // Récupération état existant (par MAC) ou défauts safe
    $existing = $existingByMac[$mac] ?? null;
    if ($existing === null) {
        foreach ($existingPlanInterfaces as $candidateIndex => $candidate) {
            if (!empty($candidate['match']['mac'])) continue;
            $candidateTarget = ($candidate['match_by'] ?? 'primary') === 'primary'
                ? $primaryIdx : (int)($candidate['index'] ?? $candidateIndex);
            if ($candidateTarget === $i) { $existing = $candidate; break; }
        }
    }
    if ($existing === null && empty($existingPlanInterfaces) && $i === $primaryIdx) {
        $existing = ['mode' => $p['network_mode'] ?? 'dhcp', 'static' => [
            'ip' => $p['static_ip'] ?? '', 'cidr' => $p['static_cidr'] ?? 24,
            'gateway' => $p['static_gateway'] ?? '', 'dns' => $dnsList,
        ]];
    }
    $mode     = $existing['mode'] ?? 'dhcp';
    $st       = $existing['static'] ?? null;
    $stIp     = (string)($st['ip'] ?? '');
    $stCidr   = (int)($st['cidr'] ?? 24) ?: 24;
    $stGw     = (string)($st['gateway'] ?? '');
    $stDns = is_array($st['dns'] ?? null) ? $st['dns'] : ($existing === null ? $_defDns : []);
    while (count($stDns) < 2) $stDns[] = '';
    $vlanId   = isset($existing['vlan']['id']) ? (int)$existing['vlan']['id'] : null;
    $bond     = is_array($existing['bond'] ?? null) ? $existing['bond'] : null;
    $isPrimary = ($i === $primaryIdx);
    $cardId    = "nic-card-$i";
    $a11yNic = ($name ?: "Interface " . ($i + 1)) . ($mac !== "" ? " · " . $mac : "");
  ?>
    <details class="nic-disclosure" data-nic-disclosure <?= $isPrimary ? 'open' : '' ?>>
      <summary data-network-summary><?= View::e($a11yNic) ?> · <?= $isPrimary ? 'Primaire · ' : '' ?><?= View::e(match ($mode) { 'static' => 'Statique · ' . $stIp . '/' . $stCidr, 'disabled' => 'Désactivée', default => 'DHCP' }) ?><?= $vlanId !== null ? ' · VLAN ' . $vlanId : '' ?><?= !empty($bond['name']) ? ' · Agrégation ' . View::e($bond['name']) : '' ?></summary>
    <article class="nic-card" id="<?= $cardId ?>" data-a11y-network="<?= View::e($a11yNic) ?>" style="margin-bottom:.75rem;">
      <header style="display:flex;align-items:center;gap:.75rem;justify-content:space-between;flex-wrap:wrap;">
        <div>
          <strong><?= View::e($name ?: "NIC #" . ($i + 1)) ?></strong>
          <span class="muted" style="margin-left:.5rem;">
            <?= View::e($mac) ?>
            <?php if ($speed > 0): ?>· <?= $speed ?> Mbps<?php endif; ?>
            <?php if ($type !== ''): ?>· <?= View::e($type) ?><?php endif; ?>
            <?php if ($ipv4Hint !== ''): ?>· actuellement <?= View::e($ipv4Hint) ?><?php endif; ?>
          </span>
        </div>
        <label style="margin:0;">
          <input type="radio" name="network[primary]" value="<?= $i ?>" <?= $isPrimary ? 'checked' : '' ?>
                 data-nic-primary-radio data-a11y-label="Interface primaire" aria-label="Interface primaire · <?= View::e($a11yNic) ?>">
          Interface primaire
        </label>
      </header>

      <!-- Identité inventaire — résolution par MAC -->
      <input type="hidden" name="network[interfaces][<?= $i ?>][match_by]" value="mac">
      <input type="hidden" name="network[interfaces][<?= $i ?>][index]"    value="<?= $i ?>">
      <input type="hidden" name="network[interfaces][<?= $i ?>][match][mac]" value="<?= View::e($mac) ?>">

      <div role="group" aria-label="Mode réseau · <?= View::e($a11yNic) ?>" style="margin:.5rem 0;">
        <label><input type="radio" name="network[interfaces][<?= $i ?>][mode]" value="dhcp" <?= $mode === 'dhcp' ? 'checked' : '' ?> data-nic-mode-radio> DHCP</label>
        <label><input type="radio" name="network[interfaces][<?= $i ?>][mode]" value="static" <?= $mode === 'static' ? 'checked' : '' ?> data-nic-mode-radio> Statique</label>
        <label><input type="radio" name="network[interfaces][<?= $i ?>][mode]" value="disabled" <?= $mode === 'disabled' ? 'checked' : '' ?> data-nic-mode-radio> Désactivée</label>
      </div>

      <div class="nic-static-fields" data-nic-static-block <?= $mode === 'static' ? '' : 'hidden' ?>>
        <div class="grid">
          <label>
            IP <span class="required-marker">*</span>
            <input type="text" name="network[interfaces][<?= $i ?>][static][ip]" data-a11y-label="IP" aria-label="IP · <?= View::e($a11yNic) ?>"
                   value="<?= View::e($stIp) ?>" placeholder="192.0.2.50">
          </label>
          <label>
            Préfixe (CIDR) <span class="required-marker">*</span>
            <select name="network[interfaces][<?= $i ?>][static][cidr]" data-a11y-label="Préfixe (CIDR)" aria-label="Préfixe (CIDR) · <?= View::e($a11yNic) ?>">
              <?php foreach ($cidrChoices as $cidr => $libelle): ?>
                <option value="<?= $cidr ?>" <?= $cidr === $stCidr ? 'selected' : '' ?>><?= View::e($libelle) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="grid">
          <label>
            Passerelle <span class="required-marker">*</span>
            <input type="text" name="network[interfaces][<?= $i ?>][static][gateway]" data-a11y-label="Passerelle" aria-label="Passerelle · <?= View::e($a11yNic) ?>"
                   value="<?= View::e($stGw) ?>" placeholder="192.0.2.1">
          </label>
          <div>
            <label>Serveurs DNS <span class="required-marker">*</span></label>
            <div data-nic-dns-list data-default-primary="<?= View::e($_defDns[0] ?? '192.0.2.53') ?>" data-default-secondary="<?= View::e($_defDns[1] ?? '192.0.2.54') ?>">
              <?php foreach ($stDns as $dnsIdx => $dnsVal): ?>
                <div class="dns-row">
                  <input type="text" name="network[interfaces][<?= $i ?>][static][dns][]" data-a11y-label="Serveur DNS" aria-label="Serveur DNS <?= $dnsIdx + 1 ?> · <?= View::e($a11yNic) ?>"
                         value="<?= View::e($dnsVal) ?>" placeholder="<?= View::e($_defDns[$dnsIdx] ?? '192.0.2.53') ?>">
                  <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer le serveur DNS" aria-label="Supprimer ce DNS"><?= View::icon('trash') ?></button>
                </div>
              <?php endforeach; ?>
            </div>
            <template data-nic-dns-tpl>
              <div class="dns-row">
                <input type="text" name="network[interfaces][<?= $i ?>][static][dns][]" data-a11y-label="Serveur DNS" aria-label="Serveur DNS supplémentaire · <?= View::e($a11yNic) ?>" placeholder="192.0.2.53">
                <button type="button" class="btn-icon-only btn-remove" data-a11y-label="Supprimer le serveur DNS" aria-label="Supprimer ce DNS"><?= View::icon('trash') ?></button>
              </div>
            </template>
            <button type="button" class="btn btn-secondary btn-small" data-nic-dns-add data-a11y-label="Ajouter un DNS" aria-label="Ajouter un DNS · <?= View::e($a11yNic) ?>">+ Ajouter un DNS</button>
          </div>
        </div>
      </div>

      <details style="margin-top:.5rem;">
        <summary class="muted">Avancé · VLAN et agrégation<?= $vlanId !== null ? ' · VLAN ' . (int)$vlanId : '' ?><?= !empty($bond['name']) ? ' · ' . View::e($bond['name']) : '' ?></summary>
        <div class="grid" style="margin-top:.5rem;">
          <label>
            VLAN ID <span class="muted">(1-4094, vide = sans VLAN)</span>
            <input type="number" min="1" max="4094"
                   name="network[interfaces][<?= $i ?>][vlan][id]" data-a11y-label="VLAN ID" aria-label="VLAN ID · <?= View::e($a11yNic) ?>"
                   value="<?= $vlanId !== null ? (int)$vlanId : '' ?>">
          </label>
          <label>
            Rôle bonding
            <select name="network[interfaces][<?= $i ?>][bond][role]" data-a11y-label="Rôle bonding" aria-label="Rôle bonding · <?= View::e($a11yNic) ?>">
              <option value="" <?= empty($bond['role']) ? 'selected' : '' ?>>— aucun —</option>
              <option value="master" <?= ($bond['role'] ?? '') === 'master' ? 'selected' : '' ?>>Master</option>
              <option value="slave"  <?= ($bond['role'] ?? '') === 'slave'  ? 'selected' : '' ?>>Slave</option>
            </select>
          </label>
        </div>
        <div class="grid">
          <label>
            Nom du bond
            <input type="text" name="network[interfaces][<?= $i ?>][bond][name]" data-a11y-label="Nom du bond" aria-label="Nom du bond · <?= View::e($a11yNic) ?>"
                   value="<?= View::e($bond['name'] ?? '') ?>" placeholder="bond0">
          </label>
          <label>
            Mode bond
            <input type="text" name="network[interfaces][<?= $i ?>][bond][mode]" data-a11y-label="Mode bond" aria-label="Mode bond · <?= View::e($a11yNic) ?>"
                   value="<?= View::e($bond['mode'] ?? 'active-backup') ?>" placeholder="active-backup">
          </label>
        </div>
      </details>
    </article>
    </details>
  <?php endforeach; ?>
</div>

<script>
(function () {
  // Toggle show/hide du bloc static par carte NIC selon le mode choisi.
  // Léger, scope par carte : pas besoin de bibliothèque.
  document.querySelectorAll('.nic-card').forEach(function (card) {
    const staticBlock = card.querySelector('[data-nic-static-block]');
    if (!staticBlock) return;
    card.querySelectorAll('[data-nic-mode-radio]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (!radio.checked) return;
        staticBlock.hidden = (radio.value !== 'static');
      });
    });
  });
})();
</script>

<?php endif; ?>
