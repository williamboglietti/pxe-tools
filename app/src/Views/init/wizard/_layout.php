<?php
/**
 * @var string   $title
 * @var callable $contentRender
 * @var bool     $centerOnPage  Si true, l'article est centré verticalement
 *                              dans la viewport (page bienvenue).
 * @var bool     $wide          Si true, élargit le conteneur (640px → 880px) —
 *                              utilisé par l'étape images dont le picker riche
 *                              (tableau version × arch) a besoin de plus de place.
 *
 * Le label d'étape + barre de progression sont rendus DANS l'<article>
 * via le partial wizard/_progress.php (étapes 1..4 uniquement, pas la welcome).
 * Le titre "PXE Tools" et le bandeau d'étape global ont été retirés pour
 * éviter le double titre avec le <h2> de chaque page.
 */
use Admin\View;
$centerOnPage = $centerOnPage ?? false;
$wide = $wide ?? false;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= View::e($title ?? 'Initialisation') ?> · PXE Tools</title>
<script>(function(){var t;try{t=localStorage.getItem('theme');}catch(e){}document.documentElement.dataset.theme=t==='light'||t==='dark'?t:(matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');})();</script>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="/style.tailwind.css?v=<?= @filemtime(__DIR__ . '/../../../../public/style.tailwind.css') ?: time() ?>">
</head>
<body<?= $centerOnPage ? ' class="wizard-centered"' : '' ?>>
<main class="container" style="max-width: <?= $wide ? '880px' : '640px' ?>;<?= $centerOnPage ? '' : ' margin-top: 4vh;' ?>">
  <!-- padding-bottom élargi (inline → bat le shorthand padding de tailwind) :
       donne de l'air sous la barre Retour/Suivant, jugée trop collée au bord. -->
  <article style="padding-bottom: 2rem;">
    <?php View::partial('_partials/pxe-http-warning'); ?>
    <?php $contentRender(); ?>
  </article>
</main>
</body>
</html>
