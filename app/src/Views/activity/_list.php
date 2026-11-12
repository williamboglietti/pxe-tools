<?php use Admin\View; use Admin\InitGuard; ?>
<section id="activity-list" data-poll-scope data-sig="<?= View::e($sig) ?>" hx-get="/activity/list" hx-include="#activity-filters" hx-trigger="every 5s" hx-swap="outerHTML">
<header class="section-heading"><h2><?= $total ?> opération<?= $total>1?'s':'' ?></h2><span>Dates en UTC</span></header>
<?php if(!$items): ?><div class="card empty-state"><h3>Aucune opération à afficher</h3><p>Les résultats des actions machine et des jobs de l’appliance apparaîtront ici.</p><a href="/activity">Effacer les filtres</a></div><?php else: ?>
<div class="activity-list">
<?php foreach($items as $item): $label=match($item['type']){'install','install_only','reinstall'=>'Installation','wipe','wipe_only'=>'Effacement des disques','disktest'=>'Test des disques','discovery'=>'Inventaire',default=>InitGuard::JOB_TITLES[$item['type']]??$item['type']}; ?>
<article class="activity-row" id="activity-<?= View::e($item['source']) ?>-<?= (int)$item['id'] ?>">
  <div class="activity-identity"><a href="<?= View::e($item['url']) ?>" class="machine-link"><?= View::e($label) ?> <span class="muted">#<?= (int)$item['id'] ?></span></a><span class="table-secondary"><?php if($item['machine_id']): ?><a href="/machines/<?= (int)$item['machine_id'] ?>"><?= View::e($item['target']) ?></a><?php else: ?>Appliance<?php endif ?> · <?= View::e($item['author']?:'Auteur non renseigné') ?></span></div>
  <div><span class="status-badge <?= View::e(View::statusClass($item['status'])) ?>"><?= View::e(match($item['status']){'pending'=>'En attente','running'=>'En cours','success'=>'Réussie','failed'=>'Échec','skipped'=>'Ignorée','cancelled','aborted'=>'Clôturée',default=>$item['status']}) ?></span><p class="activity-message"><?= View::e($item['message']?:'Ouvrir le suivi pour consulter les étapes et les journaux.') ?></p></div>
  <div class="activity-date"><time><?= View::e($item['updated_at']) ?></time><a href="<?= View::e($item['url']) ?>" class="operation-link" aria-label="Ouvrir <?= View::e($label.' pour '.$item['target']) ?>">Voir le suivi →</a></div>
</article>
<?php endforeach ?>
</div>
<?php $query=compact('q','status','type','machine'); ?><nav class="pagination" aria-label="Pages de l’activité"><span>Page <?= $page ?> / <?= $pages ?></span><div><?php if($page>1): ?><a href="/activity?<?= View::e(http_build_query($query+['page'=>$page-1])) ?>" class="btn btn-secondary">← Précédent</a><?php endif ?><?php if($page<$pages): ?><a href="/activity?<?= View::e(http_build_query($query+['page'=>$page+1])) ?>" class="btn btn-secondary">Suivant →</a><?php endif ?></div></nav>
<?php endif ?>
</section>
