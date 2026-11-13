<?php
use Admin\View;
use Admin\Models\Action;
use Admin\Models\ActionCheckpoints;
use Admin\Support\Format;

/**
 * Partial checklist progressive par phases (catalogue ActionCheckpoints).
 *
 * Inputs attendus :
 *   $action   — row actions (token déjà retiré par le controller)
 *   $machine  — row machines (pour mac + hostname)
 *   $catalog  — ActionCheckpoints::forType($action['type'])
 *   $events   — machine_events filtrés par action_id, triés created_at ASC
 *   $subjobs  — sous-jobs groupés {wipes, installations, discoveries, disk_tests}
 *   $pollInterval — int|null : 2 / 10 / null (désactivé en terminal)
 *
 * DEDUP : la première occurrence par checkpoint code est conservée (spec §Idempotence).
 * POLLING : géré côté PHP via $pollInterval injecté dans hx-trigger. Pas de JS.
 */

// -------------------------------------------------------------------
// Helpers locaux
// -------------------------------------------------------------------

/**
 * Label dynamique pour un checkpoint en cours (vs. terminé).
 * Le catalog ActionCheckpoints stocke des labels au passé/terminé (ex:
 * "Disques effacés"). Quand le checkpoint est l'étape active (⟳), on
 * affiche une forme au présent progressif (ex: "Effacement des disques")
 * pour ne pas tromper l'utilisateur qui croirait que c'est déjà fait.
 * Si pas de mapping, on garde le label original.
 */
$labelRunning = static function (string $code, string $defaultLabel): string {
    return match ($code) {
        // Codes kind='start' : reçu côté serveur dès que la phase démarre,
        // mais on garde l'UI en "en cours…" jusqu'au checkpoint suivant.
        'wipe_script_started'   => 'Effacement des disques en cours',
        'partitioning_started'  => 'Installation du système en cours',
        'late_command_started'  => 'Finalisation de l\'installation en cours',
        'ansible_started'       => 'Configuration de la machine en cours',
        'disktest_started'      => 'Tests des disques en cours',
        // Codes kind='done' : affichés en "en cours" uniquement quand ils sont
        // le prochain attendu (pas encore reçu).
        'wipe_disks_done'       => 'Effacement des disques en cours',
        'install_reboot_done'   => 'Redémarrage en cours',
        'ansible_finished'      => 'Configuration en cours',
        default                 => $defaultLabel,
    };
};

/**
 * Extrait le dernier pourcentage de progression du log wipe.
 * Format attendu (wipe.sh) : "partial-start (/dev/sda) : 5678 / 10240 MB (55%)".
 * Retourne null si pas de % trouvé.
 */
$extractWipePercent = static function (string $log): ?int {
    if ($log === '') return null;
    // On scanne dans l'ordre inverse pour trouver le dernier % (le plus récent)
    if (preg_match_all('/\((\d{1,3})%\)/', $log, $matches)) {
        $last = end($matches[1]);
        $pct = (int)$last;
        if ($pct >= 0 && $pct <= 100) return $pct;
    }
    return null;
};

$relTime = static function (?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    if ($ts === false) return '—';
    $diff = time() - $ts;
    if ($diff < 5)     return 'à l\'instant';
    if ($diff < 60)    return 'il y a ' . $diff . ' s';
    if ($diff < 3600)  return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . ' h';
    return date('d/m H:i', $ts);
};

$phaseLabelFr = static function (string $phase): string {
    return match ($phase) {
        'wipe'    => 'Effacement',
        'install' => 'Installation',
        'post'    => 'Post-installation',
        'disktest' => 'Tests de disques',
        default   => ucfirst($phase),
    };
};

$typeIconName = static function (string $type): string {
    return match ($type) {
        'reinstall', 'install_only' => 'package',
        'wipe_only'                 => 'trash',
        'discovery'                 => 'search',
        'disktest'                  => 'hard-drive',
        default                     => 'circle',
    };
};

$typeLabelFr = static function (string $type): string {
    return match ($type) {
        'reinstall'    => 'Réinstallation',
        'install_only' => 'Installation seule',
        'wipe_only'    => 'Effacement seul',
        'discovery'    => 'Discovery',
        'disktest'     => 'Tests de disques',
        default        => ucfirst($type),
    };
};

