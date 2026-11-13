<?php
use Admin\View;
use Admin\Models\ActionCheckpoints;
use Admin\Models\MachineEvent;
use Admin\Support\Format;

/**
 * View partagée : rendu d'une action parente (mode action) ou d'un sous-job legacy (mode task).
 *
 * Mode action  : $action non-null, $subjobs tableau, $task null
 * Mode task    : $task non-null, $type string, $action null, $subjobs []
 */
/** @var array|null      $action   Row actions (token retiré par le controller — SEC-🟡#1) */
/** @var array|null      $task     Row sous-job (mode task legacy) */
/** @var array           $machine  Row machines */
/** @var string          $type     Type de tâche (mode task) ou type de l'action */
/** @var array           $subjobs  Sous-jobs groupés par type {wipes,installations,...} (mode action) */
/** @var array           $events   Events machine_events (mode task discovery) */
/** @var int|null        $pollInterval Intervalle polling HTMX calculé côté PHP (2/10/null) */

$isActionMode = !empty($action);

// Helpers
$badge = static function (string $status): string {
    return match ($status) {
        'success'     => '<span class="badge-success">' . View::icon('circle-check') . ' succès</span>',
        'running'     => '<span class="badge-info">'    . View::icon('circle')       . ' en cours</span>',
        'failed'      => '<span class="badge-danger">'  . View::icon('circle-x')     . ' échec</span>',
        'pending'     => '<span class="badge-neutral">' . View::icon('circle')       . ' en attente</span>',
        'in_progress' => '<span class="badge-info">'    . View::icon('circle')       . ' en cours</span>',
        default       => '<span class="badge-neutral">' . View::e($status)           . '</span>',
    };
};

$typeLabel = static function (string $t): string {
    return match ($t) {
        'reinstall'    => 'Réinstallation',
        'install_only' => 'Installation seule',
        'wipe_only'    => 'Effacement seul',
        'install'      => 'Installation',
        'wipe'         => 'Effacement disques',
        'discovery'    => 'Discovery',
        'disktest'     => 'Tests de disques',
        default        => ucfirst($t),
    };
};

