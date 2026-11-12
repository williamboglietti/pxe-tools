<?php
use Admin\View;
/** @var array $task */
$disks = json_decode($task['disks_json'] ?? '[]', true) ?: [];
?>

<dl>
  <dt>Mode</dt>
  <dd><code><?= View::e($task['wipe_mode'] ?? '—') ?></code></dd>
  <dt>Statut</dt>
  <dd><?= View::e($task['wipe_status'] ?? '—') ?></dd>
  <dt>Action après fin</dt>
  <dd><?= View::e($task['post_action'] ?? 'poweroff') ?></dd>
  <dt>Disques ciblés</dt>
  <dd>
    <?php foreach ($disks as $d): ?>
      <?php
        // Backwards-compat : disks_json a évolué de ["/dev/sda"] (strings) vers
        // [{"name":"/dev/sda","mode":"partial"}] (objets per-disque). On supporte
        // les 2 formats pour les rows existantes en DB.
        $disk = is_array($d) ? ($d['name'] ?? '') : (string)$d;
        $mode = is_array($d) ? ($d['mode'] ?? '') : '';
      ?>
      <code><?= View::e($disk) ?></code><?php if ($mode !== ''): ?> <small class="muted">(<?= View::e($mode) ?>)</small><?php endif; ?>
    <?php endforeach; ?>
  </dd>
</dl>

<?php if (!empty($task['log'])): ?>
  <h3>Log effacement</h3>
  <pre class="text-mono text-small" style="max-height:60vh;overflow:auto;background:var(--color-surface-1);padding:1rem;border-radius:.25rem;"><?= View::e($task['log']) ?></pre>
<?php else: ?>
  <p class="muted">Pas encore de log (effacement pas démarré ou pas terminé).</p>
<?php endif; ?>

<p class="hint-row muted text-small" style="margin-top:1rem;">
  <?= View::icon('lightbulb') ?>
  <span>La page legacy <a href="/wipes/<?= (int)$task['id'] ?>">/wipes/<?= (int)$task['id'] ?></a>
  reste disponible pour le suivi log streaming HTMX.</span>
</p>
