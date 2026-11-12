<?php
/**
 * Partial — bloc user admin (login + groups + password + pubkeys).
 *
 * Variables injectées par View::partial() via extract() :
 * @var array $p     config courante (admin_user_login, admin_user_groups, admin_user_pubkeys, root_login_mode, admin_user_fullname)
 * @var bool  $isNew true si nouvelle entrée (placeholder différent pour le password)
 */
use Admin\View;
$rootMode = $p['root_login_mode'] ?? 'disabled';
?>
<p class="form-hint">Ce compte permet d’administrer la machine, avec <code>sudo</code> par défaut.</p>
<div class="form-row form-row--2col">
  <label>
    Identifiant <span class="required-marker">*</span>
    <input type="text" name="admin_user_login" value="<?= View::e($p['admin_user_login'] ?? '') ?>" required aria-required="true" placeholder="admin">
  </label>
  <label>
    Mot de passe
    <input type="password" name="admin_user_password" placeholder="<?= $isNew ? 'optionnel — la clé SSH suffit' : 'laisser vide pour conserver le mot de passe existant' ?>">
    <small class="form-hint">Stocké chiffré et consultable depuis la fiche machine. Facultatif avec une clé SSH.</small>
  </label>
</div>
<label>
  Clés SSH publiques <span class="muted">(une par ligne)</span>
  <textarea name="admin_user_pubkeys" rows="4" placeholder="ssh-ed25519 AAAA... user@host&#10;ssh-rsa AAAA... laptop@home"><?= View::e($p['admin_user_pubkeys'] ?? '') ?></textarea>
</label>
<fieldset style="border:none; padding:0; margin:0.75rem 0 0;">
  <legend class="muted text-small" style="padding:0;">Compte root</legend>
  <div class="form-row form-row--2col">
    <label style="display:flex; align-items:center; gap:.5rem;">
      <input type="radio" name="root_login_mode" value="enabled" <?= $rootMode !== 'disabled' ? 'checked' : '' ?>>
      <span>Activé <span class="muted text-small">(accès administrateur séparé)</span></span>
    </label>
    <label style="display:flex; align-items:center; gap:.5rem;">
      <input type="radio" name="root_login_mode" value="disabled" <?= $rootMode === 'disabled' ? 'checked' : '' ?>>
      <span>Désactivé <span class="muted text-small">(administration via sudo)</span></span>
    </label>
  </div>
  <small class="form-hint">Si root est désactivé, utilisez le compte administrateur et sudo. Son mot de passe root ne sera pas demandé.</small>
</fieldset>
<details>
  <summary class="muted">Options du compte · nom complet et groupes</summary>
  <label>
    Nom complet de l'utilisateur admin <span class="muted">(optionnel)</span>
    <input type="text" name="admin_user_fullname" value="<?= View::e($p['admin_user_fullname'] ?? '') ?>" placeholder="Prénom NOM">
    <small class="form-hint">Utilisé lorsque le compte root est désactivé.</small>
  </label>
  <label>
    Groupes <span class="muted">(CSV — défaut : <code>sudo</code>)</span>
    <input type="text" name="admin_user_groups" value="<?= View::e($p['admin_user_groups'] ?? 'sudo') ?>" placeholder="sudo,docker,libvirt">
    <small class="form-hint">Ajoute l'utilisateur à ces groupes Linux (ex. <code>docker</code>, <code>libvirt</code>, <code>kvm</code>).</small>
  </label>
</details>