// -------------------------------------------------------------------
// Données de l'action
// -------------------------------------------------------------------

$actionId     = (int)($action['id'] ?? 0);
$actionType   = (string)($action['type'] ?? 'unknown');
$actionStatus = (string)($action['status'] ?? 'pending');
$createdAt    = $action['created_at'] ?? null;
$endedAt      = $action['ended_at']   ?? null;

// Durée totale
$totalDuration = ($createdAt && $endedAt)
    ? max(0, strtotime($endedAt) - strtotime($createdAt))
    : null;

// -------------------------------------------------------------------
// Dedup events : premier ping pour les étapes ordinaires. Les checkpoints
// Ansible appartiennent à une tentative relançable : le dernier ping fait foi.
// Si une nouvelle tentative a commencé, son ancien verdict est ignoré.
// -------------------------------------------------------------------
$eventByCode = [];  // code → row
$latestAnsibleStart = -1;
$latestAnsibleFinish = -1;
foreach ($events as $position => $ev) {
    $code = (string)($ev['checkpoint'] ?? '');
    if ($code === '') continue;
    if ($code === 'ansible_started') $latestAnsibleStart = $position;
    if ($code === 'ansible_finished') $latestAnsibleFinish = $position;
    if (isset($eventByCode[$code]) && !in_array($code, ['ansible_started', 'ansible_finished'], true)) continue;
    $eventByCode[$code] = $ev;
}
if ($latestAnsibleStart > $latestAnsibleFinish) {
    unset($eventByCode['ansible_finished']);
}

// Une installation sans rôle Ansible se termine à la fin du debian-installer.
// Les jalons de reboot et de post-installation ne font pas partie de cette
// action ; leur observation reste dans l'historique de boot de la machine.
$installations = $subjobs['installations'] ?? [];
$latestInstallation = $installations ? end($installations) : null;
$ansibleSkipped = ($latestInstallation['ansible_status'] ?? null) === 'no_role'
    && in_array($actionType, ['install_only', 'reinstall'], true);
// Un ancien verdict d'échec ne peut pas décrire une installation devenue
// réussie après retry, même si le ping final du retry a été perdu.
if ($actionStatus === 'success'
    && ($latestInstallation['ansible_status'] ?? null) === 'success'
    && ($eventByCode['ansible_finished']['level'] ?? null) === 'error') {
    unset($eventByCode['ansible_finished']);
}
if ($ansibleSkipped) {
    $catalog = array_values(array_filter($catalog, static fn(array $entry): bool =>
        $entry['phase'] !== 'post' && $entry['code'] !== 'install_reboot_done'
    ));
}

// -------------------------------------------------------------------
// Un checkpoint plus avancé prouve que les étapes précédentes ont été
// traversées, même si leurs pings n'ont pas été reçus (boot en session,
// DHCP/TFTP non observés ou perte réseau). On déduit cela uniquement pour
// l'affichage : les événements manquants ne sont pas inventés en base.
// -------------------------------------------------------------------
$total       = count($catalog);
$reachedIndex = 0;
$lastDoneLabel = null;
foreach ($catalog as $i => $entry) {
    if (isset($eventByCode[$entry['code']])) {
        $reachedIndex = $i + 1;
        $lastDoneLabel = $entry['label'];
    }
}
// Historiquement wipe-end clôturait l'action avant le dernier ping, qui était
// alors rejeté. Un wipe_only réussi avec "Disques effacés" reçu a néanmoins
// traversé ce point du script. Afficher l'étape finale comme déduite pour les
// actions existantes ; le script corrigé émet désormais le ping avant wipe-end.
$inferredCompletion = $actionStatus === 'success' && $total > 0 && (
    ($actionType === 'wipe_only' && $reachedIndex === $total - 1)
    || ($ansibleSkipped && ($latestInstallation['install_status'] ?? null) === 'success')
    || (in_array($actionType, ['install_only', 'reinstall'], true)
        && ($latestInstallation['ansible_status'] ?? null) === 'success')
);
if ($inferredCompletion) {
    $reachedIndex = $total;
    $lastDoneLabel = $catalog[$total - 1]['label'];
}
$doneCount = $reachedIndex;

