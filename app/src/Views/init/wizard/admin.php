<?php
use Admin\View;
$step      = $step ?? 2;
$stepLabel = 'Compte admin';
$error = $error ?? null;
$existingAdmin = $existingAdmin ?? null;
$errorMsg = match ($error) {
    'missing'         => 'Renseignez le nom d\'utilisateur et le mot de passe.',
    'too_short'       => 'Le mot de passe doit faire au moins 8 caractères.',
    'mismatch'        => 'Les deux mots de passe ne correspondent pas.',
    'username_taken'  => 'Ce nom d\'utilisateur est déjà pris.',
    null              => null,
    default           => 'Erreur de validation.',
};
$title = 'Compte admin';
$contentRender = function () use ($errorMsg, $existingAdmin, $step, $stepLabel) {
    include __DIR__ . '/_progress.php';
?>
  <header><h2 style="margin:0;">Création du compte administrateur</h2></header>

  <?php if ($errorMsg): ?>
    <article class="flash flash-error" data-persistent><?= View::e($errorMsg) ?></article>
  <?php endif; ?>

  <?php if ($existingAdmin !== null): ?>
    <article class="flash flash-info" data-persistent>
      Un compte administrateur existe déjà : <strong><?= View::e($existingAdmin) ?></strong>.
      Vous pouvez le conserver et passer à l'étape suivante, ou le remplacer
      en créant un nouveau compte (l'ancien sera supprimé).
    </article>

    <div class="wizard-actions">
      <a href="/init/db" class="btn btn-secondary"><span class="btn-arrow">←</span> Retour</a>
      <a href="/login?next=%2Finit%2Fnetwork" class="btn btn-primary">Conserver et continuer <span class="btn-arrow">→</span></a>
    </div>

    <details style="margin-top:1.5rem;">
      <summary>Remplacer le compte existant</summary>
      <p class="muted" style="margin-top:1rem;">
        L'utilisateur <code><?= View::e($existingAdmin) ?></code> sera supprimé
        et remplacé par celui que vous créez ci-dessous.
      </p>
      <form method="post" action="/init/admin" autocomplete="on">
        <input type="hidden" name="recreate" value="1">
        <label>
          Nouveau nom d'utilisateur
          <input type="text" name="username" required
                 pattern="^[a-zA-Z0-9_-]{2,32}$"
                 autocomplete="username"
                 placeholder="admin"
                 value="<?= View::e($_POST['username'] ?? '') ?>">
          <small class="muted">2 à 32 caractères : lettres, chiffres, tirets, underscores.</small>
        </label>
        <label>
          Mot de passe
          <input type="password" name="password" required minlength="8"
                 autocomplete="new-password"
                 placeholder="Au moins 8 caractères">
        </label>
        <label>
          Confirmer le mot de passe
          <input type="password" name="password_confirm" required minlength="8"
                 autocomplete="new-password">
        </label>
        <div class="wizard-actions actions-end">
          <button type="submit" class="btn btn-primary">Remplacer le compte</button>
        </div>
      </form>
    </details>
  <?php else: ?>
    <p class="muted">
      Ce compte servira à vous connecter à l'UI admin (port 8443 par défaut).
      Vous pourrez créer d'autres utilisateurs plus tard.
    </p>

    <form method="post" action="/init/admin" autocomplete="on">
      <label>
        Nom d'utilisateur
        <input type="text" name="username" required autofocus
               pattern="^[a-zA-Z0-9_-]{2,32}$"
               autocomplete="username"
               placeholder="admin"
               value="<?= View::e($_POST['username'] ?? '') ?>">
        <small class="muted">2 à 32 caractères : lettres, chiffres, tirets, underscores.</small>
      </label>

      <label>
        Mot de passe
        <input type="password" name="password" required minlength="8"
               autocomplete="new-password"
               placeholder="Au moins 8 caractères">
      </label>

      <label>
        Confirmer le mot de passe
        <input type="password" name="password_confirm" required minlength="8"
               autocomplete="new-password">
      </label>

      <div class="wizard-actions">
        <a href="/init/db" class="btn btn-secondary"><span class="btn-arrow">←</span> Retour</a>
        <button type="submit" class="btn btn-primary">Suivant <span class="btn-arrow">→</span></button>
      </div>
    </form>
  <?php endif; ?>
<?php };
include __DIR__ . '/_layout.php';
