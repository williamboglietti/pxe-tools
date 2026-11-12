<?php
/**
 * Partial — Localisation et clavier (preseed Debian étendu).
 *
 * @var array $adv  section 'localization' d'advanced_config_json (defaults appliqués)
 */
use Admin\View;
$loc = $adv;
?>
<div class="form-row form-row--2col">
  <label>
    Locale principal
    <input type="text" name="adv[localization][locale]" value="<?= View::e($loc['locale'] ?? 'fr_FR.UTF-8') ?>" placeholder="fr_FR.UTF-8">
    <small class="form-hint">Locale POSIX utilisée pour l'installation et le système.</small>
  </label>
  <label>
    Disposition clavier
    <input type="text" name="adv[localization][keymap]" value="<?= View::e($loc['keymap'] ?? 'fr(latin9)') ?>" placeholder="fr(latin9)">
    <small class="form-hint">Format <code>xkb-keymap</code> (ex. <code>fr</code>, <code>fr(latin9)</code>, <code>us</code>).</small>
  </label>
</div>
<div class="form-row form-row--3col">
  <label>
    Langue d'installation <span class="muted">(optionnel)</span>
    <input type="text" name="adv[localization][language]" value="<?= View::e($loc['language'] ?? '') ?>" placeholder="fr">
  </label>
  <label>
    Pays <span class="muted">(optionnel)</span>
    <input type="text" name="adv[localization][country]" value="<?= View::e($loc['country'] ?? '') ?>" placeholder="FR">
  </label>
  <label>
    Toggle clavier <span class="muted">(optionnel)</span>
    <input type="text" name="adv[localization][keymap_toggle]" value="<?= View::e($loc['keymap_toggle'] ?? '') ?>" placeholder="No toggling">
  </label>
</div>
<label>
  Locales supplémentaires <span class="muted">(CSV)</span>
  <input type="text" name="adv[localization][supported_locales]" value="<?= View::e(implode(',', $loc['supported_locales'] ?? [])) ?>" placeholder="fr_FR.UTF-8,en_US.UTF-8">
  <small class="form-hint">Champ <code>localechooser/supported-locales</code>. Vide = aucune locale supplémentaire générée.</small>
</label>
