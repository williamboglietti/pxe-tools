<?php
/**
 * Partial HTMX-polled : status badge + log live d'un init_job.
 * Recalcule son propre hx-trigger : stop si terminal, 2s si running, 5s si pending.
 *
 * @var array<string,mixed> $job
 * @var array<string,mixed>|null $machine
 */
use Admin\View;
use Admin\Controllers\JobController;

$status = (string)($job['status'] ?? 'pending');
$pollInterval = JobController::pollIntervalForStatus($status);

$htmxAttrs = '';
if ($pollInterval !== null) {
    $htmxAttrs = ' hx-get="/jobs/' . (int)$job['id'] . '/poll"'
               . ' hx-trigger="every ' . (int)$pollInterval . 's"'
               . ' hx-swap="outerHTML"';
}

$badgeLabel = [
    'pending' => 'En attente',
    'running' => 'En cours',
    'success' => 'Succès',
    'failed'  => 'Échec',
    'aborted' => 'Interrompue',
    'skipped' => 'Ignorée',
    'cancelled' => 'Annulée',
][$status] ?? $status;
$progressPct = max(0, min((int)($job['progress_percent'] ?? 0), 100));
?>

<div id="job-log-wrapper" data-poll-scope<?= $htmxAttrs ?>>
  <div style="display:flex; align-items:center; gap:.75rem; margin-bottom:.75rem; flex-wrap:wrap;">
    <span class="job-status-badge job-status-badge--<?= View::e($status) ?>">
      <?= View::e($badgeLabel) ?>
    </span>
    <strong><?= View::e((string)($job['message'] ?? '')) ?></strong>
  </div>

  <?php if ($progressPct > 0 && in_array($status, ['running', 'aborted'], true)): ?>
    <div class="task-progress__bar" style="margin-bottom:.75rem;">
      <div class="task-progress__bar-fill" style="width: <?= $progressPct ?>%"></div>
      <span class="task-progress__bar-label"><?= $progressPct ?>%</span>
    </div>
  <?php endif; ?>

  <pre class="job-log"><?= View::e((string)($job['log'] ?? '(pas de log)')) ?></pre>

  <?php if ($status === 'success'): ?>
    <p class="muted text-small" style="margin-top:.75rem;">
      ✓ Terminé le <?= View::e((string)($job['ended_at'] ?? '')) ?>
    </p>
  <?php elseif ($status === 'failed'): ?>
    <p class="muted text-small" style="margin-top:.75rem; color:var(--color-danger);">
      ✗ Échec le <?= View::e((string)($job['ended_at'] ?? '')) ?> — voir log ci-dessus pour le détail.
    </p>
  <?php elseif ($status === 'pending'): ?>
    <p class="muted text-small" style="margin-top:.75rem;">
      ⏱ En attente que le worker prenne le job…
    </p>
  <?php endif; ?>
</div>
