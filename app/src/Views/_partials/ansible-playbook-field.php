<?php
use Admin\View;
$profile = \Admin\Util\RepositoryProfile::status();
$fieldId = (string)($fieldId ?? 'ansible-playbook');
$ansibleRole = (string)($ansibleRole ?? '');
?>
<label for="<?= View::e($fieldId) ?>"><?= $profile['active'] ? 'Playbook autorisé par le profil' : 'Nom du playbook' ?><?php if (!$profile['active']): ?> <span class="muted">(sans .yml)</span><?php endif; ?></label>
<input type="text" id="<?= View::e($fieldId) ?>" name="ansible_role"
       value="<?= View::e($ansibleRole) ?>" placeholder="<?= View::e($profile['active'] && $profile['playbooks'] !== [] ? (string)array_key_first($profile['playbooks']) : 'role-web') ?>"
       <?php if ($profile['active'] && $profile['valid']): ?>list="<?= View::e($fieldId) ?>-choices"<?php endif; ?>>
<?php if ($profile['active'] && $profile['valid']): ?>
<datalist id="<?= View::e($fieldId) ?>-choices">
  <?php foreach ($profile['playbooks'] as $role => $path): ?><option value="<?= View::e($role) ?>"><?= View::e($path) ?></option><?php endforeach; ?>
</datalist>
<small class="form-hint">Playbooks du profil : <?= View::e(implode(', ', array_keys($profile['playbooks']))) ?>. Une valeur vide omet la configuration Ansible après installation.</small>
<?php elseif (!$profile['valid']): ?>
<small class="form-hint" style="color:var(--color-danger)"><?= View::e($profile['message']) ?> Aucun playbook ne peut être programmé avec cette configuration.</small>
<?php else: ?>
<small class="form-hint">Si renseigné, le worker utilise le playbook correspondant après l’installation.</small>
<?php endif; ?>
