<?php
use Admin\View;
/**
 * Timeline temps réel des phases d'une tâche en cours (install / wipe / ansible).
 * Polling HTMX every 2s sur /api/machines/{id}/task-progress.
 * Pattern visuel inspiré de GitHub Actions build steps.
 *
 * @var array       $machine
 * @var array       $task     ['type' => 'install'|'wipe', 'id' => int, 'started_at' => ?string]
 * @var array       $phases   liste de phases ordonnées avec status, started_at, ended_at, message
 * @var array       $progress ['done' => int, 'total' => int, 'percent' => int, 'has_failure' => bool, 'is_running' => bool]
 */

$type      = $task['type'] ?? 'install';
$typeLabel = match ($type) {
    'install' => 'Installation Debian',
    'wipe'    => 'Effacement des disques',
    'ansible' => 'Provisioning Ansible',
    default   => 'Tâche',
};
$startedAt = $task['started_at'] ?? null;
$machineId = (int)($machine['id'] ?? 0);
?>
<div id="task-progress"
     class="task-progress"
     hx-get="/api/machines/<?= $machineId ?>/task-progress"
     hx-trigger="every 2s"
     hx-swap="outerHTML">

  <header class="task-progress__header">
    <hgroup>
      <h3 class="task-progress__title">
        <?= View::icon($progress['has_failure'] ? 'circle-x' : 'circle') ?>
        <?= View::e($typeLabel) ?>
      </h3>
      <p class="muted text-small">
        Démarrée <?= View::e(View::frenchDateTime($startedAt)) ?> ·
        <strong><?= (int)$progress['done'] ?>/<?= (int)$progress['total'] ?></strong> phases
      </p>
    </hgroup>
  </header>

  <div class="task-progress__bar">
    <div class="task-progress__bar-fill <?= $progress['is_running'] ? 'is-working' : '' ?> <?= $progress['has_failure'] ? 'has-failure' : '' ?>"
         style="width: <?= (int)$progress['percent'] ?>%"></div>
    <span class="task-progress__bar-label"><?= (int)$progress['percent'] ?>%</span>
  </div>

  <ol class="task-phases">
    <?php foreach ($phases as $p):
        $status = $p['status'];
        $icon = match ($status) {
            'success'  => View::icon('circle-check'),
            'failed'   => View::icon('circle-x'),
            'skipped'  => View::icon('circle'),
            'running'  => View::icon('refresh-cw'),
            default    => View::icon('circle'),
        };
        $cls = 'task-phase task-phase--' . $status;

        // Durée si la phase a un started_at + ended_at
        $duration = '';
        if (!empty($p['started_at']) && !empty($p['ended_at'])) {
            $ts1 = strtotime($p['started_at']);
            $ts2 = strtotime($p['ended_at']);
            if ($ts1 !== false && $ts2 !== false && $ts2 >= $ts1) {
                $diff = $ts2 - $ts1;
                $duration = $diff >= 60 ? sprintf('%dm %02ds', intdiv($diff, 60), $diff % 60) : sprintf('%ds', $diff);
            }
        }
    ?>
      <li class="<?= $cls ?>">
        <span class="task-phase__icon"><?= $icon ?></span>
        <div class="task-phase__body">
          <div class="task-phase__label"><?= View::e($p['label']) ?></div>
          <?php if (!empty($p['message'])): ?>
            <div class="task-phase__sub muted text-small"><?= View::e($p['message']) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($duration !== ''): ?>
          <span class="task-phase__duration muted text-small"><?= View::e($duration) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <p class="muted text-small task-progress__note">
    Cette page se rafraîchit automatiquement toutes les 2 secondes. Vous serez
    redirigé vers le wizard quand la tâche sera terminée.
  </p>
</div>
