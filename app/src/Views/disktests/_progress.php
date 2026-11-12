<?php
/**
 * Suivi temps réel des tests de disque : une carte par (disque, mode) planifié,
 * avec barre de progression, phase en cours, compteur de secteurs défectueux et
 * temps restant estimé. Données fusionnées par DiskTest::computeProgressRows
 * (jobs_json + progress_json + results_json). Inclus par disktests/_log.php et
 * par le fragment /disktests/{id}/progress (fiche machine, onglet Stockage).
 *
 * @var array $test Row disk_tests complet
 */
use Admin\View;
use Admin\Models\DiskTest;

$rows      = DiskTest::computeProgressRows($test);
$machineId = (int)($test['machine_id'] ?? 0);
$isActive  = in_array((string)($test['job_status'] ?? ''), ['pending', 'running'], true);

$fmtEta = static function (?int $s): string {
    if ($s === null || $s <= 0) return '';
    if ($s < 90)   return 'moins de 2 min';
    if ($s < 3600) return '~' . (int)round($s / 60) . ' min';
    return '~' . (int)floor($s / 3600) . ' h ' . str_pad((string)((int)round(($s % 3600) / 60)), 2, '0', STR_PAD_LEFT) . ' min';
};
$ageSeconds = static function (?string $iso): ?int {
    if (!$iso) return null;
    $ts = strtotime($iso);
    return $ts === false ? null : max(0, time() - $ts);
};
?>
<div class="disktest-progress" style="display:flex; flex-direction:column; gap:.75rem;">
  <?php foreach ($rows as $r):
    $phaseLabel = DiskTest::PHASE_LABELS[$r['phase']] ?? '';
    // « Écriture · motif 1/2 » : le total de motifs dépend du mode.
    if (preg_match('/^(write|verify)(\d+)$/', (string)$r['phase'], $pm)) {
        $passTotal  = DiskTest::MODE_PASS_TOTAL[$r['mode']] ?? null;
        $phaseLabel = ($pm[1] === 'write' ? 'Écriture' : 'Vérification')
                    . ' · motif ' . $pm[2] . ($passTotal !== null ? '/' . $passTotal : '');
    }
    $age        = $ageSeconds($r['updated_at']);
    $stale      = $r['state'] === 'running' && $age !== null && $age > 60;
    $barClass   = $r['state'] === 'running' ? 'is-working' : '';
    if ($r['state'] === 'done' && ($r['verdict'] ?? '') === 'failed') $barClass = 'has-failure';
  ?>
  <div style="padding:.75rem .9rem; border-radius: var(--radius-card, 0.75rem); background-color: var(--color-surface-2); border:1px solid var(--color-divider);">
    <div class="flex items-center justify-between gap-3 flex-wrap" style="margin-bottom:.45rem;">
      <span style="display:inline-flex; align-items:center; gap:.5rem;">
        <code><?= View::e($r['disk']) ?></code>
        <span class="muted text-small"><?= View::e(DiskTest::MODE_LABELS[$r['mode']] ?? $r['mode']) ?></span>
      </span>
      <?php if ($r['state'] === 'done'): ?>
        <span class="status-badge <?= ($r['verdict'] ?? '') === 'passed' ? 'badge-success' : (($r['verdict'] ?? '') === 'failed' ? 'badge-danger' : 'badge-neutral') ?>">
          <?= ($r['verdict'] ?? '') === 'passed' ? 'OK' : (($r['verdict'] ?? '') === 'failed' ? 'Défauts détectés' : 'Indéterminé') ?>
        </span>
      <?php elseif ($r['state'] === 'waiting'): ?>
        <span class="status-badge badge-neutral">en attente de démarrage</span>
      <?php else: ?>
        <span class="muted text-small"><?= View::e($phaseLabel) ?></span>
      <?php endif; ?>
    </div>

    <div class="task-progress__bar">
      <div class="task-progress__bar-fill <?= $barClass ?>" style="width: <?= (int)$r['pct'] ?>%"></div>
      <span class="task-progress__bar-label"><?= (int)$r['pct'] ?>%</span>
    </div>

    <div class="flex items-center justify-between gap-3 flex-wrap text-small" style="margin-top:.4rem;">
      <span>
        <?php if ($r['bad'] !== null && $r['bad'] > 0): ?>
          <strong style="color: var(--color-danger);"><?= (int)$r['bad'] ?> secteur<?= $r['bad'] > 1 ? 's' : '' ?> défectueux</strong>
        <?php elseif ($r['state'] !== 'waiting'): ?>
          <span class="muted">aucun défaut détecté</span>
        <?php endif; ?>
      </span>
      <span class="muted">
        <?php if ($r['state'] === 'running' && $r['eta_s'] !== null && $fmtEta($r['eta_s']) !== ''): ?>
          temps restant (phase en cours) : <?= View::e($fmtEta($r['eta_s'])) ?>
        <?php endif; ?>
        <?php if ($stale): ?>
          <strong style="color: var(--color-danger);">· la machine ne répond plus depuis <?= (int)$age ?> s — vérifiez son écran</strong>
        <?php elseif ($r['state'] === 'running' && $age !== null): ?>
          · mis à jour il y a <?= (int)$age ?> s
        <?php endif; ?>
      </span>
    </div>

    <?php if (!$isActive && $r['state'] === 'done' && ($r['verdict'] ?? '') === 'failed' && $machineId > 0): ?>
      <form method="post" action="/machines/<?= $machineId ?>/disktest" style="margin:.6rem 0 0 0;"
            onsubmit="return dtConfirmRepair('<?= View::e($r['disk']) ?>');">
        <input type="hidden" name="disks[]" value="<?= View::e($r['disk']) ?>">
        <input type="hidden" name="modes[<?= View::e($r['disk']) ?>]" value="badblocks_quick_rw">
        <input type="hidden" name="post_action" value="">
        <button type="submit" class="btn btn-danger" style="width:auto;">Réparer ce disque…</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<script>
function dtConfirmRepair(disk) {
  var typed = prompt(
    'Attention — Réparation de ' + disk + ' :\n\n' +
    'La réparation réécrit tout le disque pour forcer le remplacement des secteurs ' +
    'défectueux par la réserve interne du disque.\n' +
    'TOUTES LES DONNÉES de ' + disk + ' seront EFFACÉES.\n\n' +
    'Saisissez "DETRUIRE" (en majuscules) pour confirmer :'
  );
  return typed === 'DETRUIRE';
}
</script>