if ($isActionMode) {
    // =========================================================================
    // Mode action parente — nouvelle UI checklist progressive
    // =========================================================================
    $actionId     = (int)$action['id'];
    $actionStatus = (string)($action['status'] ?? 'pending');
    $actionType   = (string)($action['type']   ?? 'unknown');
    // Catalogue de checkpoints pour ce type d'action
    $catalog = ActionCheckpoints::forType($actionType);

    // Events machine liés à cette action (triés ASC pour dedup + état ⟳)
    $events = MachineEvent::forAction($actionId);

    // -------------------------------------------------------------------
    // Calcul du poll_interval côté PHP (spec §Polling adaptatif)
    // -------------------------------------------------------------------
    if (in_array($actionStatus, \Admin\Models\Action::STATUSES_TERMINAL, true)) {
        // Terminal → pas de polling
        $pollInterval = null;
    } elseif ($actionStatus === 'running') {
        // Idle si aucun event depuis 30s mais status running
        $latestEventTs = null;
        foreach ($events as $ev) {
            $ts = strtotime($ev['created_at'] ?? '');
            if ($ts && ($latestEventTs === null || $ts > $latestEventTs)) {
                $latestEventTs = $ts;
            }
        }
        $idleSince = $latestEventTs ? (time() - $latestEventTs) : PHP_INT_MAX;
        $pollInterval = $idleSince > 30 ? 10 : 2;
    } else {
        // pending → polling lent (10s)
        $pollInterval = 10;
    }
    ?>
    <header class="row-flex-spread mb-1">
      <hgroup>
        <h2><?= View::icon(match($actionType) {
            'reinstall','install_only' => 'package',
            'wipe_only'               => 'trash',
            'discovery'               => 'search',
            'disktest'                => 'hard-drive',
            default                   => 'circle',
        }) ?> <?= View::e($typeLabel($actionType)) ?> #<?= $actionId ?></h2>
        <p class="text-mono"><?= View::e($machine['mac'] ?? '') ?> · <?= View::e($machine['hostname'] ?? 'non assigné') ?></p>
      </hgroup>
      <a href="/machines/<?= (int)$machine['id'] ?>" class="btn btn-secondary">← Retour fiche machine</a>
    </header>

    <div hx-get="/machines/<?= (int)$machine['id'] ?>/boot-fragment"
         hx-trigger="every 5s" hx-swap="innerHTML">
      <?php View::partial('machines/_boot_history', ['machine' => $machine]); ?>
    </div>

    <?php
      // Page UNIQUE des tests de disque (fusion de l'ancienne /disktests/{id},
      // qui redirige désormais ici) : suivi par disque + résultats + journal
      // (_log auto-pollé, inclut _progress quand actif) + contrôles en cours de
      // test (annuler, changer l'action de fin).
      $dtSub = null;
      foreach (($subjobs['disk_tests'] ?? []) as $dtRow) { $dtSub = $dtRow; }
      if ($actionType === 'disktest' && $dtSub !== null):
        $dtSubActive = in_array((string)($dtSub['job_status'] ?? ''), ['pending', 'running'], true);
        $dtMachineId = (int)($dtSub['machine_id'] ?? $machine['id'] ?? 0);
    ?>
    <?php if ($dtSubActive): ?>
    <article>
      <header class="row-flex-spread"><strong>Pendant le test</strong></header>
      <div class="flex items-center justify-between gap-4 flex-wrap">
        <form method="post" action="/machines/<?= $dtMachineId ?>/disktest-post-action"
              class="flex items-center gap-2 flex-wrap" style="margin:0;">
          <label class="text-small" for="dt-post-action" style="margin:0;">Action à la fin du test :</label>
          <select name="post_action" id="dt-post-action" class="select" style="width:auto; margin:0;">
            <?php if ((int)($dtSub['in_session'] ?? 0) === 1): ?>
              <option value="wait"<?= ($dtSub['post_action'] ?? '') === 'wait' ? ' selected' : '' ?>>Retour à l'écran d'attente</option>
            <?php endif; ?>
            <option value="reboot"<?= ($dtSub['post_action'] ?? '') === 'reboot' ? ' selected' : '' ?>>Redémarrer</option>
            <option value="poweroff"<?= ($dtSub['post_action'] ?? '') === 'poweroff' ? ' selected' : '' ?>>Éteindre</option>
          </select>
          <button type="submit" class="btn btn-secondary" style="width:auto;">Appliquer</button>
        </form>
        <form method="post" action="/machines/<?= $dtMachineId ?>/disktest-cancel" style="margin:0;"
              onsubmit="return confirm('Annuler le test en cours ?\n\nLa machine arrête le test dans les secondes qui viennent<?= (int)($dtSub['in_session'] ?? 0) === 1 ? ' et revient à l\'écran d\'attente' : ' puis s\'éteint' ?>.');">
          <button type="submit" class="btn btn-danger" style="width:auto;">Annuler le test</button>
        </form>
      </div>
      <small class="muted text-small" style="display:block; margin-top:.6rem;">
        Astuce : vous pouvez configurer l'installation de cette machine dès maintenant
        (fiche machine → Installer) — elle démarrera automatiquement si les tests
        réussissent, et sera annulée s'ils détectent des défauts.
      </small>
    </article>
    <?php endif; ?>
    <article>
      <?php View::partial('disktests/_log', ['test' => $dtSub]); ?>
    </article>
    <?php endif; ?>

    <article>
      <?php /* Checklist progressive avec polling HTMX adaptatif */
            View::partial('tasks/_checklist', [
                'action'       => $action,
                'machine'      => $machine,
                'catalog'      => $catalog,
                'events'       => $events,
                'subjobs'      => $subjobs,
                'pollInterval' => $pollInterval,
            ]);
      ?>
    </article>

    <?php
} else {
    // =========================================================================
    // Mode task legacy (sous-job isolé, pas d'action parente)
    // =========================================================================

    // Cascade : discovery > ansible > install > wipe > job_status
    // En mode task legacy, un seul champ est renseigné (task mono-type) —
    // l'ordre garantit le bon fallback si plusieurs champs co-existent.
    $status   = (string)($task['discovery_status'] ?? $task['ansible_status'] ?? $task['install_status'] ?? $task['wipe_status'] ?? $task['job_status'] ?? 'unknown');
    $started  = $task['started_at'] ?? null;
    $ended    = $task['ended_at']   ?? null;
    $duration = ($started && $ended) ? max(0, strtotime($ended) - strtotime($started)) : null;

    $typeIcon = match ($type) {
        'discovery' => View::icon('search'),
        'install'   => View::icon('package'),
        'wipe'      => View::icon('trash'),
        'disktest'  => View::icon('hard-drive'),
        default     => '',
    };
    $typeText  = $typeLabel($type);
    $typeHtml  = trim($typeIcon . ' ' . View::e($typeText));
    ?>

    <header class="row-flex-spread mb-1">
      <hgroup>
        <h2><?= $typeHtml ?> #<?= (int)$task['id'] ?></h2>
        <p class="text-mono"><?= View::e($machine['mac'] ?? '') ?> · <?= View::e($machine['hostname'] ?? 'non assigné') ?></p>
      </hgroup>
      <a href="/machines/<?= (int)$machine['id'] ?>" class="btn btn-secondary">← Retour fiche machine</a>
    </header>

    <article>
      <header>
        <div class="grid">
          <div><strong>Statut</strong><br><?= $badge($status) ?></div>
          <div><strong>Démarré</strong><br>
            <?= $started ? View::e(View::frenchDateTime($started)) : '<span class="muted">—</span>' ?>
          </div>
          <div><strong>Terminé</strong><br>
            <?= $ended ? View::e(View::frenchDateTime($ended)) : '<span class="muted">—</span>' ?>
          </div>
          <div><strong>Durée</strong><br><?= View::e(Format::humanDuration($duration)) ?></div>
        </div>
      </header>

      <?php View::partial('tasks/_' . $type, [
          'machine' => $machine,
          'task'    => $task,
          'events'  => $events,
      ]); ?>
    </article>
    <?php
}
?>

