<?php use Admin\View; use Admin\Models\Machine; ?>
<tbody id="machines-tbody">
<?php foreach($machines as $m):
  $id=(int)$m['id']; $label=Machine::displayLabel($m); $inv=json_decode($m['inventory_json']??'[]',true)?:[];
  $ip=$inv['discovery_ip']??null; $last=$m['last_seen_at']??null;
  $offline=Machine::isOfflineAfterDiscovery($m);
?>
<tr>
  <td class="cell-select"><input type="checkbox" class="row-select" id="machine-select-<?= $id ?>" data-id="<?= $id ?>" data-hostname="<?= View::e($label) ?>" data-status="<?= View::e($m['status']) ?>" aria-label="Sélectionner <?= View::e($label) ?>"></td>
  <td class="fleet-identity"><a href="/machines/<?= $id ?>" class="machine-link"><?= View::e($label) ?></a><span class="table-secondary mono"><?= View::e($m['mac']) ?></span><span class="table-secondary"><?= View::e(Machine::vendorProduct($m)?:'Inventaire matériel absent') ?></span><span class="mobile-observation"><?= View::e($ip?:'Adresse inconnue') ?> · <?= View::e($last?$last.' UTC':'Jamais observée') ?></span></td>
  <td class="cell-address"><span class="mono"><?= View::e($ip?:'Non observée') ?></span><span class="table-secondary">Dernier inventaire reçu</span></td>
  <td class="cell-status"><span class="status-badge <?= View::e(View::statusClass($m['status'])) ?>"><?= View::e(View::statusLabel($m['status'])) ?></span>
    <?php if(!empty($m['current_action_id'])): ?><a class="table-secondary operation-link" href="/tasks/<?= (int)$m['current_action_id'] ?>"><?= View::e(match($m['current_action_type']){'install','install_only','reinstall'=>'Installation','wipe','wipe_only'=>'Effacement','disktest'=>'Test disque','discovery'=>'Inventaire',default=>'Opération'}) ?> #<?= (int)$m['current_action_id'] ?> →</a><?php else: ?><a class="table-secondary operation-link" href="/machines/<?= $id ?>/install">Configurer →</a><?php endif ?>
    <?php if($offline): ?><span class="table-secondary">hors ligne après inventaire</span><?php endif ?>
  </td>
  <td class="cell-last-seen"><time><?= View::e($last?:'Jamais observée') ?></time><?php if($last): ?><span class="table-secondary">UTC · disponibilité non garantie</span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
