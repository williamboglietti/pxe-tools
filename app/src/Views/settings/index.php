<?php
use Admin\View;
use Admin\Models\Setting;
$groups = [
    ['title' => 'Préparer les déploiements', 'links' => [
        ['/settings/images', 'Images système', 'Catalogue, téléchargements et fichiers disponibles'],
        ['/settings/ansible', 'Automatisation Ansible', Setting::get(Setting::KEY_ANSIBLE_ENABLED, 'off') === 'on' ? 'Activée pour les prochaines installations' : 'Optionnelle · désactivée'],
    ]],
    ['title' => 'Accès et réseau', 'links' => [
        ['/init/network?from=settings', 'Réseau PXE', 'Interface, adresse, sous-réseau autorisé et proxy DHCP'],
        ['/settings/https', 'Accès HTTPS', 'Configuration du portail, ports et suivi de l’application'],
    ]],
    ['title' => 'Boot et intégrations', 'links' => [
        ['/settings/ipxe', 'Démarrage iPXE', 'Chargement HTTP des systèmes · option avancée'],
    ]],
];
?>
<header class="mb-6">
  <h2>Paramètres de l’appliance</h2>
  <p class="muted">Réglages partagés des prochains déploiements, accès au portail et maintenance du serveur PXE.</p>
</header>
<div class="grid gap-4 md:grid-cols-3 mb-6">
<?php foreach ($groups as $group): ?>
<section class="card">
  <h3><?= View::e($group['title']) ?></h3>
  <?php foreach ($group['links'] as [$href, $label, $description]): ?>
  <p class="mb-4"><a href="<?= View::e($href) ?>"><strong><?= View::e($label) ?> →</strong></a><br><span class="muted text-small"><?= View::e($description) ?></span></p>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
</div>
<?php View::partial('settings/_application_status', ['jobs' => $jobs ?? []]); ?>
<details class="card mb-6">
  <summary><strong>Valeurs par défaut des déploiements</strong> — DNS et découverte</summary>
<div class="card mb-6">
  <form method="post" action="/settings">
    <?php foreach ($settings as $key => $meta): ?>
      <div class="field mb-4">
        <label class="field-label" for="setting-<?= View::e($key) ?>"><strong><?= View::e($meta['label']) ?></strong></label>
        <p class="muted text-small"><?= View::e($meta['help']) ?></p>
        <select id="setting-<?= View::e($key) ?>" name="<?= View::e($key) ?>" class="select">
          <?php foreach ($meta['options'] as $val => $lbl): ?>
            <option value="<?= View::e($val) ?>"<?= $meta['current'] === $val ? ' selected' : '' ?>>
              <?= View::e($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="field-hint">Valeur par défaut : <code><?= View::e($meta['default']) ?></code></small>
      </div>
    <?php endforeach; ?>
    <div class="actions mt-4 justify-end">
      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </div>
  </form>
</div>

<?php
$defDns  = \Admin\Models\Preset::defaultDns();
$dnsRows = $defDns;
while (count($dnsRows) < 2) $dnsRows[] = '';   // 2 lignes minimum à l'affichage
$dnsRowHtml = function ($val): string {
    ob_start(); ?>
  <div class="repeat-row is-single default-dns-row">
    <input type="text" name="default_dns[]" aria-label="Serveur DNS IPv4" value="<?= View::e((string)$val) ?>" placeholder="192.0.2.53">
    <button type="button" class="btn-icon-only btn-remove" aria-label="Supprimer ce DNS"><?= View::icon('trash') ?></button>
  </div>
<?php return ob_get_clean();
};
?>
<div class="card mb-6">
  <h3 style="margin:0 0 .5rem 0; display:inline-flex; align-items:center; gap:.5rem;"><?= View::icon('globe') ?> DNS par défaut</h3>
  <p class="muted text-small">
    Serveurs DNS proposés par défaut lors d'une nouvelle installation de machine ou
    d'un nouveau modèle (modifiables ensuite par configuration). Aucun serveur n'est proposé
    tant que vous n'en avez pas enregistré ; deux serveurs peuvent améliorer la redondance.
  </p>
  <form method="post" action="/settings/default-dns">
    <div id="default-dns-rows">
      <?php foreach ($dnsRows as $val) echo $dnsRowHtml($val); ?>
    </div>
    <template id="default-dns-row-tpl"><?= $dnsRowHtml('') ?></template>
    <button type="button" id="default-dns-add" class="btn btn-secondary btn-small">+ Ajouter un DNS</button>
    <div class="actions mt-4 justify-end">
      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </div>
  </form>
</div>
<script>
(function () {
  // La liste est facultative et peut être complètement vidée.
  var c = document.getElementById('default-dns-rows');
  var addBtn = document.getElementById('default-dns-add');
  var tpl = document.getElementById('default-dns-row-tpl');
  if (!c || !addBtn || !tpl) return;
  function refresh() {
    var rows = c.querySelectorAll('.default-dns-row');
    rows.forEach(function (r) {
      var btn = r.querySelector('.btn-remove');
      if (!btn) return;
      btn.disabled = false;
      btn.removeAttribute('aria-disabled');
      btn.removeAttribute('title');
    });
  }
  addBtn.addEventListener('click', function () {
    var tmp = document.createElement('div');
    tmp.innerHTML = tpl.innerHTML.trim();
    if (tmp.firstElementChild) c.appendChild(tmp.firstElementChild);
    refresh();
  });
  c.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-remove');
    if (!btn || btn.disabled) return;
    var row = btn.closest('.default-dns-row');
    if (row) row.remove();
    refresh();
  });
  refresh();
})();
</script>

