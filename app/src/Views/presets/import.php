<?php
/**
 * View : import d'un modèle depuis un fichier JSON (upload) ou un copier-coller.
 * Format attendu : sortie de `run_preset_push_github` (dump direct du row preset).
 */
use Admin\View;
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2>Importer un modèle</h2>
    <p class="muted">Depuis un fichier JSON (export précédent) ou un copier-coller. Si le nom existe déjà, le modèle est mis à jour sur place (les machines qui l'utilisent restent liées).</p>
  </hgroup>
  <a href="/presets" class="btn btn-secondary">← Retour</a>
</header>

<article>
  <form method="post" action="/presets/import" enctype="multipart/form-data">
    <label for="preset_json">Fichier JSON</label>
    <input type="file" id="preset_json" name="preset_json" accept="application/json,.json">
    <small class="muted">Le format attendu est celui produit par le bouton « Sauvegarder sur GitHub » : un fichier JSON du dossier de modèles déclaré par le profil.</small>

    <hr>

    <label for="preset_json_raw">…ou coller le JSON ci-dessous</label>
    <textarea id="preset_json_raw" name="preset_json_raw" rows="14" placeholder='{"name":"board","distro":"trixie", ...}' class="text-mono"></textarea>
    <small class="muted">Si un fichier est fourni, il a la priorité sur ce champ.</small>

    <div class="row-flex-spread mt-1">
      <a href="/presets" class="btn btn-secondary">Annuler</a>
      <button type="submit" class="btn btn-primary">Importer le modèle</button>
    </div>
  </form>
</article>
