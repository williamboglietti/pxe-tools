<?php
use Admin\View;
/** @var array<int, array<string,mixed>> $images */
/** @var bool $refresh_running */
/** @var array{enabled:bool,interval_hours:int,last_at:int,next_at:int} $auto_refresh */
?>

<header class="flex flex-wrap items-start justify-between gap-4 mb-6">
  <div>
    <h2 class="text-2xl font-semibold tracking-tight m-0">Images système</h2>
    <p class="text-sm text-text-secondary mt-1 max-w-2xl">
      Parcourez le catalogue, demandez un téléchargement, puis vérifiez la disponibilité des fichiers sur l’appliance.
    </p>
  </div>
  <div class="flex items-center gap-2 shrink-0">
    <a href="/settings/images/add" class="btn btn-primary">+ Ajouter</a>
    <?php View::partial('settings/images/_refresh_button', [
        'refresh_running' => $refresh_running,
    ]); ?>
  </div>
</header>

<?php View::partial('settings/_application_status', ['jobs' => $catalog_jobs ?? []]); ?>
<details class="card mb-6" style="padding:1rem 1.25rem;"><summary>Actualisation automatique du catalogue</summary>
  <form method="post" action="/settings/images/auto-refresh" class="flex items-center gap-4" style="flex-wrap:wrap; margin:0;">
    <div style="flex:1 1 22rem;">
      <label for="catalog-auto-refresh-enabled" style="margin:0;">
        <input type="checkbox" name="catalog_auto_refresh_enabled" id="catalog-auto-refresh-enabled" value="1" role="switch"
               <?= $auto_refresh['enabled'] ? 'checked' : '' ?>>
        <strong>Actualisation automatique</strong>
      </label>
      <p class="muted text-sm" style="margin:0.35rem 0 0;">
        Vérifie les images quand aucune machine n’est en cours d’installation, de wipe ou de test.
        <?php if ($auto_refresh['last_at'] > 0): ?>Dernier contrôle : <?= View::e(date('d/m/Y H:i', $auto_refresh['last_at'])) ?>.<?php endif; ?>
        <?php if ($auto_refresh['enabled'] && $auto_refresh['next_at'] > 0): ?>Prochain : <?= View::e(date('d/m/Y H:i', $auto_refresh['next_at'])) ?>.<?php endif; ?>
        <?php if ($auto_refresh['enabled'] && $auto_refresh['next_at'] === 0): ?>Premier contrôle au prochain passage du worker.<?php endif; ?>
      </p>
    </div>
    <label for="catalog-auto-refresh-interval" style="margin:0; min-width:10rem;">
      <span class="text-sm">Fréquence</span>
      <select name="catalog_auto_refresh_interval_hours" id="catalog-auto-refresh-interval" style="margin:0.25rem 0 0;">
        <option value="24" <?= $auto_refresh['interval_hours'] === 24 ? 'selected' : '' ?>>Toutes les 24 h</option>
        <option value="168" <?= $auto_refresh['interval_hours'] === 168 ? 'selected' : '' ?>>Tous les 7 jours</option>
        <option value="720" <?= $auto_refresh['interval_hours'] === 720 ? 'selected' : '' ?>>Tous les 30 jours</option>
      </select>
    </label>
    <button type="submit" class="btn btn-secondary">Enregistrer</button>
  </form>
</details>

<p id="images-freshness" class="muted text-small" role="status">États à l’ouverture de la page. Actualisation automatique pendant les téléchargements.</p>
<?php View::partial('settings/images/_list', [
    'images' => $images,
    'refresh_running' => $refresh_running,
]); ?>

<script src="/appliance-images.js" defer></script>
