<?php
use Admin\View;

$step    = $step ?? 1;
$current = $current ?? ['driver' => 'sqlite'];
$error   = $error ?? null;
$detail  = $_GET['detail'] ?? null;

$errorMsg = match ($error) {
    'invalid'    => 'Certains champs sont mal renseignés — vérifiez l\'adresse du serveur, le port (entre 1 et 65535) et les noms saisis.',
    'connection' => 'Connexion au serveur impossible : ' . ($detail ? View::e((string)$detail) : '(détail indisponible)'),
    'write'      => 'Impossible d\'enregistrer la configuration : ' . ($detail ? View::e((string)$detail) : '(détail indisponible)'),
    'migrate'    => 'Impossible de préparer la base de données : ' . ($detail ? View::e((string)$detail) : '(détail indisponible)'),
    'unsupported' => 'MySQL et MariaDB ne sont pas encore pris en charge par le worker. Choisissez le stockage intégré SQLite.',
    'external_existing' => 'La configuration externe existante est conservée. Sa migration demande une opération distincte.',
    'invalid_existing' => 'La configuration existante est illisible ou invalide ; elle est conservée et doit être vérifiée avant de continuer.',
    null         => null,
    default      => 'Une erreur de validation est survenue.',
};

$title = 'Base de données';

$configured = $configured ?? false;
$selectedDriver = $current['driver'] ?? 'sqlite';