</details>
<div class="card mb-6">
  <h3 style="margin:0 0 .5rem 0; display:inline-flex; align-items:center; gap:.5rem;"><?= View::icon('settings') ?> Appliquer la configuration</h3>
  <p class="muted text-small">
    Régénère les templates PXE (preseed.php, grub.cfg, pxelinux, dnsmasq.conf)
    à partir des paramètres courants en base, puis relance dnsmasq pour qu'il
    prenne en compte les nouveaux réglages réseau.
  </p>
  <p class="muted text-small">
    À demander après modification de l'IP serveur PXE, du mode DHCP proxy,
    de la plage CIDR, du mot de passe root global, ou des paramètres Ansible.
    Le résultat de chaque traitement reste disponible dans Activité.
  </p>
  <form method="post" action="/settings/apply-config">
    <div class="actions mt-4 justify-end">
      <button type="submit" class="btn btn-primary">Demander l’application</button>
    </div>
  </form>
</div>

<hr style="margin: 2rem 0;">

<details class="card mb-6">
  <summary><strong>Maintenance avancée</strong> — Secure Boot et reconstruction GRUB</summary>
  <p class="muted text-small">
    Actions destructives sur la chaîne de boot UEFI Secure Boot. À utiliser uniquement
    après lecture de la doc — voir README section "MOK regeneration".
  </p>

  <div class="card mb-6">
    <h4 style="margin:0 0 .5rem 0;">Régénérer la clé MOK</h4>
    <p class="muted text-small">
      Génère un nouveau keypair RSA 2048-bit et reconstruit le GRUB EFI signé.
      <strong>Toutes les machines déjà enrôlées en Secure Boot devront ré-enrôler la nouvelle clé</strong>
      via <code>mokutil --import http://&lt;IP&gt;/MOK.der</code> avant leur prochain PXE boot.
      L'ancienne paire de clés est conservée dans un sous-répertoire <code>backup-&lt;timestamp&gt;</code> du répertoire MOK de l'instance.
    </p>
    <form method="post" action="/settings/regenerate-mok"
          onsubmit="return confirm('Régénérer la clé MOK ? Les machines en Secure Boot devront ré-enrôler la nouvelle clé.');">
      <div class="actions mt-4 justify-end">
        <button type="submit" class="btn btn-danger"><?= View::icon('warning') ?> Régénérer la clé MOK</button>
      </div>
    </form>
  </div>

  <div class="card mb-6">
    <h4 style="margin:0 0 .5rem 0;">Reconstruire le GRUB EFI</h4>
    <p class="muted text-small">
      Reconstruit <code>grubx64.efi</code> avec la MOK existante et l'IP serveur PXE courante.
      Utile si l'IP du serveur PXE a changé (l'IP est embedded dans le binaire EFI).
      Pas d'impact sur les machines déjà enrôlées en SB.
    </p>
    <form method="post" action="/settings/rebuild-grub"
          onsubmit="return confirm('Reconstruire le GRUB EFI ?');">
      <div class="actions mt-4 justify-end">
        <button type="submit" class="btn btn-primary">Reconstruire le GRUB EFI</button>
      </div>
    </form>
  </div>