<script>
// Continuité de l'animation des spinners checkpoint au travers du polling HTMX.
// POURQUOI : `hx-swap` remplace les nodes DOM (incl. <svg class="checkpoint-spinner">)
// à chaque tick, ce qui réinitialise l'animation CSS `pxe-spin` à 0deg.
// L'œil voit le spinner "sauter" toutes les 5s au lieu de tourner en continu.
// FIX : sur load + après chaque swap, on pose un animation-delay négatif basé
// sur une horloge globale (performance.now() % 1500ms = durée du keyframe),
// donnant l'illusion que l'animation n'a jamais été interrompue.
(function () {
    var SPIN_MS = 1500; // doit matcher .checkpoint-spinner animation: pxe-spin 1.5s
    function syncSpinners() {
        var offset = -((performance.now() % SPIN_MS) / 1000).toFixed(3) + 's';
        document.querySelectorAll('.checkpoint-spinner').forEach(function (svg) {
            svg.style.animationDelay = offset;
        });
    }
    document.addEventListener('DOMContentLoaded', syncSpinners);
    document.body.addEventListener('htmx:afterSwap', syncSpinners);

    // Préserve l'état open des <details> "Logs bruts détaillés" à travers les
    // swaps HTMX. Sans ça, chaque polling (every 3-5s) replace le DOM et
    // referme le menu que l'admin venait d'ouvrir. On stocke l'état dans
    // localStorage avec une clé par id, restauré au load + à chaque swap.
    function restoreDetailsState() {
        document.querySelectorAll('details[id]').forEach(function (d) {
            var key = 'pxe-details-open:' + d.id;
            if (localStorage.getItem(key) === '1') {
                d.open = true;
            }
        });
    }
    function bindDetailsToggle() {
        document.querySelectorAll('details[id]').forEach(function (d) {
            if (d.dataset.bound) return;
            d.dataset.bound = '1';
            d.addEventListener('toggle', function () {
                localStorage.setItem('pxe-details-open:' + d.id, d.open ? '1' : '0');
            });
        });
    }
    document.addEventListener('DOMContentLoaded', function () { restoreDetailsState(); bindDetailsToggle(); });
    document.body.addEventListener('htmx:afterSwap', function () { restoreDetailsState(); bindDetailsToggle(); });

    // Matching par index ordinal dans le DOM — ordre : wipe.log (si dispo) → install.log (si dispo) → ansible.log (si dispo). INSTABILITÉ : si install.log apparaît mid-install après qu'ansible.log existait déjà, les indices shifts d'1. Mitigation : pattern atBottom 30px (cas tail-f normal : admin reste en bas, pas de désync visible).
    var logScrollState = [];
    document.body.addEventListener('htmx:beforeSwap', function () {
        logScrollState = [];
        document.querySelectorAll('pre.log').forEach(function (pre) {
            var atBottom = (pre.scrollHeight - pre.clientHeight - pre.scrollTop) < 30;
            logScrollState.push({ scrollTop: pre.scrollTop, atBottom: atBottom });
        });
    });
    function restoreLogScroll() {
        document.querySelectorAll('pre.log').forEach(function (pre, i) {
            var s = logScrollState[i];
            if (!s) return;
            pre.scrollTop = s.atBottom ? pre.scrollHeight : s.scrollTop;
        });
    }
    document.body.addEventListener('htmx:afterSwap', restoreLogScroll);
})();
</script>
