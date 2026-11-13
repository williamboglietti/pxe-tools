<?php
use Admin\View;
/** @var bool   $enabled */
/** @var string $runtime_status */
/** @var string $ssh_pubkey */
/** @var string $ssh_pubkey_source */
/** @var string $privkey_status     'fs' | 'db' | 'none' */
/** @var bool   $privkey_db_present */
/** @var string $privkey_fs_path */
/** @var string $playbooks_dir */
/** @var bool   $vault_unlock_pending */
/** @var string $repo_url */
/** @var string $repo_branch */
/** @var string $repo_profile */
/** @var array  $profile_status */
/** @var bool   $profile_preview */
/** @var bool   $github_push_token_set */
/** @var string $github_push_user */
/** @var bool        $webhook_enabled */
/** @var bool        $webhook_secret_set */
/** @var string      $webhook_url */
/** @var string|null $one_shot_webhook_secret  Secret fraîchement généré, à afficher une seule fois */
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2>Module Ansible</h2>
    <p class="muted">
      Provisioning post-installation (création utilisateurs, déploiement
      configs, exécution de playbooks). Optionnel — désactivé par défaut.
      Les machines reçoivent juste l'install Debian de base si désactivé.
    </p>
  </hgroup>
</header>

<p class="muted">Le socle ne contient pas Ansible. Préparez séparément le runtime adapté à votre installation ; l’activation ci-dessous ne l’installe pas.
  Consultez les instructions du module : <a href="https://github.com/williamboglietti/pxe-tools/blob/main/modules/ansible/NATIVE.md">paquet natif</a> ou <a href="https://github.com/williamboglietti/pxe-tools/blob/main/modules/ansible/README.md">volume Docker</a>.
  Le worker existant exécute les playbooks ; un second conteneur permanent n’est pas nécessaire.</p>
<?php if ($runtime_status === 'ready'): ?>
  <p>Le worker a détecté un environnement Ansible compatible. Il le vérifie à nouveau avant chaque exécution.</p>
<?php elseif ($runtime_status === 'not_configured'): ?>
  <p class="flash flash-warning">Aucun runtime Ansible n’est configuré pour le worker.</p>
<?php elseif ($runtime_status === 'unavailable'): ?>
  <p class="flash flash-warning">L’environnement Ansible est absent, inaccessible ou incompatible. Vérifiez le module préparé.</p>
<?php else: ?>
  <p class="muted">Disponibilité Ansible non confirmée : le worker est peut-être arrêté ou occupé.</p>
<?php endif; ?>

<?php if (!empty($error)): ?>
<article class="flash flash-error" role="alert" tabindex="-1" id="ansible-error" data-persistent>
  <?= View::e($error) ?><p>Corrigez les champs ci-dessous. Les secrets saisis doivent être renseignés à nouveau.</p>
</article>
<script>document.getElementById('ansible-error').focus();</script>
<?php endif; ?>
<section aria-labelledby="ansible-vault-unlock">
  <h3 id="ansible-vault-unlock">Mot de passe Vault Ansible</h3>
  <?php if ($vault_unlock_pending): ?>
    <p>Un déverrouillage est en attente. Il servira à la prochaine exécution Ansible, puis sera effacé.</p>
  <?php else: ?>
    <p class="muted">Aucun déverrouillage en attente. Une exécution Ansible attend la saisie de ce mot de passe.</p>
  <?php endif; ?>
  <form method="post" action="/settings/ansible/vault-unlock">
    <label for="ansible-vault-password">
      Mot de passe Vault
      <input type="password" name="vault_password" id="ansible-vault-password"
             required autocomplete="off" maxlength="1024">
    </label>
    <button type="submit">Déverrouiller pour la prochaine exécution</button>
  </form>
  <small class="muted">
    Jamais enregistré : ni en base, ni dans un fichier persistant. Il reste en
    mémoire ou dans un fichier tmpfs le temps de l’exécution, puis il est effacé,
    y compris en cas d’échec.
  </small>
