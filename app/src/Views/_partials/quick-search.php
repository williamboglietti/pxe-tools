<?php use Admin\View; ?>
<dialog id="quick-search" class="quick-search" aria-labelledby="quick-search-title">
  <header><h2 id="quick-search-title">Recherche rapide</h2><button type="button" class="btn-icon-only" data-search-close aria-label="Fermer la recherche"><?= View::icon('x') ?></button></header>
  <form action="/machines" method="get" role="search">
    <label class="sr-only" for="quick-search-input">Page, nom de machine, adresse IP ou MAC</label>
    <input type="search" id="quick-search-input" name="q" autocomplete="off" placeholder="Page, machine, adresse IP ou MAC…">
    <button class="quick-search-machine" type="submit">Rechercher dans les machines <?= View::icon('search') ?></button>
  </form>
  <nav aria-label="Accès aux pages" data-search-results>
    <?php foreach ($navigation as $item): ?>
    <a href="<?= $item['href'] ?>" data-search-entry="<?= View::e($item['label']) ?>"><?= View::icon($item['icon']) ?><span><?= View::e($item['label']) ?></span></a>
    <?php foreach ($item['children'] as [$href, $label]): if ($href === $item['href']) continue; ?>
    <a href="<?= $href ?>" data-search-entry="<?= View::e($item['label'] . ' ' . $label) ?>"><span class="search-route-icon" aria-hidden="true">↳</span><span><?= View::e($label) ?></span><small><?= View::e($item['label']) ?></small></a>
    <?php endforeach; endforeach; ?>
  </nav>
  <p class="quick-search-help">Accès aux pages et recherche de machines · <kbd>Échap</kbd> pour fermer</p>
</dialog>
