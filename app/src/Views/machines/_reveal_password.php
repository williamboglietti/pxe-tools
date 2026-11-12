<?php
/**
 * Bloc « Mots de passe stockés » de la fiche machine (révélation à la demande).
 *
 * Variables : $machine (ligne machines), $installConfig (install_config_json décodé, ou null).
 *
 * Le fetch est une mutation hors formulaire : le jeton CSRF ne peut pas être auto-injecté
 * (View::injectCsrf ne traite que les <form>), il voyage donc en en-tête (Csrf::HEADER),
 * comme pour HTMX (cf. layout.php).
 */
use Admin\Csrf;
use Admin\View;

$hasAdminCipher = !empty($installConfig['admin_user_password_cipher']);
$hasRootCipher  = !empty($machine['root_password_cipher']);
$hasRootLegacy  = !empty($machine['root_password_encrypted']);
if (!($hasAdminCipher || $hasRootCipher || $hasRootLegacy)) return;
?>
<div class="reveal-pw-block" style="margin-top:.75rem;" data-machine-id="<?= (int)$machine['id'] ?>"
     data-csrf-header="<?= View::e(Csrf::HEADER) ?>" data-csrf-token="<?= View::e(View::csrfToken()) ?>">
  <h5 style="margin:0 0 .5rem 0; font-size:.85rem; font-weight:600;">Mots de passe stockés</h5>
  <p class="muted text-small" style="margin:.25rem 0 .5rem 0;">
    Chiffrés côté serveur (master.key). Accessibles aux administrateurs authentifiés.
  </p>
  <div class="actions" style="display:flex; flex-wrap:wrap; gap:.5rem;">
    <?php if ($hasAdminCipher): ?>
      <button type="button" class="btn btn-secondary reveal-pw-btn" data-type="admin_user">
        <?= View::icon('lock') ?> Révéler le mot de passe utilisateur
      </button>
    <?php endif; ?>
    <?php if ($hasRootCipher || $hasRootLegacy): ?>
      <button type="button" class="btn btn-secondary reveal-pw-btn" data-type="root"
              <?= $hasRootLegacy && !$hasRootCipher ? 'data-legacy="1"' : '' ?>>
        <?= View::icon('lock') ?> Révéler le mot de passe root<?= ($hasRootLegacy && !$hasRootCipher) ? ' (legacy)' : '' ?>
      </button>
    <?php endif; ?>
  </div>
  <div class="reveal-pw-result" hidden style="margin-top:.5rem;">
    <span class="reveal-pw-label muted text-small"></span>
    <code class="reveal-pw-clear" style="user-select:all; margin-left:.4rem;"></code>
    <button type="button" class="btn btn-secondary reveal-pw-copy" style="margin-left:.4rem;">Copier</button>
    <button type="button" class="btn btn-secondary reveal-pw-hide">Masquer</button>
  </div>
  <p class="reveal-pw-error muted" style="color:var(--color-danger); margin-top:.4rem;" hidden></p>
</div>
<script>
(function () {
  const block = document.currentScript.previousElementSibling;
  if (!block || !block.classList.contains('reveal-pw-block')) return;
  const machineId = block.dataset.machineId;
  const result = block.querySelector('.reveal-pw-result');
  const labelEl = block.querySelector('.reveal-pw-label');
  const clearEl = block.querySelector('.reveal-pw-clear');
  const errEl = block.querySelector('.reveal-pw-error');
  const copyBtn = block.querySelector('.reveal-pw-copy');
  const hideBtn = block.querySelector('.reveal-pw-hide');
  const labels = { root: 'Mot de passe root :', admin_user: 'Mot de passe utilisateur :' };

  async function fetchReveal(type, passphrase) {
    const fd = new FormData();
    fd.append('type', type);
    if (passphrase) fd.append('passphrase', passphrase);
    const headers = { Accept: 'application/json' };
    if (block.dataset.csrfToken) headers[block.dataset.csrfHeader] = block.dataset.csrfToken;
    const r = await fetch('/machines/' + machineId + '/reveal-password', { method: 'POST', body: fd, headers });
    // Un rejet (403 CSRF, session expirée) renvoie du HTML : sans ce contrôle, r.json() lève une
    // exception que personne ne voit et le bouton semble simplement ne rien faire.
    if (!(r.headers.get('content-type') || '').includes('application/json')) {
      throw new Error('Session expirée ou réponse inattendue (HTTP ' + r.status + '). Rechargez la page puis réessayez.');
    }
    return r.json();
  }

  block.querySelectorAll('.reveal-pw-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      errEl.hidden = true;
      const type = btn.dataset.type;
      try {
        let j = await fetchReveal(type, null);
        if (j.legacy && !j.password) {
          const pp = prompt('Passphrase de déchiffrement (ancien format) :');
          if (!pp) return;
          j = await fetchReveal(type, pp);
        }
        if (j.password) {
          labelEl.textContent = labels[type] || 'Mot de passe :';
          clearEl.textContent = j.password;
          result.hidden = false;
        } else {
          errEl.textContent = j.error || 'Erreur inconnue';
          errEl.hidden = false;
          result.hidden = true;
        }
      } catch (e) {
        errEl.textContent = e.message || 'Erreur réseau';
        errEl.hidden = false;
        result.hidden = true;
      }
    });
  });
  hideBtn.addEventListener('click', () => { result.hidden = true; clearEl.textContent = ''; });
  copyBtn.addEventListener('click', () => {
    navigator.clipboard?.writeText(clearEl.textContent);
    copyBtn.textContent = 'Copié';
    setTimeout(() => copyBtn.textContent = 'Copier', 1500);
  });
})();
</script>
