<?php
use Admin\View;
$query=['q'=>$q,'status'=>$statusFilter,'group'=>$group,'sort'=>$sort,'per_page'=>$perPage];
$link=static fn(array $changes):string=>'/machines?'.http_build_query(array_replace($query,$changes));
?>
<section id="machines-section" data-poll-scope data-sig="<?= View::e($sig) ?>" hx-get="/machines/list-fragment" hx-trigger="every 5s" hx-swap="outerHTML" hx-include="#fleet-filters">
  <nav class="fleet-metrics" aria-label="Synthèse du parc">
    <?php foreach(['all'=>['total','Parc total'],'attention'=>['attention','À traiter'],'running'=>['running','En cours'],'failed'=>['failed','Échecs']] as $key=>[$stat,$label]): ?>
    <a href="<?= View::e('/machines?'.http_build_query(['group'=>$key])) ?>" class="metric <?= $group===$key?'is-active':'' ?>" <?= $group===$key?'aria-current="true"':'' ?>><span><?= View::e($label) ?></span><strong><?= $stats[$stat] ?></strong><span class="metric-arrow" aria-hidden="true">↗</span></a>
    <?php endforeach ?>
  </nav>
  <header class="section-heading"><div><h2>Parc de machines</h2><p><?= $filtered ?> résultat<?= $filtered>1?'s':'' ?> sur <?= $total ?> · sélection limitée à cette page</p></div>
    <a href="/machines/new" id="btn-new-machine" class="btn btn-primary">+ Ajouter une machine</a>
    <div id="bulk-actions" class="bulk-actions" style="display:none"><button type="button" id="bulk-install-btn" class="btn btn-primary">Vérifier l’installation · <span id="bulk-count">0</span></button><button type="button" id="bulk-delete-btn" class="btn btn-danger">Supprimer · <span id="bulk-count-delete">0</span></button></div>
  </header>
  <?php if(!$machines): ?>
    <div class="card empty-state"><h3><?= $total===0?'Votre parc est prêt à accueillir ses premières machines':'Aucune machine ne correspond à ces filtres' ?></h3><p><?= $total===0?'Démarrez une machine sur le réseau PXE pour recevoir son inventaire, ou ajoutez une fiche manuellement.':'Les autres machines du parc restent disponibles. Modifiez la recherche ou effacez les filtres.' ?></p><a class="btn btn-secondary" href="<?= $total===0?'/machines/new':'/machines' ?>"><?= $total===0?'Ajouter manuellement':'Effacer les filtres' ?></a></div>
  <?php else: ?>
    <form method="post" action="/machines/bulk-delete" id="bulk-delete-form" hidden><div id="bulk-delete-machine-ids"></div></form>
    <div class="card table-card"><table class="machines-table fleet-table"><caption class="sr-only">Machines : identité, dernière observation et suivi opérationnel</caption><thead><tr><th class="cell-select"><input type="checkbox" id="select-all" aria-label="Sélectionner les machines de cette page"></th><th>Machine</th><th>Adresse observée</th><th>État / opération</th><th>Dernière observation</th></tr></thead>
    <?php View::partial('machines/_list_tbody',['machines'=>$machines,'activeTests'=>$activeTests]); ?></table></div>
    <nav class="pagination" aria-label="Pages du parc"><span><?= ($page-1)*$perPage+1 ?>–<?= min($page*$perPage,$filtered) ?> sur <?= $filtered ?></span><div><?php if($page>1): ?><a class="btn btn-secondary" href="<?= View::e($link(['page'=>$page-1])) ?>">← Précédent</a><?php endif ?><span>Page <?= $page ?> / <?= $pages ?></span><?php if($page<$pages): ?><a class="btn btn-secondary" href="<?= View::e($link(['page'=>$page+1])) ?>">Suivant →</a><?php endif ?></div></nav>
  <?php endif ?>
</section>
