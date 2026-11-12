<?php
/**
 * View : page de suivi d'un init_job (pull repo, redéploiement Ansible).
 * Le body est rechargé toutes les 2-5s via HTMX (partial _log.php) tant que
 * le job est en cours. Une fois terminal (success/failed), le polling stoppe.
 *
 * @var array<string,mixed> $job
 * @var array<string,mixed>|null $machine
 * @var string $title
 */
use Admin\View;

// Fallback href si history.back() ne fonctionne pas (tab fraîche, URL directe).
$backHref = $machine ? '/machines/' . (int)$machine['id'] : '/activity';
?>

<header class="row-flex-spread mb-1">
  <h2><?= View::e($title) ?></h2>
  <a href="<?= View::e($backHref) ?>" class="btn btn-secondary"
>← Retour</a>
</header>

<p class="freshness" data-freshness role="status">Dernière actualisation : <?= gmdate('H:i:s') ?> UTC</p>
<article class="card">
  <p class="muted text-small" style="margin-bottom:1rem;">
    Type de job : <code><?= View::e((string)$job['job_type']) ?></code>
    · ID #<?= (int)$job['id'] ?>
    <?php if (!empty($job['started_at'])): ?>
      · démarré <?= View::e((string)$job['started_at']) ?>
    <?php endif; ?>
  </p>

  <?php View::partial('jobs/_log', ['job' => $job, 'machine' => $machine]); ?>
</article>

<script>
// Scroll preservation pendant les swaps HTMX :
//   - Si l'utilisateur est au bottom (tolerance 10px), on stick au bottom
//     (UX "live tail" : suit les nouvelles lignes au fur et à mesure).
//   - Si l'utilisateur a scrollé vers le haut pour lire, on préserve sa
//     position pour qu'il puisse continuer sa lecture sans être ramené ailleurs.
(function () {
  let wasAtBottom = true;
  let savedScroll = 0;
  const SCROLL_BOTTOM_THRESHOLD_PX = 10;

  document.body.addEventListener('htmx:beforeSwap', (e) => {
    if (e.target.id !== 'job-log-wrapper') return;
    const pre = e.target.querySelector('.job-log');
    if (!pre) return;
    wasAtBottom = (pre.scrollTop + pre.clientHeight) >= (pre.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX);
    savedScroll = pre.scrollTop;
  });

  document.body.addEventListener('htmx:afterSwap', (e) => {
    if (e.target.id !== 'job-log-wrapper') return;
    const pre = e.target.querySelector('.job-log');
    if (!pre) return;
    pre.scrollTop = wasAtBottom ? pre.scrollHeight : savedScroll;
  });
})();
</script>
