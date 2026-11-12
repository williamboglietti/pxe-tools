<?php
use Admin\View;
$diskConstraints = $diskConstraints ?? [];
?>
<input type="hidden" name="disk_target_constraints_json" value="<?= View::e(json_encode($diskConstraints, JSON_UNESCAPED_SLASHES)) ?>">
<p class="form-hint" data-disk-constraints-summary <?= $diskConstraints === [] ? 'hidden' : '' ?>>
  <?php if (is_array($diskConstraints)): ?>
    Disque imposé par le relevé :
    <?php foreach ($diskConstraints as $path => $serial): ?>
      <?= View::e($path) ?> · numéro de série <?= View::e(is_string($serial) ? $serial : 'invalide') ?>.
    <?php endforeach; ?>
    Une série absente ou ambiguë bloque l’installation.
  <?php else: ?>Contrainte disque invalide ; rechargez un modèle vérifié.<?php endif; ?>
</p>