$contentRender = function () use ($current, $errorMsg, $step, $selectedDriver, $configured) {
    include __DIR__ . '/_progress.php';
?>
  <header><h2 style="margin:0;">Choix de la base de données</h2></header>

  <?php if ($errorMsg): ?>
    <article class="flash flash-error" data-persistent><?= $errorMsg /* déjà escapée */ ?></article>
  <?php endif; ?>

  <p class="muted">
    L'outil enregistre ses données (machines, modèles, réglages, tâches).
    Le stockage intégré (par défaut) ne demande aucune installation : tout
    tient dans un simple fichier, sur place. MySQL et MariaDB ne sont pas encore
    disponibles pour les tâches de cette version.
  </p>

  <?php if ($selectedDriver !== 'sqlite'): ?>
  <article class="flash flash-warning" data-persistent>
    La configuration de base externe existante est conservée. Le worker ne peut
    pas encore traiter ses tâches ; une migration ou une prise en charge dédiée
    reste nécessaire. Aucun changement de stockage n’est effectué ici.
  </article>
  <?php if (!$configured): ?>
  <div class="wizard-actions"><a href="/init/welcome" class="btn btn-secondary">Retour</a></div>
  <?php return; endif; endif; ?>

  <?php if ($configured): ?>
  <article class="flash flash-info" data-persistent>
    <strong>Base de données configurée.</strong> Stockage : <?= View::e($selectedDriver) ?>.
    Cette étape est terminée. Un changement du stockage d’une appliance existante demande une migration des données.
  </article>
  <div class="wizard-actions"><a href="/init/welcome" class="btn btn-secondary">Retour</a><a href="/init/admin" class="btn btn-primary">Continuer vers le compte administrateur</a></div>
  <?php return; endif; ?>
  <form method="post" action="/init/db" id="db-form">
    <fieldset>
      <legend>Type de stockage</legend>
      <label for="driver-sqlite">
        <input type="radio" name="driver" id="driver-sqlite" value="sqlite"
               <?= $selectedDriver === 'sqlite' ? 'checked' : '' ?>>
        <strong>Stockage intégré</strong> <span class="muted">— rien à installer, recommandé</span>
      </label>
      <label for="driver-mysql">
        <input type="radio" name="driver" id="driver-mysql" value="mysql"
               disabled>
        <strong>Serveur MySQL</strong> <span class="muted">— pas encore disponible</span>
      </label>
      <label for="driver-mariadb">
        <input type="radio" name="driver" id="driver-mariadb" value="mariadb"
               disabled>
        <strong>Serveur MariaDB</strong> <span class="muted">— pas encore disponible</span>
      </label>
    </fieldset>

    <fieldset id="mysql-fields" style="display:none;">
      <legend>Connexion au serveur</legend>

      <details>
        <summary>Avancé · préparer le serveur (commandes à exécuter)</summary>
        <p class="muted">
          Avant de continuer, créez la base de données et l'utilisateur dédié
          sur votre serveur. Ces commandes demandent un accès administrateur.
          Pour des raisons de sécurité, l'outil ne demande jamais ce mot de
          passe d'administration — cette préparation reste de votre côté.
        </p>
<pre><code>CREATE DATABASE pxe_tools CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pxe_tools'@'%' IDENTIFIED BY '&lt;changeme&gt;';
GRANT ALL PRIVILEGES ON pxe_tools.* TO 'pxe_tools'@'%';
FLUSH PRIVILEGES;</code></pre>
        <p class="muted">
          Remplacez <code>&lt;changeme&gt;</code> par un mot de passe robuste.
          Le serveur doit être joignable depuis cette machine (port 3306 ouvert
          si un pare-feu est en place).
        </p>
      </details>

      <label>
        Adresse du serveur
        <input type="text" name="host" id="db-host"
               value="<?= View::e($current['host'] ?? '') ?>"
               placeholder="192.0.2.5"
               pattern="^[a-zA-Z0-9._\-]+$">
        <small class="muted">L'outil doit pouvoir joindre cette adresse sur votre réseau.</small>
      </label>

      <label>
        Port
        <input type="number" name="port" id="db-port"
               value="<?= View::e((string)($current['port'] ?? 3306)) ?>"
               min="1" max="65535" placeholder="3306">
      </label>

      <label>
        Nom de la base
        <input type="text" name="database" id="db-name"
               value="<?= View::e($current['database'] ?? 'pxe_tools') ?>"
               placeholder="pxe_tools"
               pattern="^[a-zA-Z0-9_]+$">
        <small class="muted">Doit déjà exister (voir les commandes ci-dessus).</small>
      </label>

      <label>
        Utilisateur
        <input type="text" name="username" id="db-user"
               value="<?= View::e($current['username'] ?? 'pxe_tools') ?>"
               placeholder="pxe_tools"
               pattern="^[a-zA-Z0-9_]+$">
      </label>

      <label>
        Mot de passe
        <input type="password" name="password" id="db-pass"
               value="<?= View::e($current['password'] ?? '') ?>"
               autocomplete="new-password">
        <small class="muted">Conservé dans la configuration de l'outil, en accès restreint. Pour le changer, repassez par cet assistant.</small>
      </label>

      <div id="db-test-result" style="margin-top:.5rem;"></div>

      <div style="display:flex;gap:.5rem;">
        <button type="button" id="db-test-btn" class="btn btn-secondary">
          Tester la connexion
        </button>
      </div>
    </fieldset>

    <div class="wizard-actions">
      <a href="/init/welcome" class="btn btn-secondary"><span class="btn-arrow">←</span> Retour</a>
      <button type="submit" class="btn btn-primary">Suivant <span class="btn-arrow">→</span></button>
    </div>
  </form>

  <script>
  (function(){
    const radios    = document.querySelectorAll('input[name="driver"]');
    const fieldset  = document.getElementById('mysql-fields');
    const testBtn   = document.getElementById('db-test-btn');
    const resultDiv = document.getElementById('db-test-result');
    const form      = document.getElementById('db-form');

    function toggle() {
      const driver = document.querySelector('input[name="driver"]:checked').value;
      fieldset.style.display = (driver === 'sqlite') ? 'none' : '';
    }
    radios.forEach(r => r.addEventListener('change', toggle));
    toggle();

    // Bouton "Tester la connexion" — POST form data vers /api/init-db-test
    // sans soumettre le form principal (qui écrirait db.conf).
    testBtn.addEventListener('click', async () => {
      testBtn.disabled = true;
      resultDiv.innerHTML = '<span class="muted">Test en cours…</span>';
      try {
        const fd = new FormData(form);
        const res = await fetch('/api/init-db-test', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });
        resultDiv.innerHTML = await res.text();
      } catch (e) {
        resultDiv.innerHTML = '<span class="db-test-result db-test-result--err">Erreur réseau : ' + e.message + '</span>';
      } finally {
        testBtn.disabled = false;
      }
    });
  })();
  </script>
<?php };
include __DIR__ . '/_layout.php';