// Prochain checkpoint à franchir GLOBALEMENT (pour l'indicateur ⟳ unique).
// IMPORTANT : un seul checkpoint en cours dans TOUTE la timeline, pas un par
// phase. Avant ce fix, chaque phase calculait son propre "next pending" →
// 3 checkpoints marqués ⟳ simultanément (1 par phase Wipe/Install/Post)
// alors que la machine n'en exécute qu'un seul à la fois.
// Mapping code → code suivant dans le catalog (utilisé pour la logique kind='start' :
// une entrée 'start' doit rester en spinner tant que son code suivant n'est pas reçu).
$nextCodeOf = [];
$catalogCodes = array_column($catalog, 'code');
foreach ($catalogCodes as $i => $c) {
    $nextCodeOf[$c] = $catalogCodes[$i + 1] ?? null;
}

$nextPendingCode = $catalog[$reachedIndex]['code'] ?? null;

// -------------------------------------------------------------------
// Numérotation globale des étapes : 1 → count($catalog), continue à
// travers les phases. Permet à l'utilisateur d'identifier sans ambiguïté
// "étape 8" auprès du support, peu importe la phase.
// -------------------------------------------------------------------
$globalIndexByCode = [];
foreach ($catalog as $i => $entry) {
    $globalIndexByCode[$entry['code']] = $i + 1;
}

// -------------------------------------------------------------------
// Durée par étape : event courant - event précédent du catalog reçu.
// Le premier event reçu n'a pas de durée (point de départ → "départ").
// -------------------------------------------------------------------
$durationByCode = [];
$lastTs = null;
foreach ($catalog as $entry) {
    $code = $entry['code'];
    if (!isset($eventByCode[$code])) continue;
    $ts = strtotime((string)($eventByCode[$code]['created_at'] ?? ''));
    if ($ts === false) continue;
    $durationByCode[$code] = $lastTs !== null ? max(0, $ts - $lastTs) : null;
    $lastTs = $ts;
}

// -------------------------------------------------------------------
// Badge statut
// -------------------------------------------------------------------
$badgeClass = match ($actionStatus) {
    'success'   => 'badge-success',
    'running'   => 'badge-info',
    'failed'    => 'badge-danger',
    'pending'   => 'badge-neutral',
    'cancelled' => 'badge-neutral',
    default     => 'badge-neutral',
};
$badgeLabel = match ($actionStatus) {
    'success'   => 'Succès',
    'running'   => 'En cours',
    'failed'    => 'Échec',
    'pending'   => 'En attente',
    'cancelled' => 'Clôturée manuellement',
    default     => View::e($actionStatus),
};

// -------------------------------------------------------------------
// Phases distinctes dans le catalogue (ordre d'apparition)
// -------------------------------------------------------------------
$phases = array_values(array_unique(array_column($catalog, 'phase')));

