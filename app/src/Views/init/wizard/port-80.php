<?php
use Admin\View;
$title = 'Libérer le port HTTP';
$centerOnPage = false;
$contentRender = function () use ($job, $missingConsent, $identityUnavailable) {
?>
<h2>Libérer le port utilisé pour le démarrage PXE</h2>
<p>Le port TCP 80 doit être disponible pour PXE Tools. L’installation de Debian peut avoir démarré son service nginx par défaut.</p>
<p>Si vous choisissez l’action ci-dessous, PXE Tools vérifiera d’abord que tous les programmes qui écoutent sur le port 80 appartiennent bien à <strong>nginx.service</strong>. Il arrêtera ensuite ce service et désactivera son démarrage automatique. <strong>Les sites ou services qui utilisent ce nginx cesseront de fonctionner, y compris après un redémarrage.</strong> Sa configuration et ses fichiers resteront en place.</p>
<p>Si un autre programme occupe le port, si nginx n’est pas identifié avec certitude ou si vous souhaitez conserver ce service, ne poursuivez pas : vérifiez votre configuration réseau et serveur vous-même.</p>
<?php if ($identityUnavailable): ?><p role="alert">Le processus nginx actuel ne peut pas être identifié ; aucune action n’a été demandée.</p><?php endif; ?>
<?php if ($missingConsent): ?><p role="alert">Cochez la confirmation pour demander cette action.</p><?php endif; ?>
<?php if (is_array($job)): ?>
  <p>État de la demande : <strong><?= View::e((string)$job['status']) ?></strong>.</p>
  <?php if (in_array($job['status'], ['pending', 'running'], true)): ?><p>Rechargez cette page pour suivre son résultat.</p><?php endif; ?>
  <?php if ($job['status'] === 'failed'): ?>
    <p role="alert">La vérification ou l’action a échoué. Aucun autre service ne sera arrêté automatiquement.</p>
    <details><summary>Détails de la vérification</summary><pre><?= View::e(substr((string)($job['log'] ?? ''), -2500)) ?></pre></details>
  <?php endif; ?>
  <?php if ($job['status'] === 'success'): ?><p>Le port est libre. Le réseau PXE doit encore être configuré et confirmé séparément ; le service HTTP sera démarré à cette étape.</p><?php endif; ?>
<?php endif; ?>
<form method="post" action="/init/port-80">
<label><input type="checkbox" name="confirm_stop_nginx" value="1" required> Je demande l’arrêt et la désactivation du service nginx identifié, et je comprends que ses sites deviendront indisponibles.</label>
<button type="submit">Vérifier puis arrêter nginx</button>
</form>
<p><a href="/init/network">Retour à la configuration réseau</a></p>
<?php };
include __DIR__ . '/_layout.php';
