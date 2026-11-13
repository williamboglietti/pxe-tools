<?php
/**
 * Partial — Post-install (URLs pubkeys remote).
 *
 * Structuré uniquement : pas de textarea shell libre (sécurité). Voir
 * plan d'extension projet pour le rationale.
 *
 * Lignes add/remove : index numérique unique par ligne (compteur monotone JS,
 * jamais réutilisé → pas de collision après suppression). Le parseur serveur
 * fait array_values() (cf. Preset::parseAdvancedConfig) → index non-contigus OK.
 *
 * @var array $advPostInstall  section 'post_install' (remote_pubkey_urls[])
 */
use Admin\View;
$urls = $advPostInstall['remote_pubkey_urls'] ?? [];
if (empty($urls)) $urls = [['url' => '', 'label' => '']];

// Rendu d'une ligne (réutilisé pour les lignes existantes ET le <template> JS,
// avec $i = '__IDX__' que le JS remplace par le compteur). $i : int|string.
$urlRow = function ($i, array $row = []): string {
    ob_start(); ?>
  <div class="repeat-row pubkey-url-row">
    <div class="form-row form-row--2col">
      <label>URL HTTPS
        <input type="url" name="adv[post_install][remote_pubkey_urls][<?= $i ?>][url]" value="<?= View::e($row['url'] ?? '') ?>" placeholder="https://sshid.io/USER"></label>
      <label>Libellé <span class="muted">(optionnel)</span>
        <input type="text" name="adv[post_install][remote_pubkey_urls][<?= $i ?>][label]" value="<?= View::e($row['label'] ?? '') ?>" placeholder="sshid.io USER"></label>
    </div>
    <button type="button" class="btn-icon-only btn-remove" aria-label="Supprimer cette URL"><?= View::icon('trash') ?></button>
  </div>
<?php return ob_get_clean();
};
?>
<h4 style="margin:0 0 0.5rem 0;">URLs distantes de clés SSH</h4>
<p class="form-hint">Chaque URL renvoyant des clés SSH publiques (une par ligne) sera <code>curl</code>ée et ajoutée à <code>~admin/.ssh/authorized_keys</code> en fin d'install. <strong>HTTPS uniquement</strong>. Ex. : <code>https://sshid.io/USER</code>, <code>https://github.com/USER.keys</code>.</p>
<div id="pubkey-urls-rows">
  <?php foreach ($urls as $i => $row) echo $urlRow((int)$i, $row); ?>
</div>
<template id="pubkey-url-row-tpl"><?= $urlRow('__IDX__') ?></template>
<button type="button" id="pubkey-add" class="btn btn-secondary btn-small">+ Ajouter une URL</button>

<script>
(function () {
  // Lignes répétables : add (compteur monotone, pas de réutilisation d'index) +
  // remove (délégation). Le serveur fait array_values() → gaps tolérés.
  function repeatable(containerId, rowSel, addId, tplId) {
    var c = document.getElementById(containerId);
    var addBtn = document.getElementById(addId);
    var tpl = document.getElementById(tplId);
    if (!c || !addBtn || !tpl) return;
    var next = c.querySelectorAll(rowSel).length;
    addBtn.addEventListener('click', function () {
      // Sources can rebuild these rows; derive a fresh index to avoid collisions.
      c.querySelectorAll('[name]').forEach(function (input) {
        var match = input.name.match(/\[remote_pubkey_urls\]\[(\d+)\]/);
        if (match) next = Math.max(next, Number(match[1]) + 1);
      });
      var html = tpl.innerHTML.replace(/__IDX__/g, String(next++)).trim();
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      if (tmp.firstElementChild) c.appendChild(tmp.firstElementChild);
    });
    c.addEventListener('click', function (e) {
      var btn = e.target.closest('.btn-remove');
      if (!btn) return;
      var row = btn.closest(rowSel);
      if (row) row.remove();
    });
  }
  repeatable('pubkey-urls-rows', '.pubkey-url-row', 'pubkey-add', 'pubkey-url-row-tpl');
})();
</script>