// Calcul du statut par phase (pour le badge de groupe)
$phaseStatus = [];
foreach ($phases as $phase) {
    $phaseEntries = array_filter($catalog, fn($e) => $e['phase'] === $phase);
    $phaseTotal   = count($phaseEntries);
    $phaseDone    = 0;
    $phaseFailed  = false;
    $phaseTimestamps = [];
    foreach ($phaseEntries as $entry) {
        $entryIndex = array_search($entry['code'], $catalogCodes, true);
        if ($entryIndex !== false && $entryIndex < $reachedIndex) {
            $phaseDone++;
        }
        if (isset($eventByCode[$entry['code']])) {
            if (($eventByCode[$entry['code']]['level'] ?? '') === 'error') {
                $phaseFailed = true;
            }
            $ts = $eventByCode[$entry['code']]['created_at'] ?? null;
            if ($ts) $phaseTimestamps[] = strtotime($ts);
        }
    }
    // Les callbacks terminaux des sous-jobs restent la source de vérité si
    // un ping checkpoint d'erreur s'est perdu. Cela couvre aussi l'historique
    // créé avant que les agents émettent un niveau "error".
    if ($phase === 'wipe') {
        foreach ($subjobs['wipes'] ?? [] as $wipe) {
            if (($wipe['wipe_status'] ?? null) === 'failed') $phaseFailed = true;
        }
    } elseif ($phase === 'install' && ($latestInstallation['install_status'] ?? null) === 'failed') {
        $phaseFailed = true;
    } elseif ($phase === 'post' && ($latestInstallation['ansible_status'] ?? null) === 'failed') {
        $phaseFailed = true;
    } elseif ($phase === 'disktest') {
        foreach ($subjobs['disk_tests'] ?? [] as $diskTest) {
            if (($diskTest['job_status'] ?? null) === 'failed') $phaseFailed = true;
        }
    }
    if ($phaseFailed) {
        $st = 'failed';
    } elseif ($phaseDone === 0) {
        $st = 'pending';
    } elseif ($phaseDone < $phaseTotal) {
        $st = 'running';
    } else {
        $st = 'success';
    }

    // Durée de phase : MAX - MIN des timestamps des events dans cette phase
    $phaseDuration = null;
    if (count($phaseTimestamps) >= 2) {
        $phaseDuration = max($phaseTimestamps) - min($phaseTimestamps);
    }

    $phaseStatus[$phase] = ['status' => $st, 'duration' => $phaseDuration];
}

