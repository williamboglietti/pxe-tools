<?php
use Admin\View;
$error      = $error ?? null;
$next = $next ?? '/machines';
$errorMsg = match ($error) {
    'invalid' => 'Identifiants incorrects.',
    'missing' => 'Renseignez votre nom d\'utilisateur et votre mot de passe.',
    null      => null,
    default   => 'Erreur d\'authentification.',
};
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion · PXE Tools</title>
<!-- Anti-FOUC : pose data-theme AVANT le CSS (choix stocké, sinon préférence système). -->
<script>(function(){try{var t=localStorage.getItem('theme');if(t!=='dark'&&t!=='light'){t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="/style.tailwind.css?v=<?= @filemtime(__DIR__ . '/../../../public/style.tailwind.css') ?: time() ?>">
</head>
<body style="background-color: var(--color-surface-0); color: var(--color-text-primary); min-height: 100vh;">
<main class="flex items-center justify-center px-4" style="min-height: 100vh;">
  <div class="card w-full" style="max-width: 28rem;">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold tracking-tight m-0">PXE Tools <?= View::appVersionBadge() ?></h1>
      <p class="text-sm mt-1" style="color: var(--color-text-secondary);">Connexion administrateur</p>
    </header>

    <?php if (!empty($created)): ?>
      <p class="flash flash-success" role="status">Compte administrateur créé. Connectez-vous pour poursuivre à l’étape Réseau.</p>
    <?php endif; ?>
    <?php if ($errorMsg !== null): ?>
      <div role="alert" class="mb-4 px-3 py-2 rounded-lg text-sm flex items-center gap-2"
           style="background-color: var(--color-danger-bg); color: var(--color-danger);">
        <?= View::e($errorMsg) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/login" autocomplete="on" class="flex flex-col gap-4">
      <input type="hidden" name="next" value="<?= View::e($next) ?>">
      <div class="field">
        <label class="field-label" for="login-username">Nom d'utilisateur</label>
        <input id="login-username" type="text" name="username" required autofocus
               autocomplete="username" class="input"
               value="<?= View::e($_POST['username'] ?? '') ?>">
      </div>
      <div class="field">
        <label class="field-label" for="login-password">Mot de passe</label>
        <input id="login-password" type="password" name="password" required
               autocomplete="current-password" class="input">
      </div>
      <button type="submit" class="btn btn-primary btn-lg w-full mt-2">Se connecter</button>
    </form>
  </div>
</main>
</body>
</html>
