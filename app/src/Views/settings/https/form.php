<?php
use Admin\View;
$https_port = $https_port ?? (int)(getenv('ADMIN_PORT') ?: 8443);
$http_port = $http_port ?? (int)(getenv('ADMIN_HTTP_PORT') ?: 8080);
$host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost';
$httpsUrl = 'https://' . $host . ($https_port === 443 ? '' : ':' . $https_port);
$httpUrl = 'http://' . $host . ($http_port === 80 ? '' : ':' . $http_port);
?>
<header class="mb-6">
  <a href="/settings">← Paramètres de l’appliance</a>
  <h2>Accès HTTPS</h2>
  <p class="muted">Choisissez si l’appliance chiffre directement les connexions au portail, ou si un reverse proxy assure le TLS.</p>
</header>
<?php View::partial('settings/_application_status', ['jobs' => $jobs ?? []]); ?>
<form method="post" action="/settings/https" class="card">
  <fieldset>
    <legend>Configuration enregistrée</legend>
    <label for="https-enabled"><input type="checkbox" name="https_enabled" id="https-enabled" role="switch" <?= $enabled ? 'checked' : '' ?>>
      Activer HTTPS local et rediriger HTTP vers HTTPS
    </label>
    <p class="muted">Choix enregistré : <strong><?= $enabled ? 'HTTPS local demandé' : 'HTTP local demandé' ?></strong>.</p>
    <p>Après application réussie, l’adresse attendue est <code><?= View::e($enabled ? $httpsUrl : $httpUrl) ?></code>.</p>
  </fieldset>
  <details class="mb-4">
    <summary>Ports, certificat et reverse proxy</summary>
    <p>HTTP : <code><?= View::e($httpUrl) ?></code> · HTTPS : <code><?= View::e($httpsUrl) ?></code>.</p>
    <p class="muted">Le certificat local est auto-signé : vérifiez son identité avant de l’accepter. Quand le TLS est terminé par votre reverse proxy, il peut joindre l’appliance sur son port HTTP.</p>
  </details>
  <p class="muted">Enregistrer crée une demande de rechargement nginx. Le suivi confirme sa réussite ou présente l’erreur ; l’adresse actuelle peut rester nécessaire pendant le traitement.</p>
  <div class="actions"><button type="submit" class="btn btn-primary">Enregistrer et demander l’application</button></div>
</form>
