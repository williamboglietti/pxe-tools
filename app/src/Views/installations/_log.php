<?php use Admin\View; ?>
<article class="card">
<header class="section-heading"><div><strong>Installation</strong> <span class="status-badge <?= View::e(View::statusClass($install['install_status']??'')) ?>"><?= View::e(View::statusLabel($install['install_status']??'pending')) ?></span><p>Démarrée <?= View::e($install['started_at']??'—') ?> · Terminée <?= View::e($install['ended_at']??'—') ?> UTC</p></div>
<?php if(!empty($install['action_id'])): ?><a class="btn btn-secondary" href="/tasks/<?= (int)$install['action_id'] ?>">Étapes et suivi complet →</a><?php endif ?></header>
<h3>Journal de l’installation du système</h3><pre class="log" id="os-install-log"><?= View::e($install['install_log']?:'Aucun journal installateur reçu.') ?></pre>
<details><summary>Automatisation Ansible · <?= View::e(View::statusLabel($install['ansible_status']??'skipped')) ?></summary><pre class="log" id="ansible-install-log"><?= View::e($install['ansible_log']?:'Aucun journal Ansible reçu.') ?></pre></details>
</article>
