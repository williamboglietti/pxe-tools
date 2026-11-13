<?php
use Admin\View;
use Admin\Models\Setting;
$step      = $step ?? 5;
$stepLabel = 'Validation';
$title = 'Validation';
$contentRender = function () use ($step, $stepLabel) {
    include __DIR__ . '/_progress.php';
?>
  <header><h2 style="margin:0;">Configuration prête</h2></header>

  <p>
    Tout est prêt. Récapitulatif :
  </p>

  <?php
    $iconStyle = 'display:inline-flex;align-items:center;gap:.4rem;';
    $dhcpOn   = Setting::get(Setting::KEY_DHCP_PROXY_ENABLED) === 'on';
    $httpsOn  = Setting::get(Setting::KEY_HTTPS_ENABLED, 'off') === 'on';
  ?>
  <dl>
    <dt>Carte réseau utilisée</dt>
    <dd><code><?= View::e(Setting::get(Setting::KEY_PXE_INTERFACE) ?? '–') ?></code></dd>
    <dt>Adresse du serveur</dt>
    <dd><code><?= View::e(Setting::get(Setting::KEY_PXE_IP) ?? '–') ?></code></dd>
    <dt>Mode automatique</dt>
    <dd>
      <span style="<?= $iconStyle ?>">
        <?= View::icon($dhcpOn ? 'circle-check' : 'circle') ?>
        <?= $dhcpOn ? 'activé' : 'désactivé (votre équipement réseau s\'en charge)' ?>
      </span>
    </dd>
    <dt>Accès au portail d'administration</dt>
    <dd>
      <span style="<?= $iconStyle ?>">
        <?= View::icon($httpsOn ? 'lock' : 'circle') ?>
        <?= $httpsOn ? 'connexion sécurisée' : 'connexion non chiffrée (réseau de confiance)' ?>
      </span>
    </dd>
  </dl>

  <p class="muted" style="margin-top:1rem;">
    Le serveur va maintenant se préparer. Vous pourrez ajouter des systèmes à
    installer depuis <strong>Paramètres › Systèmes</strong> une fois la
    configuration terminée.
  </p>

  <form method="post" action="/init/complete" style="margin-top:1.5rem;">
    <div class="wizard-actions">
      <a href="/init/images" class="btn btn-secondary"><span class="btn-arrow">←</span> Retour</a>
      <button type="submit" class="btn btn-primary">Terminer la configuration <span class="btn-arrow">→</span></button>
    </div>
  </form>
<?php };
include __DIR__ . '/_layout.php';
