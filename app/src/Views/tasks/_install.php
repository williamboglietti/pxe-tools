<?php
use Admin\View;
/** @var array $task */
?>

<dl>
  <dt>Statut install</dt>
  <dd><?= View::e($task['install_status'] ?? '—') ?></dd>
  <dt>Statut Ansible</dt>
  <dd><?= View::e($task['ansible_status'] ?? '—') ?></dd>
</dl>

<h3>Journal de l’installation du système</h3>
<pre class="log"><?= View::e($task['install_log'] ?? 'Aucun journal reçu.') ?></pre>

<?php if (!empty($task['ansible_log'])): ?>
  <h3>Log Ansible</h3>
  <pre class="text-mono text-small" style="max-height:60vh;overflow:auto;background:var(--color-surface-1);padding:1rem;border-radius:.25rem;"><?= View::e($task['ansible_log']) ?></pre>
<?php else: ?>
  <p class="muted">Pas encore de log Ansible (provisioning pas démarré ou Ansible désactivé).</p>
<?php endif; ?>

<p class="hint-row muted text-small" style="margin-top:1rem;">
  <?= View::icon('lightbulb') ?>
  <span>La page legacy <a href="/installations/<?= (int)$task['id'] ?>">/installations/<?= (int)$task['id'] ?></a>
  reste disponible pour le suivi log streaming HTMX.</span>
</p>
