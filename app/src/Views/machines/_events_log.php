<?php
/**
 * Partial — terminal d'événements machine (polled HTMX 2s).
 *
 * @var array              $machine
 * @var array<int,array>   $events  ordre chronologique : ancien → récent
 */
use Admin\View;
?>
<?php if (empty($events)): ?>
  <pre class="log events-log events-log--empty">(aucun événement enregistré pour cette machine)</pre>
<?php else: ?>
  <pre class="log events-log"><?php foreach ($events as $ev):
    $level = $ev['level'] ?? 'info';
    $ts = $ev['created_at'] ?? '';
    // Format timestamp : "YYYY-MM-DD HH:MM:SS" (court)
    $tsShort = $ts !== '' ? substr($ts, 0, 19) : '';
    ?><span class="events-line events-line--<?= View::e($level) ?>"><span class="events-ts"><?= View::e($tsShort) ?></span> <span class="events-level">[<?= View::e(strtoupper($level)) ?>]</span> <?= View::e($ev['message'] ?? '') ?></span>
<?php endforeach; ?></pre>
<?php endif; ?>
