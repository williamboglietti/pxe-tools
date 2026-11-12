<?php use Admin\View; ?>
<dialog id="bulk-install-modal" class="bulk-modal" aria-labelledby="bulk-modal-title" aria-describedby="bulk-modal-description" style="width:min(58rem,calc(100vw - 2rem));max-height:90vh;overflow:auto;">
  <form method="post" action="/machines/bulk-install" id="bulk-install-form">
    <h2 id="bulk-modal-title" class="text-lg font-semibold">Installer <span id="bulk-modal-count">0</span> machine(s)</h2>
    <p id="bulk-modal-description">Vérifiez chaque cible avant de programmer l’installation. La copie du modèle remplace le système et les données des disques ciblés.</p>
    <p class="text-sm">Sélection : <span id="bulk-modal-list"></span></p>
    <div id="bulk-modal-warning" class="flash flash-warning" hidden>
      <span id="bulk-modal-warning-count"></span> machine(s) déjà installée(s) : <span id="bulk-modal-warning-list"></span>. Leur système sera remplacé.
    </div>
    <div class="field mb-3">
      <label for="bulk-preset-id" class="field-label">Modèle à copier</label>
      <select name="preset_id" id="bulk-preset-id" required class="select">
        <option value="">Sélectionner un modèle</option>
        <?php foreach ($presets as $preset): ?>
        <option value="<?= (int)$preset['id'] ?>" data-variant="<?= View::e($preset['variant']??'debian') ?>"><?= View::e($preset['name']) ?> · <?= View::e($preset['distro']) ?></option>
        <?php endforeach; ?>
      </select>
      <small class="field-hint">Chaque machine reçoit un instantané indépendant. Les cibles à adapter se configurent depuis leur fiche.</small>
    </div>
    <fieldset class="mb-4">
      <legend class="field-label">Quand démarrer ?</legend>
      <label><input type="radio" name="wake_now" value="0" checked> Maintenant — redémarrage demandé via l’appliance</label>
      <label><input type="radio" name="wake_now" value="1"> Au prochain démarrage PXE — aucun redémarrage automatique</label>
    </fieldset>
    <div id="bulk-modal-machine-ids"></div>
    <div id="bulk-preflight-fingerprints"></div>
    <p id="bulk-preflight-status" role="status" aria-live="polite">Le contrôle préalable ne déclenche aucune opération.</p>
    <div id="bulk-preflight-results" aria-label="Contrôle par machine"></div>
    <div class="flex items-center justify-end gap-2 mt-4 flex-wrap">
      <button type="button" class="btn btn-ghost" id="bulk-modal-cancel">Fermer</button>
      <button type="button" class="btn btn-secondary" id="bulk-preflight-btn">Contrôler les cibles</button>
      <button type="submit" class="btn btn-primary" id="bulk-launch-btn" disabled>Programmer les cibles prêtes</button>
    </div>
  </form>
</dialog>
