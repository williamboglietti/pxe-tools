<?php
use Admin\View;
use Admin\Support\Format;
/**
 * Partial timeline des tâches d'une machine — 1 ligne par action parente.
 * Refonte spec §Timeline machine : agrège par action_id, lien vers /tasks/{action_id}.
 *
 * @var array $machine
 * @var array<int,array{type:string,id:int,action_id:int,status:string,started_at:?string,ended_at:?string,created_at:?string,duration_s:?int,summary:string,subjobs_summary:string}> $tasks
 */

// SEC : token déjà retiré par listFragment (TaskController) avant d'arriver ici.
// Si ce partial est inclus depuis un autre context, on dépouille par précaution.
foreach ($tasks as &$row) {
    unset($row['token']);
}
unset($row);

$icon = fn(string $status): string => match ($status) {
    'success' => View::icon('circle-check'),
    'running' => View::icon('circle'),
    'failed'  => View::icon('circle-x'),
    'pending' => View::icon('circle'),
    default   => '',
};

$statusClass = fn(string $status): string => match ($status) {
    'success' => 'badge-success',
    'running' => 'badge-info',
    'failed'  => 'badge-danger',
    default   => 'badge-neutral',
};

$statusLabel = fn(string $status): string => match ($status) {
    'success'   => 'succès',
    'running'   => 'en cours',
    'failed'    => 'échec',
    'pending'   => 'en attente',
    'cancelled' => 'clôturée',
    default     => $status,
};

$relativeTime = function (?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    if ($ts === false) return '—';
    $diff = time() - $ts;
    if ($diff < 60)    return 'à l\'instant';
    if ($diff < 3600)  return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . ' h';
    if ($diff < 86400 * 7) return 'il y a ' . floor($diff / 86400) . ' j';
    return date('d/m H:i', $ts);
};
?>

<?php if (empty($tasks)): ?>
  <p class="muted">Aucune tâche enregistrée pour cette machine.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th></th>
        <th>Action</th>
        <th>Statut</th>
        <th>Démarrée</th>
        <th>Durée</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tasks as $t):
        // Lien canonique vers la page action parente
        $url = '/tasks/' . (int)($t['action_id'] ?? $t['id']);
        $status   = (string)($t['status'] ?? 'pending');
        $subjobs  = (string)($t['subjobs_summary'] ?? '');
        $subtitle = $subjobs && $subjobs !== 'aucun sous-job' ? ' (' . $subjobs . ')' : '';
      ?>
        <tr>
          <td style="font-size:1.2rem;text-align:center;width:2rem;color:<?= $status === 'success' ? 'var(--color-success,#15803d)' : ($status === 'failed' ? 'var(--color-danger,#ff3b30)' : ($status === 'running' ? 'var(--color-apple-blue,#0071e3)' : 'var(--color-text-secondary)')) ?>;"
              aria-label="État : <?= View::e($statusLabel($status)) ?>" role="img">
            <?= $icon($status) ?>
          </td>
          <td>
            <a href="<?= View::e($url) ?>" style="text-decoration:none;">
              <strong><?= View::e($t['summary']) ?></strong><?php if ($subtitle): ?><span class="muted" style="font-weight:normal;"> <?= View::e($subtitle) ?></span><?php endif ?>
            </a>
          </td>
          <td>
            <span class="status-badge <?= $statusClass($status) ?>" style="font-size:0.72rem;">
              <?= View::e($statusLabel($status)) ?>
            </span>
          </td>
          <td class="muted" style="font-size:0.85rem;"><?= View::e($relativeTime($t['started_at'] ?? $t['created_at'] ?? null)) ?></td>
          <td class="muted" style="font-size:0.85rem;font-family:ui-monospace,'SF Mono',monospace;"><?= View::e(Format::humanDuration($t['duration_s'], compact: true)) ?></td>
          <td style="text-align:right;">
            <?php if ($status === 'running' || $status === 'pending'):
              // Bouton compact "Clôturer le suivi" — visible quand l'action est
              // active. Action irréversible derrière confirm() : pose l'action
              // en 'cancelled' et clôt les sous-jobs encore actifs pour
              // permettre une nouvelle opération sur la machine.
            ?>
              <form method="post"
                    action="/actions/<?= (int)($t['action_id'] ?? $t['id']) ?>/force-complete"
                    onsubmit="return confirm('Clôturer le suivi de cette action et de ses sous-jobs actifs ? Cette correction administrative ne garantit pas l’arrêt des processus distants.');"
                    style="margin:0;display:inline-block;">
                <button type="submit" class="btn btn-secondary" style="font-size:0.72rem;padding:0.25rem 0.5rem;margin:0;"
                        title="À utiliser si l'opération est physiquement terminée côté machine mais qu'un checkpoint manqué bloque la fiche.">
                  Clôturer le suivi
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
