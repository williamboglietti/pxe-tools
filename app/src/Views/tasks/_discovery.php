<?php
use Admin\View;
/** @var array $task */
/** @var array<int,array> $events */
?>

<h3>Phases reçues</h3>
<?php if (empty($events)): ?>
  <p class="muted">Aucun événement de phase enregistré pour ce cycle.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Heure</th><th>Niveau</th><th>Message</th></tr></thead>
    <tbody>
      <?php foreach ($events as $e): ?>
        <tr>
          <td class="text-mono text-small"><?= View::e($e['created_at'] ?? '—') ?></td>
          <td><?= View::e($e['level'] ?? '—') ?></td>
          <td><?= View::e($e['message'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if (!empty($task['inventory_snapshot_json'])):
    $inv = json_decode($task['inventory_snapshot_json'], true);
?>
  <details style="margin-top:1.5rem;">
    <summary><strong>Inventaire collecté</strong> (snapshot complet)</summary>
    <pre class="text-small" style="max-height:50vh;overflow:auto;background:var(--color-surface-1);padding:1rem;border-radius:.25rem;"><?= View::e(json_encode($inv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </details>
<?php endif; ?>

<?php if (!empty($task['ip_addr'])): ?>
  <p class="hint-row muted text-small" style="margin-top:1rem;">
    <?= View::icon('lightbulb') ?>
    <span>Discovery initiée depuis l'IP <code><?= View::e($task['ip_addr']) ?></code> ·
    dernière phase : <code><?= View::e($task['last_phase'] ?? '—') ?></code></span>
  </p>
<?php endif; ?>
