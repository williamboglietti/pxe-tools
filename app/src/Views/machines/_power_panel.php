<?php
use Admin\View;
/**
 * Partial — Panel "Contrôle alimentation à distance" pour machines/show.php.
 * Servi aussi en fragment HTMX (GET /machines/{id}/power-fragment) pour
 * polling 5s qui matérialise l'action consommée par la machine.
 *
 * @var array $machine
 */

// Visible seulement pendant la fenêtre d'attente active, quel que soit le
// statut métier (`discovered`, `tested`, `failed` après un test de disques).
// Une fois la deadline expirée, l'agent reçoit poweroff ou s'éteint localement.
$pvAwaitRaw    = $machine['awaiting_action_until'] ?? null;
$pvAwaitActive = $pvAwaitRaw && strtotime((string)$pvAwaitRaw . ' UTC') > time();
$powerVisible  = $pvAwaitActive;
if (!$powerVisible) {
    // Fenêtre fermée : retirer le panel au prochain poll HTMX.
    // Renvoyer un fragment vide pour que HTMX swap retire le panel proprement.
    return;
}

$nextAction = $machine['next_action'] ?? null;
$nextActionLabel = match ($nextAction) {
    'poweroff' => 'Extinction',
    'reboot'   => 'Redémarrage',
    default    => null,
};

// Calcul de la deadline d'auto-poweroff serveur-side (timestamp epoch).
// awaiting_action_until est en UTC, on l'expose tel quel au JS qui fera la
// diff vs Date.now() local du browser (browser horloge fiable).
$awaitingUntil = $machine['awaiting_action_until'] ?? null;
$awaitingEpoch = $awaitingUntil ? strtotime((string)$awaitingUntil . ' UTC') : 0;
$remainingSec  = $awaitingEpoch > 0 ? max(0, $awaitingEpoch - time()) : 0;
?>
<article class="panel">
  <header><h3>Contrôle alimentation à distance</h3></header>
  <p class="muted">
    La machine reste allumée en attente d'instruction (après l'inventaire ou un
    test de disques). Utilisez le menu <strong>Actions → Redémarrer / Arrêter</strong> —
    l'ordre prend effet en ≤ 5 secondes.
  </p>
  <?php if ($nextActionLabel !== null): ?>
    <p>Action programmée : <strong><?= View::e($nextActionLabel) ?></strong></p>
  <?php endif; ?>
  <?php if ($awaitingEpoch > 0 && $nextActionLabel === null): ?>
    <p style="display:flex; align-items:center; gap:.4rem; margin:.5rem 0 0 0;">
      <?= View::icon('clock') ?>
      <span>
        Auto-poweroff dans
        <strong id="power-countdown"
                data-expires-epoch="<?= $awaitingEpoch ?>"
                style="font-variant-numeric: tabular-nums;">
          <?= sprintf('%02d:%02d', intdiv($remainingSec, 60), $remainingSec % 60) ?>
        </strong>
      </span>
    </p>
  <?php endif; ?>
  <small class="form-hint">
    Sécurité : si aucune action n'est reçue dans l'heure, la machine s'éteint
    automatiquement.
  </small>
  <?php /* Les boutons d'action vivent dans le menu Actions (point unique,
           routage intelligent attente/SSH) — le panneau n'affiche que l'état
           et permet d'annuler une action programmée non encore consommée. */ ?>
  <?php if ($nextActionLabel !== null): ?>
    <div class="actions mt-3 justify-end">
      <form method="post" action="/machines/<?= (int)$machine['id'] ?>/power" style="margin:0;">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-secondary">Annuler l'action</button>
      </form>
    </div>
  <?php endif; ?>
</article>
<script>
  // Countdown JS : tick toutes les secondes. data-expires-epoch est posé par
  // PHP en UTC absolu — on diff vs Date.now() local du browser (UTC aussi
  // via getTime() / 1000). Pas de drift entre machines/admin/écran CDV.
  (function() {
    var el = document.getElementById('power-countdown');
    if (!el) return;
    var expires = parseInt(el.dataset.expiresEpoch, 10);
    if (!expires) return;
    function tick() {
      var rem = Math.max(0, expires - Math.floor(Date.now() / 1000));
      var m = String(Math.floor(rem / 60)).padStart(2, '0');
      var s = String(rem % 60).padStart(2, '0');
      el.textContent = m + ':' + s;
      // Coloration warning quand < 5 min restants
      if (rem > 0 && rem <= 300) {
        el.style.color = 'var(--color-warning)';
      } else {
        el.style.color = '';
      }
      if (rem === 0) { clearInterval(t); }
    }
    tick();
    var t = setInterval(tick, 1000);
  })();
</script>
