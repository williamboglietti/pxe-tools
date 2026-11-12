<?php
/**
 * View : sélection d'un modèle source pour duplication.
 *
 * @var array<int,array<string,mixed>> $presets
 */
use Admin\View;
?>

<header class="row-flex-spread mb-1">
  <h2>Dupliquer un modèle</h2>
  <a href="/presets" class="btn btn-secondary">← Retour</a>
</header>

<article>
  <p class="muted">
    Choisissez un modèle source. Le nouveau modèle héritera de tous ses paramètres,
    y compris l'inventaire matériel lié si présent. Vous ajusterez avant d'enregistrer.
  </p>

  <?php if (empty($presets)): ?>
    <p>Aucun modèle disponible à dupliquer.</p>
  <?php else: ?>
    <form method="get" action="/presets/duplicate" class="grid" style="align-items:end;">
      <label>
        Modèle source
        <select name="source_id" required>
          <?php foreach ($presets as $pp): ?>
            <option value="<?= (int)$pp['id'] ?>">
              <?= View::e($pp['name']) ?> (<?= View::e($pp['distro']) ?><?= !empty($pp['based_on_machine_id']) ? ', inventaire lié' : '' ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary" style="min-height:2.5rem;">Dupliquer</button>
    </form>
  <?php endif; ?>
</article>
