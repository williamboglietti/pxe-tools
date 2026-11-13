<?php
use Admin\View;
use Admin\Auth;
use Admin\Models\Setting;
use Admin\Ui\Navigation;
$flashes = View::consumeFlashes();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$user = Auth::user();
$username = $user['username'] ?? '';
$ansibleEnabled = Setting::get(Setting::KEY_ANSIBLE_ENABLED, 'off') === 'on';
// Sidebar applicative seulement quand on est connecté ET hors wizard /init
// (le wizard est un flux pré-setup, pleine page, sans nav).
$showSidebar = $username !== '' && (!str_starts_with($path, '/init') || Setting::get('wizard_completed') === '1');
$navigation = Navigation::sections();
$pageContext = Navigation::context($path, $title ?? 'PXE Tools', $data ?? []);
$activeSection = $pageContext['section'];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= View::e($title ?? 'PXE Tools') ?></title>
<!-- Anti-FOUC : pose data-theme AVANT le CSS (choix stocké, sinon préférence système). -->
<script>(function(){var r=document.documentElement,t='system';try{t=localStorage.getItem('theme')||'system';}catch(e){}if(t!=='light'&&t!=='dark')t='system';r.dataset.themeMode=t;r.dataset.theme=t==='system'?(matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'):t;try{r.dataset.sidebarCollapsed=localStorage.getItem('pxe.sidebar.collapsed')==='true'?'true':'false';}catch(e){}})();</script>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="/style.tailwind.css?v=<?= @filemtime(__DIR__ . '/../../public/style.tailwind.css') ?: time() ?>">
<script src="/htmx.min.js" defer></script>
<script src="/toast.js?v=<?= @filemtime(__DIR__ . '/../../public/toast.js') ?: time() ?>" defer></script>
<script src="/form-a11y.js?v=<?= @filemtime(__DIR__ . '/../../public/form-a11y.js') ?: time() ?>" defer></script>
<script src="/ui-state.js?v=<?= @filemtime(__DIR__ . '/../../public/ui-state.js') ?: time() ?>" defer></script>
<script src="/theme.js?v=<?= @filemtime(__DIR__ . '/../../public/theme.js') ?: time() ?>" defer></script>
<script src="/navigation.js?v=<?= @filemtime(__DIR__ . '/../../public/navigation.js') ?: time() ?>" defer></script>
<script src="/quick-search.js?v=<?= @filemtime(__DIR__ . '/../../public/quick-search.js') ?: time() ?>" defer></script>
</head>
<?php /* Header CSRF global pour HTMX (audit F-03) : hérité par tous les hx-* descendants et les fragments swappés. */ ?>
<body<?php if (($__csrf = View::csrfToken()) !== ''): ?> hx-headers='{"<?= View::e(Admin\Csrf::HEADER) ?>": "<?= View::e($__csrf) ?>"}'<?php endif; ?>>
<?php
$flashesHtml = static function() use ($flashes) {
    foreach ($flashes as $f) {
        $_actionUrl   = $f['action_url']   ?? null;
        $_actionLabel = $f['action_label'] ?? null;
        $_jobType     = $f['job_type']     ?? null;
        echo '<article class="flash flash-' . View::e($f['type']) . '"';
        if (!empty($_actionUrl)) {
            echo ' data-action-url="' . View::e($_actionUrl) . '" data-action-label="' . View::e($_actionLabel ?: 'Voir') . '"';
        }
        if (!empty($_jobType)) {
            echo ' data-job-type="' . View::e($_jobType) . '"';
        }
        if (preg_match('~^/jobs/(\d+)$~', (string)$_actionUrl, $jobMatch)) echo ' data-job-id="' . (int)$jobMatch[1] . '"';
        echo ' data-server-flash data-persistent role="' . ($f['type'] === 'error' ? 'alert' : 'status') . '">' . View::e($f['msg']);
        if ($_actionUrl) echo ' <a href="' . View::e($_actionUrl) . '">' . View::e($_actionLabel ?: 'Voir le suivi') . '</a>';
        echo '</article>';
    }
};
?>
<?php if ($showSidebar): ?>
  <a href="#main-content" class="skip-link">Aller au contenu</a>
  <div class="app-shell" id="app-shell" data-sidebar-collapsed="false" data-sidebar-hover="false">
  <aside class="app-sidebar" id="app-sidebar" aria-label="Menu principal">
    <div class="sidebar-header">
      <a href="/machines" class="sidebar-brand" aria-label="PXE Tools · Machines"><span class="sidebar-brand-mini" aria-hidden="true">PXE</span><span class="sidebar-label sidebar-brand-full">PXE Tools <?= View::appVersionBadge() ?></span></a>
      <button class="sidebar-close btn btn-ghost" type="button" data-sidebar-close aria-label="Fermer le menu"><?= View::icon('x') ?></button>
    </div>
    <button type="button" class="sidebar-search sidebar-item" data-search-open aria-label="Recherche rapide"><?= View::icon('search') ?><span class="sidebar-label">Recherche rapide</span><kbd class="sidebar-label">Ctrl K</kbd></button>
    <nav class="sidebar-nav" aria-label="Navigation principale">
      <?php foreach ($navigation as $key => $item): ?>
      <?php if ($item['children'] === []): ?>
      <a href="<?= $item['href'] ?>" class="sidebar-item" aria-label="<?= View::e($item['label']) ?>"<?= $activeSection === $key ? ' aria-current="page"' : '' ?>><?= View::icon($item['icon']) ?><span class="sidebar-label"><?= View::e($item['label']) ?></span></a>
      <?php else: ?>
      <details class="sidebar-group" data-nav-group="<?= $key ?>"<?= $activeSection === $key ? ' open' : '' ?>>
        <summary class="sidebar-item" aria-label="<?= View::e($item['label']) ?>"<?= $activeSection === $key ? ' aria-current="page"' : '' ?>><?= View::icon($item['icon']) ?><span class="sidebar-label"><?= View::e($item['label']) ?></span><span class="sidebar-label sidebar-chevron" aria-hidden="true">⌄</span></summary>
        <div class="sidebar-subnav">
          <?php foreach ($item['children'] as [$href, $label]): ?>
          <a href="<?= $href ?>" class="sidebar-subitem"<?= Navigation::current($path, $href) ? ' aria-current="page"' : '' ?>><?= View::e($label) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <button type="button" class="sidebar-action" data-theme-toggle aria-label="Basculer le thème">
        <?= View::icon('moon', 'theme-icon-moon') ?><?= View::icon('sun', 'theme-icon-sun') ?> <span class="sidebar-label">Apparence</span>
      </button>
      <form method="post" action="/logout">
        <button type="submit" class="sidebar-action" aria-label="Déconnexion"><?= View::icon('log-out') ?><span class="sidebar-label">Déconnexion</span></button>
      </form>
      <button type="button" class="sidebar-action sidebar-collapse" data-sidebar-collapse aria-controls="app-sidebar" aria-expanded="true" aria-label="Replier le panneau" title="Replier le panneau"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg><span class="sidebar-label">Replier le panneau</span></button>
    </div>
  </aside>
  <div class="app-backdrop" id="app-backdrop" data-sidebar-close></div>
  <div class="app-content"<?= preg_match('~^/machines/\d+$~', $path) ? ' data-context-tabs="true"' : '' ?>>
    <header class="app-topbar">
      <button type="button" class="sidebar-burger" data-sidebar-open aria-controls="app-sidebar" aria-expanded="false" aria-label="Ouvrir le menu"><?= View::icon('menu') ?></button>
      <nav class="context-breadcrumbs" aria-label="Contexte">
        <?php foreach ($pageContext['crumbs'] as $index => $crumb): ?>
        <?php if ($index > 0): ?><span class="context-divider" aria-hidden="true">/</span><?php endif; ?>
        <?php if ($index === array_key_last($pageContext['crumbs'])): ?><span aria-current="page"><?= View::e($crumb['label']) ?></span><?php else: ?><a href="<?= View::e($crumb['href']) ?>"><?= View::e($crumb['label']) ?></a><?php endif; ?>
        <?php endforeach; ?>
      </nav>
      <details class="account-menu">
        <summary aria-label="Compte et apparence"><?= View::icon('shield') ?><span><?= View::e($username) ?></span></summary>
        <div class="account-panel"><p><?= View::e($username) ?></p><label for="theme-preference">Apparence</label><select id="theme-preference" data-theme-preference><option value="system">Système</option><option value="light">Clair</option><option value="dark">Sombre</option></select><a href="/settings">Paramètres de l’appliance</a></div>
      </details>
    </header>
    <main class="app-main" id="main-content" tabindex="-1">
      <?php $flashesHtml(); ?>
      <?php View::partial('_partials/pxe-http-warning'); ?>
      <?php $contentRender(); ?>
    </main>
  </div>
  </div>
  <?php View::partial('_partials/quick-search', ['navigation' => $navigation]); ?>
<?php else: ?>
  <main class="app-main app-main--bare" id="main-content" tabindex="-1">
    <?php $flashesHtml(); ?>
    <?php $contentRender(); ?>
  </main>
<?php endif; ?>
</body>
</html>
