<?php
use Admin\View;
use Admin\Models\DiskTest;
$gradeClass = static fn(string $grade): string => match ($grade) {
    'A' => 'badge-success', 'B' => 'badge-info', 'C' => 'badge-warning', 'D' => 'badge-danger', default => 'badge-neutral',
};
?>
<article>
  <header><strong>Fiabilité et historique des disques</strong></header>
  <p class="muted text-small">A : production avec sauvegarde · B : redondance ou copie indépendante · C : données jetables · D : remplacement · ? : test incomplet.</p>
  <?php if (empty($diskReliability)): ?>
    <p class="muted">Aucun disque identifié par un numéro de série unique.</p>
  <?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th>Numéro de série</th><th>Note</th><th>Usage et constats</th><th>Historique</th></tr></thead>
      <tbody>
      <?php foreach ($diskReliability as $disk):
        $latest = $disk['latest'];
        $verdict = $latest['verdict'] ?? ['grade' => '?', 'placement' => 'Un test complet est nécessaire.', 'reasons' => ['Aucun relevé SMART avant/après disponible.']];
      ?>
        <tr>
          <td><code><?= View::e($disk['serial']) ?></code></td>
          <td><span class="status-badge <?= $gradeClass($verdict['grade']) ?>"><?= View::e($verdict['grade']) ?></span></td>
          <td>
            <div><?= View::e($verdict['placement']) ?></div>
            <ul class="text-small"><?php foreach ($verdict['reasons'] as $reason): ?><li><?= View::e($reason) ?></li><?php endforeach; ?></ul>
            <?php if ($latest !== null && str_starts_with($latest['mode'], 'badblocks_') && !$latest['performance_measured']): ?>
              <small class="muted">Les ralentissements n'ont pas été mesurés pendant ce test.</small>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($disk['history'] === []): ?><span class="muted">Aucun test enregistré.</span>
            <?php else: ?>
              <details><summary><?= count($disk['history']) ?> test(s)</summary>
                <table><thead><tr><th>Date</th><th>Test</th><th>Note</th><th>Constats</th></tr></thead><tbody>
                <?php foreach ($disk['history'] as $entry): ?>
                  <tr>
                    <td><?= View::e(View::frenchDateTime($entry['date'] ?? null)) ?></td>
                    <td><a href="/disktests/<?= (int)$entry['test_id'] ?>"><?= View::e(str_replace(' (EFFACEMENT !)', '', DiskTest::MODE_LABELS[$entry['mode']] ?? $entry['mode'])) ?></a></td>
                    <td><span class="status-badge <?= $gradeClass($entry['verdict']['grade']) ?>"><?= View::e($entry['verdict']['grade']) ?></span></td>
                    <td><?= View::e(implode(' ; ', $entry['verdict']['reasons'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody></table>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</article>
