<?php
use Admin\View;

$step      = $step ?? 4;
$stepLabel = 'Systèmes';
$title     = 'Systèmes d\'exploitation';
$wide      = true;   // le picker (tableau version × arch) a besoin de plus de place que 640px

/** @var array $distros */
/** @var array $grouped */
/** @var array $counts */
$totalEntries = array_sum($counts);

$contentRender = function () use ($step, $stepLabel, $distros, $grouped, $counts, $totalEntries) {
    include __DIR__ . '/_progress.php';
?>
  <header><h2 style="margin:0;">Systèmes d'exploitation</h2></header>

  <p class="muted">
    Choisissez les versions à préparer. <strong>Debian (version actuelle)</strong> est
    toujours installée en base — elle sert au mode découverte et au dépannage de vos
    machines, quel que soit votre choix ci-dessous. Les téléchargements démarrent en
    tâche de fond ; l'outil reste utilisable pendant ce temps. Vous pourrez affiner ou
    compléter cette sélection plus tard dans <strong>Paramètres › Systèmes</strong>.
  </p>

  <?php if ($totalEntries === 0): ?>
    <p class="muted">
      <?= View::icon('package') ?>
      Le catalogue se met à jour en arrière-plan (cela peut prendre jusqu'à une
      minute) — vous pourrez choisir des versions précises depuis
      <strong>Paramètres › Systèmes</strong> une fois prêt. En attendant, Debian
      (version actuelle) sera préparée automatiquement.
    </p>
    <form method="post" action="/init/images">
      <div class="wizard-actions">
        <a href="/init/network" class="btn btn-secondary"><span class="btn-arrow">←</span> Retour</a>
        <button type="submit" class="btn btn-primary">Continuer <span class="btn-arrow">→</span></button>
      </div>
    </form>
  <?php else: ?>
    <div class="wizard-actions" style="margin-bottom:1rem;">
      <a href="/init/network" class="btn btn-secondary"><span class="btn-arrow">←</span> Retour</a>
    </div>
    <?php View::partial('_partials/os-picker-form', [
        'distros'            => $distros,
        'grouped'            => $grouped,
        'counts'             => $counts,
        'form_action'        => '/init/images',
        'submit_label'       => 'Continuer <span class="btn-arrow">→</span>',
        'submit_class'       => 'btn btn-primary',
        'require_selection'  => false,
        'empty_tab_hint'     => 'Le catalogue se met à jour en arrière-plan — vous pourrez ajouter cette version plus tard depuis Paramètres › Systèmes.',
    ]); ?>
  <?php endif; ?>
<?php };
include __DIR__ . '/_layout.php';
