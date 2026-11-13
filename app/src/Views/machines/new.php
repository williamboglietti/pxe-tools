<header class="row-flex-spread mb-1">
  <h2>Nouvelle machine (saisie manuelle)</h2>
  <a href="/machines" class="btn btn-secondary">← Retour</a>
</header>

<div class="card">
  <p class="muted">
    Normalement, les machines apparaissent automatiquement après leur premier boot PXE (discovery).
    Cette page permet d'ajouter manuellement une machine si son adresse MAC est déjà connue.
  </p>
  <form method="post" action="/machines">
    <label>
      Adresse MAC <span class="required-marker">*</span>
      <input type="text" name="mac" required placeholder="02:00:00:00:00:01" pattern="[0-9a-fA-F:.\-]+" aria-required="true">
      <small class="form-hint">Formats acceptés : <code>aa:bb:cc:dd:ee:ff</code>, <code>aa-bb-cc-dd-ee-ff</code>, <code>aabbccddeeff</code></small>
    </label>

    <label>
      Nom d'hôte <span class="muted">(optionnel)</span>
      <input type="text" name="hostname" placeholder="hostname">
    </label>

    <div class="actions mt-4 justify-end">
      <button type="submit" class="btn btn-primary">Créer</button>
    </div>
  </form>
</div>
