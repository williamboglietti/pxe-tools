<?php
/**
 * Fragment HTMX d'un job de tests de disque (statut + tableau résultats par
 * disque + log brut). Polled toutes les 2s par disktests/show.php.
 *
 * @var array $test  DiskTest::find($id)
 */
use Admin\View;
use Admin\Models\DiskTest;

$jobs = json_decode($test['jobs_json'] ?? '[]', true) ?: [];
$results = json_decode($test['results_json'] ?? '[]', true) ?: [];
// Index résultats par "disk|mode" pour matching rapide
$resByKey = [];
foreach ($results as $r) {
    $resByKey[($r['disk'] ?? '') . '|' . ($r['mode'] ?? '')] = $r;
}

$verdictClass = static fn(?string $v): string => match ($v) {
    'passed' => 'badge-success',
    'failed' => 'badge-danger',
    default  => 'badge-neutral',
};
$verdictLabel = static fn(?string $v): string => match ($v) {
    'passed'  => 'OK',
    'failed'  => 'FAILED',
    'unknown' => 'inconnu',
    default   => '—',
};
$fmtDuration = static function (?int $s): string {
    if (!$s || $s <= 0) return '—';
    if ($s < 60)   return $s . ' s';
    if ($s < 3600) return (int)floor($s / 60) . ' min ' . ($s % 60) . ' s';
    return (int)floor($s / 3600) . ' h ' . ((int)floor(($s % 3600) / 60)) . ' min';
};

// Polling adaptatif auto-porté (pattern jobs/_log.php) : le fragment se
// re-poll lui-même tant que le test vit, puis s'arrête (état terminal).
$isActive     = in_array((string)($test['job_status'] ?? ''), ['pending', 'running'], true);
$pollInterval = match ((string)($test['job_status'] ?? '')) {
    'running' => 2,
    'pending' => 5,
    default   => null,
};
$htmxAttrs = $pollInterval !== null
    ? ' hx-get="/disktests/' . (int)$test['id'] . '/log"'
    . ' hx-trigger="every ' . $pollInterval . 's"'
    . ' hx-swap="outerHTML" hx-indicator=".htmx-indicator"'
    : '';
?>

<article id="disktest-log"<?= $htmxAttrs ?>>
  <header>
    Statut&nbsp;:
    <span class="status-badge <?= View::e(View::statusClass($test['job_status'] ?? null)) ?>">
      <?= View::e(View::statusLabel($test['job_status'])) ?>
    </span>
    <br>
    <small class="muted">
      Démarré <?= View::e($test['started_at'] ?? '–') ?>
      · Terminé <?= View::e($test['ended_at'] ?? '–') ?>
    </small>
  </header>

  <?php if ($isActive): ?>
    <h4 style="margin-top:1rem;">Suivi en direct</h4>
    <?php View::partial('disktests/_progress', ['test' => $test]); ?>
  <?php endif; ?>

  <h4 style="margin-top:1rem;">Résultats par disque</h4>
  <table class="disktest-results">
    <thead>
      <tr>
        <th>Disque</th>
        <th>Mode</th>
        <th>Verdict</th>
        <th>Bad blocks</th>
        <th>Durée</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($jobs as $j):
        $key = ($j['disk'] ?? '') . '|' . ($j['mode'] ?? '');
        $r = $resByKey[$key] ?? null;
        $modeLabel = DiskTest::MODE_LABELS[$j['mode'] ?? ''] ?? ($j['mode'] ?? '');
      ?>
        <tr>
          <td><code><?= View::e($j['disk'] ?? '?') ?></code></td>
          <td><?= View::e($modeLabel) ?></td>
          <td>
            <?php if ($r === null): ?>
              <span class="status-badge badge-running">en cours…</span>
            <?php else: ?>
              <span class="status-badge <?= View::e($verdictClass($r['verdict'] ?? '')) ?>">
                <?= View::e($verdictLabel($r['verdict'] ?? '')) ?>
              </span>
            <?php endif; ?>
          </td>
          <td><?= $r === null ? '—' : (int)($r['bad_blocks'] ?? 0) ?></td>
          <td><?= $r === null ? '—' : View::e($fmtDuration($r['duration_s'] ?? null)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h4 style="margin-top:1rem;">Log</h4>
  <pre class="log"><?= View::e($test['log'] ?? '(en attente du démarrage des tests sur la machine cible…)') ?></pre>
</article>
