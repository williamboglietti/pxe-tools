<?php use Admin\View; ?>
<header class="page-heading">
  <div><p class="eyebrow">Exploitation</p><h1>Machines</h1><p class="page-intro">Préparer les installations et retrouver les opérations du parc.</p></div>
  <a href="/activity" class="btn btn-secondary"><?= View::icon('zap') ?> Toute l’activité</a>
</header>
<form method="get" action="/machines" id="fleet-filters" class="filter-bar" role="search">
  <div class="field search-field"><label for="fleet-search">Rechercher une machine</label><input type="search" id="fleet-search" name="q" value="<?= View::e($q) ?>" placeholder="Nom d’hôte, adresse IP ou MAC" autocomplete="off"></div>
  <details class="filter-options" open><summary>Filtres et tri · <?= View::e($statusFilter === 'all' ? 'tous les états' : View::statusLabel($statusFilter)) ?></summary><div class="filter-options-fields">
  <div class="field"><label for="status-filter">État du suivi</label><select name="status" id="status-filter"><option value="all">Tous les états</option><?php foreach($statuses as $s): ?><option value="<?= View::e($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= View::e(View::statusLabel($s)) ?></option><?php endforeach ?></select></div>
  <div class="field"><label for="fleet-sort">Trier par</label><select name="sort" id="fleet-sort"><?php foreach(['name'=>'Nom de la machine','recent'=>'Dernière modification','status'=>'État du suivi'] as $value=>$label): ?><option value="<?= $value ?>" <?= $sort===$value?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></div>
  </div></details>
  <input type="hidden" name="per_page" value="<?= $perPage ?>"><input type="hidden" name="group" value="<?= View::e($group) ?>"><input type="hidden" name="page" value="<?= $page ?>">
  <button class="btn btn-primary" type="submit">Rechercher</button><a class="btn btn-ghost" href="/machines">Réinitialiser</a>
</form>
<p class="freshness" id="fleet-freshness" data-freshness role="status">Dernière actualisation : <time><?= gmdate('H:i:s') ?> UTC</time></p>
<?php View::partial('machines/_section',compact('machines','sig','activeTests','stats','total','filtered','page','pages','perPage','q','statusFilter','group','sort')); ?>
<?php View::partial('machines/_bulk_dialog',['presets'=>$presets]); ?>
<script src="/bulk-install.js?v=<?= filemtime(__DIR__.'/../../../public/bulk-install.js') ?>" defer></script>