// -------------------------------------------------------------------
// Construction de l'hx-trigger (polling adaptatif côté PHP)
// -------------------------------------------------------------------
$htmxAttrs = '';
if (isset($pollInterval) && $pollInterval !== null) {
    $htmxAttrs = ' hx-get="/tasks/' . $actionId . '/checklist"'
               . ' hx-trigger="every ' . (int)$pollInterval . 's"'
               . ' hx-swap="outerHTML"';
}
?>
<div id="checklist" class="checklist-wrapper"<?= $htmxAttrs ?>>

  <?php /* ============================================================
         Header checklist : icône type, titre, MAC, hostname, statut
         ============================================================ */ ?>
  <div class="checklist-header">
    <div class="checklist-title-row">
      <span class="checklist-type-icon" aria-hidden="true">
        <?= View::icon($typeIconName($actionType)) ?>
      </span>
      <div>
        <h3 class="checklist-title">
          <?= View::e($typeLabelFr($actionType)) ?> #<?= $actionId ?>
        </h3>
        <p class="checklist-meta muted">
          <?= View::e($machine['mac'] ?? '') ?>
          <?php if (!empty($machine['hostname'])): ?>
            · <?= View::e($machine['hostname']) ?>
          <?php endif ?>
          <?php if ($createdAt): ?>
            · Démarrée <?= View::e($relTime($createdAt)) ?>
          <?php endif ?>
        </p>
      </div>
    </div>
    <div class="checklist-status-row">
      <span class="status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
      <?php if ($total > 0 || $totalDuration !== null): ?>
        <div class="checklist-status-detail muted" style="font-size:0.82rem;">
          <?php if ($total > 0): ?>
            <span>
              Étape <?= $doneCount ?>/<?= $total ?>
              <?php if ($lastDoneLabel): ?>
                <span class="checklist-step-label">(<?= View::e($lastDoneLabel) ?>)</span>
              <?php endif ?>
            </span>
          <?php endif ?>
          <?php if ($totalDuration !== null): ?>
            <span>· <?= View::e(Format::humanDuration($totalDuration)) ?></span>
          <?php endif ?>
        </div>
      <?php endif ?>
    </div>

  </div>
  <?php if ($ansibleSkipped): ?>
    <p class="muted" style="font-size:0.82rem;margin:0 0 1rem;">
      Configuration Ansible ignorée : aucun rôle exécuté. Le démarrage du système se suit dans l'historique de boot.
    </p>
  <?php endif; ?>

  <?php /* ============================================================
         Logs collecte : un log par phase (wipe / install / post-install).
         Affiché dans un <details> sous chaque section de phase (cf. boucle
         ci-dessous). LOGCAP01 (2026-05-17) : installations.install_log
         alimenté par batch POST du late_command preseed.
         ============================================================ */ ?>
  <?php
  $wipeLog    = '';
  $hasInstallSubjob = !empty($subjobs['installations']);

  $wipeJobs = $subjobs['wipes'] ?? [];
  foreach ($wipeJobs as $wipe) {
      if (!empty($wipe['log'])) {
          if (count($wipeJobs) > 1) $wipeLog .= "\n=== Effacement #" . (int)$wipe['id'] . " ===\n";
          $wipeLog .= $wipe['log'];
      }
  }
  $installLog = Action::logFromSubjobs($subjobs, 'install');
  $ansibleLog = Action::logFromSubjobs($subjobs, 'ansible');

  // Mapping phase → log à afficher sous la section.
  $logByPhase = [
      'wipe'    => ['log' => $wipeLog,    'name' => 'effacement.log', 'waitMsg' => null],
      'install' => ['log' => $installLog, 'name' => 'install.log', 'download' => 'install', 'waitMsg' => $hasInstallSubjob
          ? (in_array($actionStatus, ['pending', 'running'], true)
              ? 'Le journal sera disponible lors de la finalisation par debian-installer…'
              : 'Aucun journal d’installation reçu.') : null],
      'post'    => ['log' => $ansibleLog, 'name' => 'ansible.log', 'download' => 'ansible', 'waitMsg' => $hasInstallSubjob
          ? (in_array($actionStatus, ['pending', 'running'], true)
              ? 'En attente du démarrage du playbook Ansible…'
              : 'Aucun journal Ansible reçu.') : null],
  ];
  ?>

  <?php /* ============================================================
         Phases : une section par phase du catalogue
         ============================================================ */ ?>
  <?php if (empty($phases)): ?>
    <p class="muted" style="padding:1rem 0;">Aucun checkpoint défini pour ce type d'action.</p>
  <?php else: ?>
    <?php foreach ($phases as $phase):
      $ps = $phaseStatus[$phase] ?? ['status' => 'pending', 'duration' => null];
      $phsBadgeClass = match ($ps['status']) {
          'success' => 'badge-success',
          'running' => 'badge-info',
          'failed'  => 'badge-danger',
          default   => 'badge-neutral',
      };
      $phsBadgeLabel = match ($ps['status']) {
          'success' => 'Terminée',
          'running' => 'En cours',
          'failed'  => 'Échec',
          default   => 'En attente',
      };
      $phaseEntries = array_values(array_filter($catalog, fn($e) => $e['phase'] === $phase));
    ?>
    <article class="checklist-phase" style="margin-bottom:0.75rem;">
      <header style="display:flex;align-items:center;gap:0.6rem;padding-bottom:0.4rem;border-bottom:1px solid var(--color-divider,rgba(0,0,0,.08));margin-bottom:0.5rem;">
        <strong style="flex:1;"><?= View::e($phaseLabelFr($phase)) ?></strong>
        <?php if ($ps['duration'] !== null): ?>
          <span class="muted" style="font-size:0.8rem;"><?= View::e(Format::humanDuration($ps['duration'])) ?></span>
        <?php endif ?>
        <span class="status-badge <?= $phsBadgeClass ?>" style="font-size:0.7rem;"><?= $phsBadgeLabel ?></span>
      </header>

      <ul class="checklist-steps" role="list" style="list-style:none;margin:0;padding:0;">
        <?php
        // "En cours" : seulement si l'action est still running. La détection
        // utilise $nextPendingCode (global, calculé en haut du fichier) →
        // 1 SEUL checkpoint en cours dans toute la timeline (avant ce fix,
        // chaque phase calculait son propre next pending → 3 ⟳ simultanés
        // sur Wipe + Install + Post même quand seul Wipe était actif).
        $isActionRunning = ($actionStatus === 'running');

        // Set des codes "absorbés" par une entry kind='start' active : ces
        // codes ne doivent PAS porter le spinner next-pending, car c'est leur
        // précédent (kind='start' déjà reçu mais pas encore confirmé) qui
        // porte le spinner. Évite 2 spinners simultanés pour la même phase.
        $absorbedByPrevStart = [];
        foreach ($catalog as $i => $cEntry) {
            if (($cEntry['kind'] ?? 'done') !== 'start') continue;
            if (!isset($eventByCode[$cEntry['code']]) || $i + 1 !== $reachedIndex) continue;
            $nextC = $nextCodeOf[$cEntry['code']] ?? null;
            if ($nextC !== null) $absorbedByPrevStart[$nextC] = true;
        }

        $pctOf = static function (string $code) use ($subjobs, $extractWipePercent): ?int {
            // % progression wipe : uniquement implémenté pour la phase wipe
            // (parsing du log de wipe.sh). Pas de marqueur % fiable pour
            // preseed/ansible côté install.
            if ($code !== 'wipe_script_started' && $code !== 'wipe_disks_done') return null;
            if (empty($subjobs['wipes'])) return null;
            foreach ($subjobs['wipes'] as $w) {
                if (!empty($w['log'])) {
                    $pct = $extractWipePercent((string)$w['log']);
                    if ($pct !== null) return $pct;
                }
            }
            return null;
        };

        foreach ($phaseEntries as $idx => $entry):
            $code     = $entry['code'];
            $label    = $entry['label'];
            $kind     = $entry['kind'] ?? 'done';
            $ev       = $eventByCode[$code] ?? null;
            $stepIndex = $globalIndexByCode[$code];
            $inferred = $ev === null && (
                $stepIndex < $reachedIndex
                || ($inferredCompletion && $stepIndex === $reachedIndex)
            );
            $iconHtml = '';
            $stateIcon = '';

            // Détection "kind=start en cours" : event reçu mais le checkpoint
            // suivant pas encore arrivé → l'étape dure (partitionnement, wipe.sh,
            // ansible) et il serait trompeur de cocher ✓ tant qu'on n'a pas la
            // confirmation de la fin (= arrivée du checkpoint suivant).
            $nextC = $nextCodeOf[$code] ?? null;
            $startInProgress = ($kind === 'start')
                && ($ev !== null)
                && ($stepIndex === $reachedIndex)
                && ($nextC !== null)
                && $isActionRunning;

            // Durée écoulée depuis l'étape précédente (premier event reçu = "départ").
            $stepDuration = $durationByCode[$code] ?? null;
            $stepDurationStr = $stepDuration !== null ? Format::humanDuration($stepDuration) : 'départ';

            if ($ev !== null && (string)($ev['level'] ?? 'info') === 'error') {
                // ✗ rouge — checkpoint en erreur
                $label = match ($code) {
                    'wipe_disks_done'  => 'Effacement des disques en échec',
                    'disktest_finished' => 'Tests des disques en échec',
                    'ansible_finished' => 'Configuration de la machine en échec',
                    default            => $label,
                };
                $stateClass   = 'checkpoint--error';
                $stateIcon    = '✗';
                $stateAriaLabel = 'Étape en erreur';
                $stateColor   = 'var(--color-danger,#ff3b30)';
                $stateNote    = $stepDurationStr;
            } elseif ($startInProgress) {
                // Spinner bleu animé — étape de longue durée démarrée mais pas
                // encore terminée (kind=start sans confirmation du next).
                $stateClass   = 'checkpoint--running';
                $stateAriaLabel = 'Étape en cours';
                $stateColor   = 'var(--color-apple-blue,#0071e3)';
                $iconHtml = '<svg class="checkpoint-spinner" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>';
                $label = $labelRunning($code, $label);
                $pct = $pctOf($code);
                $stateNote = $pct !== null ? ($pct . ' %') : 'en cours…';
            } elseif ($ev !== null || $inferred) {
                // ✓ vert — reçu directement ou confirmé par un checkpoint ultérieur.
                $stateClass   = 'checkpoint--done';
                $stateIcon    = '✓';
                $stateAriaLabel = $inferred ? 'Étape déduite de la progression de l’action' : 'Étape franchie';
                $stateColor   = 'var(--color-success,#15803d)';
                $stateNote    = $inferred ? 'déduite' : $stepDurationStr;
            } elseif ($isActionRunning && $code === $nextPendingCode && !isset($absorbedByPrevStart[$code])) {
                // Spinner bleu animé — prochain checkpoint attendu (cas
                // standard : aucun event reçu, et pas absorbé par un précédent
                // kind=start qui porte déjà le spinner).
                $stateClass   = 'checkpoint--running';
                $stateAriaLabel = 'Étape en cours';
                $stateColor   = 'var(--color-apple-blue,#0071e3)';
                $iconHtml = '<svg class="checkpoint-spinner" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>';
                $label = $labelRunning($code, $label);
                $pct = $pctOf($code);
                $stateNote = $pct !== null ? ($pct . ' %') : 'en cours…';
            } else {
                // ⊙ gris — en attente
                $stateClass   = 'checkpoint--pending';
                $stateIcon    = '⊙';
                $stateAriaLabel = 'Étape en attente';
                $stateColor   = 'var(--color-text-tertiary,rgba(0,0,0,.36))';
                $stateNote    = '—';
            }
        ?>
          <li class="checkpoint-row <?= $stateClass ?>">
            <span class="checkpoint-icon"
                  style="color:<?= $stateColor ?>;"
                  aria-label="<?= View::e($stateAriaLabel) ?>"
                  role="img"><?= $iconHtml !== '' ? $iconHtml : $stateIcon ?></span>
            <span class="checkpoint-label">Étape <?= $globalIndexByCode[$code] ?? ($idx + 1) ?> : <?= View::e($label) ?></span>
            <span class="checkpoint-note muted"><?= View::e($stateNote) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>

      <?php /* Log de la phase courante : <details> collapsible juste sous la
             liste des checkpoints. Affiché si log présent OU si un message
             d'attente existe (sous-job installations créé mais log pas encore
             reçu). Phase ignorée si pas de log et pas de message d'attente. */ ?>
      <?php
      $phaseLog = $logByPhase[$phase] ?? null;
      $showPhaseLog = $phaseLog !== null && ($phaseLog['log'] !== '' || $phaseLog['waitMsg'] !== null);
      ?>
      <?php if ($showPhaseLog): ?>
        <?php /* id stable par (action, phase) : le JS de tasks/show.php
               préserve l'état open via localStorage clé "pxe-details-open:<id>".
               Sans id, le mécanisme querySelectorAll('details[id]') ne matche
               pas et le polling HTMX referme le menu à chaque tick. */ ?>
        <details class="checklist-phase-log"
                 id="phase-log-<?= (int)$action['id'] ?>-<?= View::e($phase) ?>"
                 style="margin-top:0.5rem;">
          <summary style="cursor:pointer;font-size:0.82rem;color:var(--color-text-secondary);display:flex;align-items:center;gap:0.4rem;">
            <?= View::e($phaseLog['name']) ?>
            <?php /* Dans le <summary> pour rester en haut à droite de la carte, dépliée ou non ;
                     un lien dans un <summary> ne déplie pas la carte. */ ?>
            <?php if (isset($phaseLog['download']) && $phaseLog['log'] !== ''): ?>
              <a class="btn btn-secondary" style="margin-left:auto;font-size:0.8rem;"
                 href="/actions/<?= (int)$action['id'] ?>/log/<?= View::e($phaseLog['download']) ?>"
                 download><?= View::icon('download') ?> Télécharger</a>
            <?php endif; ?>
          </summary>
          <?php if ($phaseLog['log'] !== ''): ?>
            <pre class="log" style="max-height:20rem;margin-top:0.4rem;"><?= View::e($phaseLog['log']) ?></pre>
          <?php else: ?>
            <p class="muted" style="font-size:0.82rem;margin:0.4rem 0 0;">
              <?= View::e($phaseLog['waitMsg']) ?>
            </p>
          <?php endif ?>
        </details>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($ansibleSkipped && $ansibleLog !== ''): ?>
    <details class="checklist-phase-log" id="phase-log-<?= $actionId ?>-post"
             style="margin:0.5rem 0 1rem;">
      <summary style="cursor:pointer;font-size:0.82rem;color:var(--color-text-secondary);display:flex;align-items:center;gap:0.4rem;">ansible.log
        <a class="btn btn-secondary" style="margin-left:auto;font-size:0.8rem;"
           href="/actions/<?= (int)$actionId ?>/log/ansible" download><?= View::icon('download') ?> Télécharger</a>
      </summary>
      <pre class="log" style="max-height:20rem;margin-top:0.4rem;"><?= View::e($ansibleLog) ?></pre>
    </details>
  <?php endif; ?>

  <?php /* ============================================================
         Bouton "Marquer comme terminée" — bas de la vue action, entre
         les phases et les logs bruts. Visible uniquement quand l'action
         est en cours. Layout 2 colonnes : texte explicatif à gauche,
         bouton à droite (Bug J — repositionné depuis le header).
         ============================================================ */ ?>
  <?php if ($actionStatus === 'running' || $actionStatus === 'pending'): ?>
    <div class="checklist-force-complete">
      <small class="muted" style="font-size:0.78rem;line-height:1.5;flex:1;min-width:14rem;">
        À utiliser uniquement si l'opération est physiquement terminée côté machine
        mais qu'un checkpoint manqué bloque la fiche en « En cours ». L’action passera en « Clôturée manuellement ». Aucun processus distant ne sera arrêté. Si nécessaire, corrigez ensuite l’état de la machine dans les paramètres Expert.
      </small>
      <form method="post"
            action="/actions/<?= $actionId ?>/force-complete"
            onsubmit="return confirm('Clôturer administrativement ce suivi ?\n\nAucun processus distant ne sera arrêté. Vérifiez l’état physique de la machine avant de préparer une nouvelle opération.');"
            style="margin:0;flex-shrink:0;">
        <button type="submit" class="btn btn-secondary" style="margin:0;font-size:0.85rem;">
          <?= View::icon('check') ?> Clôturer le suivi
        </button>
      </form>
    </div>
  <?php endif; ?>

  <?php
  // Bouton "Réessayer la post-installation" — visible si l'une des deux
  // conditions est vraie :
  //   A) Phase 'post' marquée failed via un machine_event level=error
  //      (chemin nominal — checkpoint ansible_finished level=error)
  //   B) installations.ansible_status = 'failed' (fallback source-of-truth DB,
  //      au cas où le checkpoint ne soit pas arrivé/persisté).
  // Le double gating évite la régression observée 2026-05-19 : pendant un
  // temps, le filtre IP de l'endpoint /api/internal/checkpoint rejetait les
  // pings worker (loopback 127.0.0.1), donc aucun event level=error n'était
  // écrit en DB → le bouton restait invisible alors que ansible_status='failed'.
  $postPhaseFailed = isset($phaseStatus['post']) && $phaseStatus['post']['status'] === 'failed';
  $ansibleFailedInDb = false;
  foreach (($subjobs['installations'] ?? []) as $_inst) {
      if (($_inst['ansible_status'] ?? '') === 'failed') {
          $ansibleFailedInDb = true;
          break;
      }
  }
  $showRetryButton = $postPhaseFailed || $ansibleFailedInDb;
  $machineIdForRetry = (int)($machine['id'] ?? 0);
  ?>
  <?php if ($showRetryButton && $machineIdForRetry > 0): ?>
    <div class="checklist-force-complete" style="margin-top:0.5rem;">
      <small class="muted" style="font-size:0.78rem;line-height:1.5;flex:1;min-width:14rem;">
        Le playbook Ansible a échoué. Vous pouvez relancer uniquement la phase
        post-installation sans refaire le wipe ni l'installation OS.
      </small>
      <form method="post"
            action="/machines/<?= $machineIdForRetry ?>/ansible/retry"
            onsubmit="return confirm('Voulez-vous relancer le playbook Ansible ?\n\nSeule la phase post-installation sera réexécutée (pas de wipe, pas de réinstallation OS).');"
            style="margin:0;flex-shrink:0;">
        <button type="submit" class="btn btn-danger" style="margin:0;font-size:0.85rem;">
          <?= View::icon('refresh-cw') ?> Réessayer la post-installation
        </button>
      </form>
    </div>
  <?php endif; ?>

  <?php /* Logs sont désormais affichés sous chaque phase via <details>
         class="checklist-phase-log" (cf. boucle phases ci-dessus). */ ?>

</div>