</details>

<hr style="margin: 2rem 0;">



<details class="danger-zone mb-6">
  <summary><strong>Réinitialisation</strong> — actions irréversibles</summary>
  <h3 class="danger-zone__title">
    <?= View::icon('warning') ?> Zone de danger
  </h3>

  <p class="danger-zone__desc muted text-small">
    <strong>Réinitialiser les machines</strong> — supprime les machines découvertes,
    installations, wipes et fichiers de boot per-MAC. Modèles, paramètres et
    comptes admin <strong>préservés</strong>.
  </p>
  <p class="danger-zone__desc muted text-small">
    <strong>Réinitialisation complète</strong> — remet PXE Tools à l'état d'install
    neuve : efface <strong>tout</strong> (base, comptes, modèles, images netboot
    ~1,5&nbsp;Go, bootloaders, MOK) et relance le wizard d'installation.
  </p>

  <div class="danger-zone__actions">
    <button type="button" id="open-reset-modal" class="btn btn-danger">
      Réinitialiser les machines
    </button>
    <button type="button" id="open-factory-modal" class="btn btn-danger">
      Réinitialisation complète
    </button>
  </div>
</details>

<dialog id="reset-modal" class="danger-modal" aria-labelledby="reset-modal-title">
  <article>
    <header style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
      <h3 id="reset-modal-title" style="margin:0; display:inline-flex; align-items:center; gap:.5rem;">
        <?= View::icon('warning') ?> Réinitialiser PXE Tools
      </h3>
      <button type="button" aria-label="Fermer" id="reset-close-x" class="btn-icon-only">&times;</button>
    </header>

    <div class="modal-body">
      <p>
        Cette action est <strong style="color: var(--color-danger, #ff3b30);">irréversible</strong>.
        Les éléments suivants seront supprimés&nbsp;:
      </p>
      <ul class="danger-list">
        <li>Toutes les machines découvertes</li>
        <li>L'historique des installations</li>
        <li>L'historique des effacements de disques</li>
        <li>L'historique des sessions de découverte</li>
        <li>Les fichiers de boot PXE per-MAC (<code>pxelinux.cfg/01-*</code>)</li>
      </ul>
      <p class="muted">
        Sont <strong>conservés</strong>&nbsp;: modèles, paramètres serveur PXE,
        comptes admin, clé MOK, images OS téléchargées.
      </p>

      <label class="confirm-label">
        Tapez <strong style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--color-danger, #ff3b30);">RESET</strong> pour confirmer
        <input type="text" id="reset-confirm-input" autocomplete="off" placeholder="RESET">
      </label>
    </div>

    <footer>
      <button type="button" id="cancel-reset-btn" class="btn btn-secondary">Annuler</button>
      <button type="submit" form="reset-machines-form" id="reset-confirm-btn" class="btn btn-danger" disabled>
        Confirmer la réinitialisation
      </button>
    </footer>
  </article>
</dialog>

<form id="reset-machines-form" method="post" action="/settings/reset" style="display:none;">
  <input type="hidden" name="confirm" value="">
</form>

