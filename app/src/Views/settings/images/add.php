<?php
use Admin\View;
use Admin\Catalog\DistroCatalogInterface;
/** @var array<string, DistroCatalogInterface> $distros */
/** @var array<string, array<int, array<string, array{codename:?string, channel:?string, archs:array<string,array<string,mixed>>}>>> $grouped */
/** @var array<string, int> $counts */

$totalEntries = array_sum($counts);
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2>Ajouter une image</h2>
    <p class="muted">
      Sélectionnez les architectures à télécharger pour chaque version, puis
      cliquez sur <strong>« Télécharger sélection »</strong>.
    </p>
  </hgroup>
  <a href="/settings/images" class="picker-back">← Retour</a>
</header>

<?php if ($totalEntries === 0): ?>
  <article class="images-empty">
    <p class="muted">
      <?= View::icon('package') ?>
      Le catalogue est vide. Retournez sur
      <a href="/settings/images">/settings/images</a> et cliquez sur
      <strong>« ↻ Refresh catalogue »</strong> pour découvrir les versions
      disponibles upstream (Debian, Ubuntu, Fedora, etc.).
    </p>
  </article>
<?php else: ?>

  <?php View::partial('_partials/os-picker-form', [
      'distros'     => $distros,
      'grouped'     => $grouped,
      'counts'      => $counts,
      'form_action' => '/settings/images/add',
  ]); ?>

<?php endif; ?>
