<?php
use Admin\View;
$step      = $step ?? 1;
$stepLabel = 'Réseau';
$interfaces = $interfaces ?? [];
$current = $current ?? [];
$error = $error ?? null;
$settingsFlow = $settingsFlow ?? false;
$errorMsg = match ($error) {
    'missing'       => 'Choisissez une carte réseau et une adresse.',
    'missing_subnet' => 'Indiquez le sous-réseau des clients PXE autorisés.',
    'invalid_subnet' => 'Le sous-réseau des clients doit être une plage IPv4 valide, par exemple 192.0.2.0/24.',
    'subnet_mismatch' => 'L’adresse du serveur ne correspond pas au sous-réseau indiqué. Corrigez-le ou confirmez la configuration manuelle.',
    'invalid_ip'    => 'L\'adresse doit être au format valide (par exemple 192.0.2.10).',
    'missing_proxy' => 'Le proxy DHCP nécessite d\'indiquer la plage du réseau local (par exemple 192.0.2.0/24).',
    'invalid_interface' => 'Nom de carte réseau invalide.',
    'unknown_interface' => 'Cette carte n’est pas détectée. Vérifiez son nom ou confirmez la configuration manuelle.',
    'interface_ip_mismatch' => 'Cette adresse n’est pas détectée sur la carte choisie. Utilisez la détection ou confirmez la configuration manuelle.',
    'network_mismatch' => 'L’adresse du serveur n’appartient pas à cette plage. Corrigez-la ou confirmez la configuration manuelle.',
    'save_failed' => 'La configuration n’a pas été enregistrée. Vérifiez la disponibilité de la base puis réessayez.',
    'confirm_network' => 'Vérifiez et enregistrez la configuration réseau avant de continuer.',
    'invalid_cidr'  => 'Plage du réseau local invalide. Format attendu : par exemple 192.0.2.0/24.',
    null            => null,
    default         => 'La configuration n\'a pas pu être validée.',
};
$title = 'Réseau';
$contentRender = function () use ($interfaces, $current, $errorMsg, $step, $stepLabel, $settingsFlow) {
    if (!$settingsFlow) include __DIR__ . '/_progress.php';
    // Map JS : iface_name → { ip, network, netmask, wireless, state }.
    // Permet au navigateur de pré-remplir les champs IP/réseau/netmask et
    // d'afficher les warnings (Wi-Fi non recommandé, ETH down détectée)
    // sans round-trip serveur.
    $jsIfaceMap = [];
    foreach ($interfaces as $iface) {
        $cidrAddr = $iface['ipv4'][0] ?? null;
        $ip = ''; $cidr = '';
        if ($cidrAddr) {
            [$ip, $plen] = array_pad(explode('/', $cidrAddr, 2), 2, '24');
            $plen = max(0, min(32, (int)$plen));
            $maskLong = $plen === 0 ? 0 : (~((1 << (32 - $plen)) - 1)) & 0xFFFFFFFF;
            $network  = long2ip(ip2long($ip) & $maskLong);
            $cidr     = $network . '/' . $plen;
        }
        $jsIfaceMap[$iface['name']] = [
            'ip'       => $ip,
            'cidr'     => $cidr,
            'wireless' => (bool)$iface['wireless'],
            'state'    => $iface['state'],
        ];
    }
?>
  <header><h2 style="margin:0;">Configuration réseau</h2></header>

  <?php if ($errorMsg): ?>
    <article class="flash flash-error" id="network-error" tabindex="-1" role="alert" data-persistent><?= View::e($errorMsg) ?></article>
  <?php endif; ?>

  <p class="muted">
    Le serveur communiquera avec les machines à installer par la carte réseau
    choisie. Les machines le contacteront à l'adresse indiquée.
  </p>

  <form method="post" action="<?= $settingsFlow ? '/init/network?from=settings' : '/init/network' ?>" id="network-form">
    <label>
      Carte réseau (ou connexion) à utiliser
      <?php if ($interfaces === []): ?>
      <input name="pxe_interface" id="pxe-interface" required value="<?= View::e($current['pxe_interface'] ?? '') ?>" placeholder="enp1s0">
      <?php else: ?>
      <select name="pxe_interface" id="pxe-interface" required>
        <?php foreach ($interfaces as $iface):
          $sel = $iface['name'] === ($current['pxe_interface'] ?? '') ? ' selected' : '';
          // <option> ne supporte pas le HTML — caractères Unicode neutres.
          $stateBadge = $iface['state'] === 'up' ? '●' : '○';
          $type = $iface['wireless'] ? 'Wi-Fi' : 'Filaire';
          $ips = !empty($iface['ipv4']) ? ' — ' . implode(', ', $iface['ipv4']) : '';
        ?>
          <option value="<?= View::e($iface['name']) ?>"<?= $sel ?>>
            <?= $stateBadge ?> <?= View::e($iface['name']) ?> · <?= $type ?> · <?= View::e($iface['mac']) ?><?= View::e($ips) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <small class="muted">
        Cartes réseau détectées automatiquement. Les connexions internes au système sont masquées.
      </small>
    </label>

    <article id="iface-warning" class="flash flash-warning" data-persistent style="display:none;margin-top:.5rem;">
      <!-- Mis à jour côté JS selon l'interface sélectionnée -->
    </article>

    <label>
      Adresse du serveur (celle que les machines utiliseront pour le contacter)
      <input type="text" name="pxe_ip" id="pxe-ip" required
             pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$"
             placeholder="192.0.2.10"
             value="<?= View::e($current['pxe_ip'] ?? '') ?>">
      <small class="muted">
        Pré-remplie avec l'adresse de la carte réseau choisie. Modifiez-la seulement si les machines doivent passer par une autre adresse (configuration avancée).
      </small>
    </label>

    <label>
      Sous-réseau des clients PXE autorisés
      <input type="text" name="pxe_subnet" id="pxe-subnet" required
             pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$"
             placeholder="192.0.2.0/24"
             value="<?= View::e($current['pxe_subnet'] ?? '') ?>">
      <small class="muted">Les menus, configurations d’installation et signaux PXE sont limités à cette plage. Vérifiez la suggestion avant de continuer.</small>
    </label>

    <p id="network-detection" class="muted" role="status"></p>
    <button type="button" id="use-interface-detection" class="btn btn-secondary btn-small">Utiliser les valeurs détectées</button>
    <label class="mt-4"><input type="checkbox" id="network-override" name="network_override" <?= !empty($current['network_override']) ? 'checked' : '' ?>>
      Configuration manuelle : je confirme l’interface, l’adresse et la plage, y compris lorsqu’elles diffèrent de la détection.
    </label>
    <fieldset>
      <legend>Proxy DHCP PXE</legend>
      <label for="dhcp-proxy-enabled">
        <input type="checkbox" name="dhcp_proxy_enabled" id="dhcp-proxy-enabled" role="switch"
               <?= ($current['dhcp_proxy_enabled'] ?? false) ? 'checked' : '' ?>>
        Activer le proxy DHCP PXE sur le réseau choisi
      </label>
      <small class="muted" id="dhcp-proxy-help">
        <!-- Mis à jour côté JS selon l'état de la checkbox -->
      </small>
    </fieldset>

    <fieldset id="proxy-fields" style="display:none;">
      <legend>Plage du réseau local (détectée automatiquement)</legend>
      <label>
        Plage du réseau local
        <input type="text" name="dhcp_proxy_cidr" id="dhcp-proxy-cidr"
               pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$"
               placeholder="192.0.2.0/24"
               value="<?= View::e($current['dhcp_proxy_cidr'] ?? '') ?>">
        <small class="muted">
          Calculée à partir de la carte réseau choisie. Modifiez-la seulement si
          vos machines à installer sont sur un réseau différent de celui du serveur (configuration avancée).
        </small>
      </label>
    </fieldset>

    <fieldset>
      <legend>Accès au portail d'administration</legend>
      <label for="https-enabled">
        <input type="checkbox" name="https_enabled" id="https-enabled" role="switch"
               <?= ($current['https_enabled'] ?? true) ? 'checked' : '' ?>>
        Sécuriser l'accès au portail (connexion chiffrée, recommandé)
      </label>
      <small class="muted">
        Si activé : le portail s'ouvre via une connexion sécurisée, à l'adresse
        port HTTPS configuré (<?= (int)(getenv('ADMIN_PORT') ?: 8443) ?>), après application par le worker.
        Un certificat auto-signé doit être vérifié avant de l’accepter.
        Le port HTTP configuré (<?= (int)(getenv('ADMIN_HTTP_PORT') ?: 8080) ?>) redirigera alors vers HTTPS.<br>
        Si désactivé : le portail local sert HTTP ; le chiffrement peut être assuré par un reverse proxy externe.
      </small>
    </fieldset>

    <div class="wizard-actions">
      <a href="<?= $settingsFlow ? '/settings' : '/init/admin' ?>" class="btn btn-secondary"><span class="btn-arrow">←</span> <?= $settingsFlow ? 'Retour aux paramètres' : 'Retour' ?></a>
      <button type="submit" class="btn btn-primary"><?= $settingsFlow ? 'Enregistrer' : 'Suivant' ?> <span class="btn-arrow">→</span></button>
    </div>
  </form>

  <script>
  (function(){
    const ifaceMap  = <?= json_encode($jsIfaceMap, JSON_UNESCAPED_SLASHES) ?>;
    const selIface  = document.getElementById('pxe-interface');
    const chkProxy  = document.getElementById('dhcp-proxy-enabled');
    const fs        = document.getElementById('proxy-fields');
    const ipInput   = document.getElementById('pxe-ip');
    const cidrInput = document.getElementById('dhcp-proxy-cidr');
    const subnetInput = document.getElementById('pxe-subnet');
    const helpEl    = document.getElementById('dhcp-proxy-help');
    const warnEl    = document.getElementById('iface-warning');
    const form      = document.getElementById('network-form');

    // Pour comparer la sélection courante aux autres ETH dispo : on a besoin
    // de la liste des Ethernet UP/down présents.
    const allIfaces = Object.entries(ifaceMap).map(([name, cfg]) => ({ name, ...cfg }));
    const ethernetIfs = allIfaces.filter(i => !i.wireless);
    const ethUp       = ethernetIfs.filter(i => i.state === 'up').map(i => i.name);
    const ethDown     = ethernetIfs.filter(i => i.state !== 'up').map(i => i.name);

    const helpTexts = {
      on:
        'Le serveur répondra aux demandes de démarrage PXE en complément du DHCP existant. ' +
        'Activez-le seulement après avoir vérifié que ce mode convient à votre réseau.',
      off:
        'Le proxy DHCP est désactivé. Configurez votre DHCP existant pour diriger les machines ' +
        'vers ce serveur si vous souhaitez démarrer par le réseau.',
    };

    function updateIfaceWarning(){
      const cfg = ifaceMap[selIface.value];
      if (!cfg) { warnEl.style.display = 'none'; warnEl.innerHTML = ''; return; }

      const messages = [];
      if (cfg.wireless) {
        messages.push(
          '<strong>Attention : le Wi-Fi est déconseillé.</strong> ' +
          'Le démarrage par le réseau est bien plus fiable sur une connexion filaire (câble). ' +
          'En Wi-Fi, l\'opération échoue souvent et reste lente. Préférez une carte réseau filaire.'
        );
        if (ethUp.length > 0) {
          messages.push(
            'Carte(s) réseau filaire(s) active(s) disponible(s) : <code>' +
            ethUp.join('</code>, <code>') + '</code>.'
          );
        } else if (ethDown.length > 0) {
          messages.push(
            'Une carte réseau filaire est détectée mais semble inactive : <code>' +
            ethDown.join('</code>, <code>') + '</code>. Branchez le câble pour l\'utiliser.'
          );
        }
      } else if (cfg.state !== 'up') {
        messages.push(
          '<strong>Attention : carte réseau inactive.</strong> ' +
          'La carte réseau choisie est inactive (câble débranché ?). ' +
          'Le démarrage par le réseau ne fonctionnera pas tant qu\'elle reste inactive.'
        );
      }

      if (messages.length === 0) {
        warnEl.style.display = 'none';
        warnEl.innerHTML = '';
      } else {
        warnEl.style.display = '';
        warnEl.innerHTML = messages.join('<br><br>');
      }
    }

    const manual = document.getElementById('network-override');
    const detected = document.getElementById('network-detection');
    let ipEdited = false, cidrEdited = false, subnetEdited = false;
    ipInput.addEventListener('input', () => { ipEdited = true; });
    cidrInput.addEventListener('input', () => { cidrEdited = true; });
    subnetInput.addEventListener('input', () => { subnetEdited = true; });
    function applyIfaceDefaults(force = false, changed = false){
      const cfg = ifaceMap[selIface.value] || {ip:'', cidr:''};
      detected.textContent = cfg.ip ? 'Détecté sur ' + selIface.value + ' : ' + cfg.ip + ' · ' + cfg.cidr : 'Aucune adresse détectée : une configuration manuelle doit être confirmée.';
      if (force || (changed && !ipEdited && !manual.checked)) ipInput.value = cfg.ip || '';
      if (force || (changed && !cidrEdited && !manual.checked)) cidrInput.value = cfg.cidr || '';
      if (force || (changed && !subnetEdited && !manual.checked)) subnetInput.value = cfg.cidr || '';
      if (changed && (ipEdited || cidrEdited || subnetEdited || manual.checked)) detected.textContent += ' Votre saisie manuelle est conservée ; vérifiez sa cohérence avec cette carte.';
      if (force) { ipEdited = false; cidrEdited = false; subnetEdited = false; manual.checked = false; }
    }
    document.getElementById('use-interface-detection').addEventListener('click', () => applyIfaceDefaults(true));

    function updateProxy(){
      const on = chkProxy.checked;
      fs.style.display = on ? '' : 'none';
      helpEl.textContent = helpTexts[on ? 'on' : 'off'];
    }

    selIface.addEventListener('change', () => {
      applyIfaceDefaults(false, true);
      updateIfaceWarning();
    });
    chkProxy.addEventListener('change', updateProxy);

    // Confirmation au submit si Wi-Fi sélectionné — évite l'erreur muette d'un
    // utilisateur pressé qui valide sans lire le warning inline.
    form.addEventListener('submit', (e) => {
      const cfg = ifaceMap[selIface.value];
      if (cfg && cfg.wireless) {
        const ok = window.confirm(
          'Vous avez choisi une connexion Wi-Fi (' + selIface.value + ').\n\n' +
          'Le démarrage par le réseau est instable en Wi-Fi : il échoue souvent ' +
          'et reste lent. Une connexion filaire (câble) est fortement recommandée.\n\n' +
          'Continuer quand même ?'
        );
        if (!ok) {
          e.preventDefault();
          return;
        }
      }
    });

    if (document.getElementById('network-error')) document.getElementById('network-error').focus();
    applyIfaceDefaults();
    updateIfaceWarning();
    updateProxy();
  })();
  </script>
<?php };
include __DIR__ . '/_layout.php';
