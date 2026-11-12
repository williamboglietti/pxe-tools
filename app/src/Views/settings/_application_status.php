<?php
use Admin\View;
$jobs = $jobs ?? [];
?>
<?php if ($jobs !== []): ?>
<section class="card mb-6" aria-label="Résultat de la dernière demande">
  <h3>Dernière demande d’application</h3>
  <p class="muted text-small">État retourné par le worker pour ces traitements précis. Une configuration enregistrée n’est appliquée qu’après leur réussite.</p>
  <ul>
  <?php foreach ($jobs as $job): ?>
    <li class="mb-2">
      <span class="badge <?= View::statusClass($job['status']) ?>"><?= View::e(match ($job['status']) {
          'success' => 'Succès confirmé', 'failed' => 'Échec', 'running' => 'En cours', 'skipped' => 'Ignoré', default => 'En attente',
      }) ?></span>
      <a href="/jobs/<?= (int)$job['id'] ?>">Traitement #<?= (int)$job['id'] ?> · <?= View::e($job['job_type']) ?></a>
      <?php if (!empty($job['message'])): ?><span class="muted"> — <?= View::e($job['message']) ?></span><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