</section>
<form method="post" action="/settings/ansible">
  <fieldset>
    <legend><strong>Activation</strong></legend>
    <label for="ansible-enabled">
      <input type="checkbox" name="ansible_enabled" id="ansible-enabled" role="switch"
             <?= $enabled ? 'checked' : '' ?>>
      Activer le module Ansible (provisioning post-installation)
    </label>
    <small class="muted" id="ansible-help">
      <?php if ($enabled): ?>
        Activé. Le worker utilisera les playbooks demandés lorsque l’environnement
        Ansible est disponible. Le suivi reste disponible dans Activité.
      <?php else: ?>
        Désactivé (par défaut). Les machines installées reçoivent juste l'OS de base.
        Aucun compte Ansible n'est ajouté aux machines installées. Le suivi reste disponible dans Activité.
      <?php endif; ?>
    </small>
  </fieldset>

  <fieldset id="ansible-fields" style="<?= $enabled ? '' : 'display:none;' ?>">
    <legend><strong>Configuration SSH</strong></legend>

    <label>
      Clé publique SSH (déployée dans <code>authorized_keys</code> des machines installées)
      <?php if ($ssh_pubkey !== ''): ?>
        <input type="text" name="ssh_pubkey" id="ansible-pubkey" readonly
               value="<?= View::e($ssh_pubkey) ?>"
               style="font-family: ui-monospace, monospace; font-size: 0.78rem;">
        <small class="muted">
          Source : <strong><?= View::e($ssh_pubkey_source) ?></strong>.
          Clé publique utilisée pour les prochaines installations.
        </small>
        <button type="button" class="btn btn-secondary btn-small" onclick="const field=document.getElementById('ansible-pubkey');field.readOnly=false;field.focus();this.hidden=true;">Remplacer la clé publique</button>
      <?php else: ?>
        <textarea name="ssh_pubkey" rows="3"
                  placeholder="ssh-ed25519 AAAA... admin@pxe-tools"></textarea>
        <small class="muted">
          Sera déployée dans <code>authorized_keys</code> du user créé par
          preseed sur chaque machine installée. Permet au worker de SSH-er
          sans password.
        </small>
      <?php endif; ?>
    </label>

    <details class="mb-4"><summary>Accès du worker — clé privée et diagnostic</summary>
      <?php if ($privkey_status === 'fs'): ?>
        <p>Clé privée détectée au chemin <code><?= View::e($privkey_fs_path) ?></code> ; cet emplacement doit être préparé par l’administrateur.</p>
      <?php else: ?>
        <p>Aucun fichier de clé privée détecté au chemin <code><?= View::e($privkey_fs_path) ?></code>. Le worker utilise un fichier ; la saisie de clé privée dans cette interface n’est pas disponible.</p>
      <?php endif; ?>
      <?php if (!empty($privkey_db_present)): ?>
        <p class="flash flash-warning">Une ancienne clé privée est stockée en clair dans la base, mais le worker ne l’utilise pas. Elle reste conservée tant que vous ne demandez pas sa suppression.</p>
        <label><input type="checkbox" name="ssh_privkey_clear" value="1"> Effacer explicitement l’ancienne clé privée enregistrée en base</label>
      <?php endif; ?>
      <small class="muted">Vérifiez les clés d’hôtes SSH avant toute connexion. La préparation des clés reste distincte de l’activation.</small>
    </details>
    <label>
      Répertoire des playbooks
      <input type="text" name="playbooks_dir"
             placeholder="<?= View::e($playbooks_dir) ?>"
             value="<?= View::e($playbooks_dir) ?>">
      <small class="muted">
        Ce chemin sert au diagnostic du profil. Le worker utilise son propre
        paramètre <code>ANSIBLE_WORKDIR</code>. Configurez les deux de manière
        cohérente si vous personnalisez ce chemin.
      </small>
    </label>

    <label>
      Dépôt Ansible (URL Git facultative)
      <input type="text" name="repo_url"
             placeholder="https://example.test/playbooks.git"
             value="<?= View::e($repo_url) ?>">
      <small class="muted">
        Laissez vide pour utiliser les playbooks déjà présents dans le répertoire
        local, sans synchronisation Git. Effacer une URL ne supprime pas les
        fichiers du volume. Si une URL est indiquée, le worker tente de
        synchroniser le clone présent avant chaque playbook. Il refuse le clone
        si son origine effective ou sa branche diffère de la configuration.
        Enregistrer une autre URL ne remplace pas ses fichiers.
        Le clonage initial est tenté au démarrage du worker, pas lors de
        l’enregistrement de ce formulaire.
        Pour SSH, configurez si nécessaire <code>PXE_GIT_SSH_KEY</code> et montez les clés d’hôtes vérifiées dans le fichier <code>known_hosts</code> du worker ; les hôtes inconnus sont refusés.
      </small>
    </label>

    <label for="ansible-repo-branch">
      Branche suivie
      <input type="text" name="repo_branch" id="ansible-repo-branch"
             maxlength="255" autocomplete="off" spellcheck="false"
             placeholder="main" value="<?= View::e($repo_branch) ?>"
             aria-describedby="ansible-repo-branch-help">
      <small class="muted" id="ansible-repo-branch-help">
        Si le clone existant suit une autre branche, préparez un clone contrôlé
        avant de reprendre la synchronisation. Le worker ne change pas de branche
        et ne réinitialise pas ce clone automatiquement, afin de préserver ses travaux.
      </small>
    </label>

    <label for="ansible-repo-profile">
      Manifeste du profil PXE (JSON)
      <input type="text" name="repo_profile" id="ansible-repo-profile"
             maxlength="512" autocomplete="off" spellcheck="false"
             placeholder="pxe/profile.json" value="<?= View::e($repo_profile) ?>"
             aria-describedby="ansible-repo-profile-help">
      <small class="muted" id="ansible-repo-profile-help">
        Chemin relatif dans le répertoire de travail. Avec un profil, seuls ses
        playbooks autorisés peuvent être utilisés ; pour un dépôt Git, seuls les
        fichiers nécessaires déclarés sont récupérés. Laissez vide pour utiliser
        les playbooks locaux ou le dépôt complet lorsqu’une URL est configurée.
        Après utilisation d’un profil, ce retour nécessite un clone complet
        contrôlé ; vider le champ ne transforme pas le clone partiel existant.
      </small>
    </label>

    <section class="card mb-4" aria-labelledby="repository-profile-title" id="repository-profile-status">
      <h3 id="repository-profile-title">
        <?php if (!$profile_status['active'] && !$profile_status['valid']): ?>Clone complet requis — playbooks bloqués
        <?php elseif (!$profile_status['active'] && trim($repo_url) === ''): ?>Playbooks locaux
        <?php elseif (!$profile_status['active']): ?>Mode historique — dépôt complet
        <?php elseif ($profile_status['valid']): ?>Profil PXE disponible
        <?php else: ?>Profil PXE indisponible — playbooks bloqués<?php endif; ?>
      </h3>
      <p class="muted"<?php if (!$profile_status['valid']): ?> style="color:var(--color-danger)"<?php endif; ?>>
        <?= View::e($profile_status['message']) ?>
      </p>
      <?php if ($profile_preview): ?>
        <p class="muted text-small">Diagnostic de la saisie affichée ; ces modifications ne sont pas enregistrées.</p>
      <?php endif; ?>
      <?php if ($profile_status['active']): ?>
        <p class="muted text-small">
          Manifeste lu dans le clone local :
          <code><?= View::e($profile_status['source'] ?? ($profile_status['root'] . '/' . $profile_status['manifest'])) ?></code>.
          Ce diagnostic n’effectue aucune synchronisation Git.
        </p>
        <?php if ($profile_status['name'] !== null): ?>
          <p class="text-small">Profil <strong><?= View::e($profile_status['name']) ?></strong>
            · version <?= (int)$profile_status['schema_version'] ?>
            · modèles dans <code><?= View::e($profile_status['presets_path']) ?></code>.</p>
          <details class="mb-4">
            <summary>Fichiers et répertoires du profil (<?= count($profile_status['paths']) ?>)</summary>
            <ul><?php foreach ($profile_status['paths'] as $profilePath): ?>
              <li><code><?= View::e($profilePath) ?></code></li>
            <?php endforeach; ?></ul>
          </details>
          <p class="text-small"><strong><?= $profile_status['valid'] ? 'Playbooks autorisés' : 'Playbooks déclarés (indisponibles)' ?></strong></p>
          <div class="table-scroll">
            <table>
              <thead><tr><th>Rôle</th><th>Fichier</th></tr></thead>
              <tbody><?php foreach ($profile_status['playbooks'] as $profileRole => $profilePlaybook): ?>
                <tr><td><code><?= View::e($profileRole) ?></code></td><td><code><?= View::e($profilePlaybook) ?></code></td></tr>
              <?php endforeach; ?></tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if ($profile_status['missing_paths'] !== []): ?>
          <p class="text-small"><strong>Chemins absents du clone local</strong></p>
          <ul><?php foreach ($profile_status['missing_paths'] as $missingPath): ?>
            <li><code><?= View::e($missingPath) ?></code></li>
          <?php endforeach; ?></ul>
        <?php endif; ?>
      <?php endif; ?>
    </section>

  </fieldset>

  <fieldset id="github-push-fields" style="<?= $enabled ? '' : 'display:none;' ?>">
    <legend><strong>Push GitHub (sauvegarde des modèles)</strong></legend>
    <small class="muted" style="display:block;margin-bottom:0.6rem;">
      La deploy key SSH ci-dessus est généralement <em>read-only</em>. Pour
      permettre au bouton <em>Sauvegarder sur GitHub</em> d'un modèle de
      committer dans le repo, fournissez un Personal Access Token GitHub
      (scope <code>repo</code>) — utilisé uniquement pour le <code>git push</code> en HTTPS.
    </small>

    <label>
      Utilisateur GitHub
      <input type="text" name="github_push_user"
             placeholder="utilisateur"
             value="<?= View::e($github_push_user) ?>">
      <small class="muted">
        Apparaît dans l'URL HTTPS de push : <code>https://&lt;user&gt;:&lt;PAT&gt;@github.com/…</code>
      </small>
    </label>

    <label>
      Personal Access Token (PAT)
      <?php if ($github_push_token_set): ?>
        <input type="password" name="github_push_token" value=""
               placeholder="●●●●●● (défini — laisser vide pour ne pas modifier)">
        <label style="display:flex;align-items:center;gap:0.4rem;margin-top:0.4rem;font-weight:normal;">
          <input type="checkbox" name="github_push_token_clear" value="1">
          Effacer le PAT enregistré
        </label>
      <?php else: ?>
        <input type="password" name="github_push_token" value="" placeholder="ghp_…">
      <?php endif; ?>
      <small class="muted">
        Chiffré en DB via libsodium secretbox (clé maîtresse).
        Créez le PAT sur <code>github.com/settings/tokens</code> avec scope <code>repo</code>.
      </small>
    </label>
  </fieldset>

  <fieldset style="margin-top:1.5rem;">
    <legend><strong>Webhook GitHub — redéploiement automatique</strong></legend>

    <?php if ($one_shot_webhook_secret !== null): ?>
      <?php /* IMPORTANT : ne PAS utiliser la classe .flash ici — toast.js
              hijack tout .flash et le convertit en toast (perd les champs
              interactifs comme l'input + le bouton). On utilise un styling
              inline custom pour garantir le rendu dans la page. */ ?>
      <div id="webhook-secret-callout"
           style="margin-bottom:1rem;padding:1rem;border-left:4px solid #f59e0b;
                  background:rgba(245,158,11,0.08);border-radius:4px;">
        <strong style="display:block;margin-bottom:0.5rem;">
          <?= View::icon('warning') ?> Nouveau secret webhook généré — copiez-le immédiatement
        </strong>
        <p style="margin:0.5rem 0;">
          Ce secret ne sera affiché qu'<strong>une seule fois</strong>. Au prochain
          rechargement de la page, il aura disparu. Collez-le maintenant dans
          GitHub : <em>Settings → Webhooks → Edit → Secret</em>.
        </p>
        <div style="display:flex;gap:0.5rem;align-items:center;margin-top:0.5rem;">
          <input type="text" id="one-shot-webhook-secret" readonly
                 value="<?= View::e($one_shot_webhook_secret) ?>"
                 onclick="this.select()"
                 style="font-family:monospace;flex:1;padding:0.5rem;
                        background:var(--color-surface-2,rgba(127,127,127,0.12));
                        border:1px solid var(--color-divider,rgba(0,0,0,.15));
                        border-radius:4px;user-select:all;">
          <button type="button" id="copy-webhook-secret" class="btn btn-primary"
                  onclick="
                    const input = document.getElementById('one-shot-webhook-secret');
                    input.select();
                    navigator.clipboard.writeText(input.value).then(() => {
                      this.textContent = '✓ Copié';
                      setTimeout(() => { this.textContent = 'Copier'; }, 2000);
                    }).catch(() => {
                      document.execCommand('copy');
                      this.textContent = '✓ Copié';
                      setTimeout(() => { this.textContent = 'Copier'; }, 2000);
                    });
                  ">
            Copier
          </button>
        </div>
      </div>
    <?php endif; ?>

    <p class="muted" style="margin:0.25rem 0 0.75rem;">
      Lorsque vous activez le webhook, chaque push sur le dépôt Ansible déclenche
      un redéploiement automatique sur les machines ayant l'option
      <em>« Redéployer automatiquement »</em> activée dans leurs paramètres.
      Le mapping fichier → machine prend en compte <code>host_vars/{hostname}.yml</code>,
      <code>group_vars/{group}.yml</code>, et la cible <code>hosts:</code> des playbooks.
    </p>

    <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem;">
      <input type="checkbox" name="webhook_enabled" value="1" <?= $webhook_enabled ? 'checked' : '' ?>>
      <strong>Activer la réception du webhook GitHub</strong>
    </label>

    <label>
      URL du webhook (à coller dans GitHub)
      <input type="text" value="<?= View::e($webhook_url) ?>" readonly
             style="font-family:monospace;background:var(--color-surface-2,rgba(127,127,127,0.08));">
      <small class="muted">
        Dans le dépôt GitHub configuré :
        <em>Settings → Webhooks → Add webhook</em>.
        Payload URL = la valeur ci-dessus.
        Content type = <code>application/json</code>.
        Events = <em>Just the push event</em>.
      </small>
    </label>

    <label>
      Secret HMAC
      <?php if ($webhook_secret_set): ?>
        <input type="password" name="webhook_secret" value=""
               placeholder="●●●●●● (défini — laisser vide pour ne pas modifier)">
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:0.5rem;">
          <button type="submit" name="webhook_action" value="regen_secret"
                  class="btn btn-secondary"
                  formnovalidate
                  onclick="return confirm('Générer un nouveau secret va invalider l\'ancien. Vous devrez le copier immédiatement dans GitHub (Settings → Webhooks → Secret), il ne sera plus jamais affiché ensuite. Continuer ?');"
                  style="font-size:0.85rem;">
            Générer un nouveau secret maintenant
          </button>
          <label style="display:flex;align-items:center;gap:0.4rem;font-weight:normal;font-size:0.85rem;">
            <input type="checkbox" name="webhook_secret_clear" value="1">
            Effacer le secret enregistré au prochain « Enregistrer »
          </label>
        </div>
      <?php else: ?>
        <input type="password" name="webhook_secret" value=""
               placeholder="Coller un secret existant — ou cliquer le bouton ci-dessous pour en générer un">
        <div style="margin-top:0.5rem;">
          <button type="submit" name="webhook_action" value="regen_secret"
                  class="btn btn-secondary"
                  formnovalidate
                  style="font-size:0.85rem;">
            Générer un secret aléatoire (32 octets)
          </button>
        </div>
      <?php endif; ?>
      <small class="muted" style="display:block;margin-top:0.4rem;">
        Chiffré en DB via libsodium. Validation HMAC SHA-256
        (<code>X-Hub-Signature-256</code>) rejetée par <code>hash_equals</code>
        (timing-safe) avant tout parsing du payload. La valeur générée s'affiche
        une seule fois dans un bandeau en haut de page — copiez-la immédiatement
        dans GitHub, elle n'est plus jamais visible ensuite.
      </small>
    </label>
  </fieldset>

  <div class="actions" style="margin-top:1rem;">
    <a href="/settings" class="btn btn-secondary">Retour</a>
    <button type="submit" class="btn btn-primary">Enregistrer</button>
  </div>
</form>

<script>
(function(){
  const chk = document.getElementById('ansible-enabled');
  const fs  = document.getElementById('ansible-fields');
  const gh  = document.getElementById('github-push-fields');
  chk.addEventListener('change', () => {
    fs.style.display = chk.checked ? '' : 'none';
    if (gh) gh.style.display = chk.checked ? '' : 'none';
  });
})();
</script>
