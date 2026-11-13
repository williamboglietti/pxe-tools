<?php
use Admin\View;
/** @var array $task */
$jobs    = json_decode($task['jobs_json']    ?? '[]', true) ?: [];
$results = json_decode($task['results_json'] ?? '[]', true) ?: [];
?>

<dl>
  <dt>Statut</dt>
  <dd><?= View::e($task['job_status'] ?? '—') ?></dd>
  <dt>Mode</dt>
  <dd><?= (int)$task['parallel'] === 1 ? 'parallèle' : 'séquentiel' ?></dd>
  <dt>Action après fin</dt>
  <dd><?= View::e($task['post_action'] ?? 'poweroff') ?></dd>
</dl>

<h3>Jobs lancés</h3>
<table>
  <thead><tr><th>Disque</th><th>Mode</th><th>Verdict</th><th>Bad blocks</th><th>Durée</th></tr></thead>
  <tbody>
    <?php
    // Map results par "disk:mode" pour merger avec jobs.
    $rmap = [];
    foreach ($results as $r) { $rmap[($r['disk'] ?? '') . '|' . ($r['mode'] ?? '')] = $r; }
    foreach ($jobs as $j):
        $key = ($j['disk'] ?? '') . '|' . ($j['mode'] ?? '');
        $r = $rmap[$key] ?? null;
        $verdict = $r['verdict'] ?? null;
        $verdictBadge = match ($verdict) {
            'passed' => '<span class="badge-success">' . View::icon('circle-check') . ' passed</span>',
            'failed' => '<span class="badge-danger">' . View::icon('circle-x') . ' failed</span>',
            null     => '<span class="muted">—</span>',
            default  => '<span class="badge-neutral">' . View::e($verdict) . '</span>',
        };
    ?>
      <tr>
        <td><code><?= View::e($j['disk'] ?? '—') ?></code></td>
        <td><?= View::e($j['mode'] ?? '—') ?></td>
        <td><?= $verdictBadge ?></td>
        <td><?= isset($r['bad_blocks']) ? (int)$r['bad_blocks'] : '—' ?></td>
        <td><?= isset($r['duration_s']) ? (int)$r['duration_s'] . ' s' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if (!empty($task['log'])): ?>
  <details style="margin-top:1rem;">
    <summary>Log brut (badblocks/smartctl)</summary>
    <pre class="text-mono text-small" style="max-height:50vh;overflow:auto;background:var(--color-surface-1);padding:1rem;border-radius:.25rem;"><?= View::e($task['log']) ?></pre>
  </details>
<?php endif; ?>

<p class="hint-row muted text-small" style="margin-top:1rem;">
  <?= View::icon('lightbulb') ?>
  <span>La page legacy <a href="/disktests/<?= (int)$task['id'] ?>">/disktests/<?= (int)$task['id'] ?></a>
  reste disponible pour le suivi log streaming HTMX.</span>
</p>