<script>
  (function () {
    var dlg    = document.getElementById('reset-modal');
    var input  = document.getElementById('reset-confirm-input');
    var btn    = document.getElementById('reset-confirm-btn');
    var hidden = document.querySelector('#reset-machines-form input[name="confirm"]');
    var openBtn = document.getElementById('open-reset-modal');
    var cancelBtn = document.getElementById('cancel-reset-btn');
    var closeX = document.getElementById('reset-close-x');
    if (!dlg || !input || !btn || !hidden || !openBtn) return;

    function setEnabled() {
      btn.disabled = (input.value !== 'RESET');
      hidden.value = input.value;
    }

    openBtn.addEventListener('click', function () {
      input.value = '';
      setEnabled();
      dlg.showModal();
      setTimeout(function () { input.focus(); }, 0);
    });

    cancelBtn.addEventListener('click', function () { dlg.close(); });
    closeX.addEventListener('click', function () { dlg.close(); });

    // Click sur le ::backdrop (en dehors de l'article) → fermeture.
    // <dialog> native : e.target === dlg quand on clique sur le backdrop.
    dlg.addEventListener('click', function (e) {
      var r = dlg.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) {
        dlg.close();
      }
    });

    input.addEventListener('input', setEnabled);
  })();
</script>

<dialog id="factory-reset-modal" class="danger-modal" aria-labelledby="factory-modal-title">
  <article>
    <header style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
      <h3 id="factory-modal-title" style="margin:0; display:inline-flex; align-items:center; gap:.5rem;">
        <?= View::icon('warning') ?> Réinitialisation complète
      </h3>
      <button type="button" aria-label="Fermer" id="factory-close-x" class="btn-icon-only">&times;</button>
    </header>

    <div class="modal-body">
      <p>
        Cette action est <strong style="color: var(--color-danger, #ff3b30);">irréversible</strong>
        et remet l'appliance à l'état d'<strong>install neuve</strong>. Seront supprimés&nbsp;:
      </p>
      <ul class="danger-list">
        <li>Toute la base : machines, modèles, paramètres serveur PXE</li>
        <li>Les comptes admin (vous devrez en recréer un)</li>
        <li>Les images netboot téléchargées (~1,5&nbsp;Go) + le catalogue</li>
        <li>Les bootloaders TFTP et la clé MOK (régénérés au bootstrap)</li>
      </ul>
      <p class="muted">
        Après réinitialisation, l'appliance repart sur le <strong>wizard
        d'installation</strong> (<code>/init</code>). Le re-téléchargement Debian
        (30-60&nbsp;min) se relancera depuis le wizard.
      </p>

      <label class="confirm-label">
        Tapez <strong style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--color-danger, #ff3b30);">REINITIALISER</strong> pour confirmer
        <input type="text" id="factory-confirm-input" autocomplete="off" placeholder="REINITIALISER">
      </label>
    </div>

    <footer>
      <button type="button" id="cancel-factory-btn" class="btn btn-secondary">Annuler</button>
      <button type="submit" form="factory-reset-form" id="factory-confirm-btn" class="btn btn-danger" disabled>
        Tout réinitialiser
      </button>
    </footer>
  </article>
</dialog>

<form id="factory-reset-form" method="post" action="/settings/factory-reset" style="display:none;">
  <input type="hidden" name="confirm" value="">
</form>

<script>
  (function () {
    var dlg    = document.getElementById('factory-reset-modal');
    var input  = document.getElementById('factory-confirm-input');
    var btn    = document.getElementById('factory-confirm-btn');
    var hidden = document.querySelector('#factory-reset-form input[name="confirm"]');
    var openBtn = document.getElementById('open-factory-modal');
    var cancelBtn = document.getElementById('cancel-factory-btn');
    var closeX = document.getElementById('factory-close-x');
    if (!dlg || !input || !btn || !hidden || !openBtn) return;

    function setEnabled() {
      btn.disabled = (input.value !== 'REINITIALISER');
      hidden.value = input.value;
    }
    openBtn.addEventListener('click', function () {
      input.value = ''; setEnabled(); dlg.showModal();
      setTimeout(function () { input.focus(); }, 0);
    });
    cancelBtn.addEventListener('click', function () { dlg.close(); });
    closeX.addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) {
      var r = dlg.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dlg.close();
    });
    input.addEventListener('input', setEnabled);
  })();
</script>
