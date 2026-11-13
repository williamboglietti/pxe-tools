<?php
use Admin\View;
$jobIds = $jobIds ?? [];
$selection = $jobIds !== [] ? '?jobs=' . implode(',', $jobIds) : '';
$working = count(array_filter($jobs, static fn(array $job): bool => in_array($job['status'], ['pending', 'running'], true))) > 0
    || count(array_filter($downloads ?? [], static fn(array $image): bool => in_array($image['status'], ['pending', 'downloading'], true))) > 0;
?>
<div id="init-progress"
     hx-get="/api/init-status<?= View::e($selection) ?>"
     <?= $working ? 'hx-trigger="every 3s"' : '' ?>
     hx-swap="outerHTML">

    <?php
        // is_working = au moins un job actif (running ou pending) → applique
        // l'animation stripe-shift sur la barre globale pour matérialiser
        // le mouvement même si progress_percent est statique entre deux polls.
        $isWorking = false;
        foreach ($jobs as $j) {
            if (in_array($j['status'], ['running', 'pending'], true)) { $isWorking = true; break; }
        }
    ?>
    <div class="init-progress-bar<?= $hasFailure ? ' has-failure' : '' ?>">
        <div class="init-progress-bar-fill <?= $isWorking ? 'is-working' : '' ?> <?= $hasFailure ? 'has-failure' : '' ?>" style="width: <?= (int)$progress ?>%"></div>
        <span class="init-progress-bar-label"><?= (int)$progress ?>%</span>
    </div>

    <?php if ($jobs === []): ?><p class="muted">Aucun traitement à afficher.</p><?php endif; ?>
    <ol class="init-jobs">
        <?php foreach ($jobs as $j):
            $status = $j['status'];
            $title  = $j['title'] ?? ($j['job_type'] ?? '');
            $sub    = $j['sub_message'] ?? null;
            $stale  = !empty($j['stale']);
            $age    = (int)($j['stale_age_s'] ?? 0);

            $icon = match ($status) {
                'success'  => View::icon('circle-check'),
                'failed'   => View::icon('circle-x'),
                default    => View::icon('circle'),  // running, pending, skipped
            };
            $cls = match ($status) {
                'success' => 'init-job-success',
                'failed'  => 'init-job-failed',
                'running' => 'init-job-running',
                'skipped' => 'init-job-skipped',
                default   => 'init-job-pending',
            };
            if ($stale) $cls .= ' init-job-stale';
        ?>
        <li class="init-job <?= $cls ?>">
            <span class="init-job-icon"><?= $icon ?></span>
            <div class="init-job-body">
                <div class="init-job-title"><a href="/jobs/<?= (int)$j['id'] ?>">#<?= (int)$j['id'] ?> · <?= View::e($title) ?></a> <span class="badge <?= View::statusClass($status) ?>"><?= View::e(match ($status) { 'success' => 'Succès confirmé', 'failed' => 'Échec', 'running' => 'En cours', 'skipped' => 'Ignoré', default => 'En attente' }) ?></span></div>
                <?php if ($status === 'running' && $sub !== null): ?>
                    <div class="init-job-sub muted"><?= View::e($sub) ?></div>
                <?php elseif ($status === 'running' && (int)$j['progress_percent'] > 0): ?>
                    <div class="init-job-sub muted"><?= (int)$j['progress_percent'] ?>%</div>
                <?php elseif ($status === 'failed'): ?>
                    <div class="init-job-sub muted">
                        Échec —
                        <?= $sub !== null ? View::e($sub) . ' · ' : '' ?>
                        <a href="/jobs/<?= (int)$j['id'] ?>">Consulter le journal et le diagnostic</a>
                    </div>
                <?php endif; ?>
                <?php if ($stale): ?>
                    <div class="init-job-warning">
                        ⚠ Pas d'activité détectée depuis <?= $age ?>s.
                        <form method="post" action="/api/init-retry-job/<?= (int)$j['id'] ?><?= View::e($selection) ?>"
                              hx-post="/api/init-retry-job/<?= (int)$j['id'] ?><?= View::e($selection) ?>"
                              hx-target="#init-progress"
                              hx-swap="outerHTML"
                              class="init-job-retry-inline">
                            <button type="submit" class="btn-link">Forcer la relance</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($hasFailure): ?>
    <form method="post" action="/api/init-retry<?= View::e($selection) ?>"
          hx-post="/api/init-retry<?= View::e($selection) ?>" hx-target="#init-progress" hx-swap="outerHTML"
          class="init-retry">
        <button type="submit" class="btn btn-secondary"><?= View::icon('refresh-cw') ?> Recommencer les jobs en échec</button>
    </form>
    <?php endif; ?>

    <?php if (!empty($downloads)):
        // Libellé lisible par code distro (le code DB est brut : debian, ubuntu…).
        $distroLabels = [
            'debian'    => 'Debian',
            'ubuntu'    => 'Ubuntu',
            'fedora'    => 'Fedora',
            'centos'    => 'CentOS Stream',
            'almalinux' => 'AlmaLinux',
            'rocky'     => 'Rocky Linux',
        ];
    ?>
    <h2 class="init-downloads-title">Téléchargement des systèmes</h2>
    <ol class="init-jobs">
        <?php foreach ($downloads as $d):
            $dStatus = (string)($d['status'] ?? 'pending');
            $dPct    = max(0, min(100, (int)($d['progress_percent'] ?? 0)));
            $dMsg    = $d['message'] ?? null;
            $dErr    = $d['error_msg'] ?? null;
            $dStale  = !empty($d['stale']);
            $dAge    = (int)($d['age_s'] ?? 0);
            $dId     = (int)($d['id'] ?? 0);
            $dCode   = (string)($d['distro'] ?? '');
            $dLabel  = $distroLabels[$dCode] ?? ucfirst($dCode);
            $dVer    = (string)($d['version_full'] ?? ($d['version'] ?? ''));
            $dArch   = (string)($d['arch'] ?? '');

            $dIcon = match ($dStatus) {
                'downloaded' => View::icon('circle-check'),
                'failed'     => View::icon('circle-x'),
                default      => View::icon('circle'),  // pending, downloading
            };
            $dCls = match ($dStatus) {
                'downloaded'  => 'init-job-success',
                'failed'      => 'init-job-failed',
                'downloading' => 'init-job-running',
                default       => 'init-job-pending',
            };
            if ($dStale) $dCls .= ' init-job-stale';

            // Titre image lisible (« Debian 13.4.0 (amd64) »).
            $dTitle = trim($dLabel . ($dVer !== '' ? ' ' . $dVer : '') . ($dArch !== '' ? ' (' . $dArch . ')' : ''));
        ?>
        <li class="init-job <?= $dCls ?>">
            <span class="init-job-icon"><?= $dIcon ?></span>
            <div class="init-job-body">
                <div class="init-job-title"><?= View::e($dTitle) ?></div>
                <?php if ($dStatus === 'downloading' && $dMsg !== null && trim((string)$dMsg) !== ''): ?>
                    <div class="init-job-sub muted"><?= View::e($dMsg) ?></div>
                <?php elseif ($dStatus === 'downloading'): ?>
                    <div class="init-job-sub muted"><?= $dPct ?>%</div>
                <?php elseif ($dStatus === 'failed'): ?>
                    <div class="init-job-sub muted">
                        Échec<?= ($dErr !== null && trim((string)$dErr) !== '') ? ' — ' . View::e($dErr) : '' ?>
                    </div>
                <?php endif; ?>
                <?php if ($dStatus === 'downloading'): ?>
                    <div class="init-progress-bar">
                        <div class="init-progress-bar-fill is-working" style="width: <?= $dPct ?>%"></div>
                    </div>
                <?php endif; ?>
                <?php if ($dStale || $dStatus === 'failed'): ?>
                    <div class="init-job-warning">
                        <?php if ($dStale): ?>⚠ Pas d'activité détectée depuis <?= $dAge ?>s.<?php endif; ?>
                        <form method="post" action="/settings/images/<?= $dId ?>/download"
                              hx-post="/settings/images/<?= $dId ?>/download"
                              hx-target="#init-progress"
                              hx-swap="outerHTML"
                              class="init-job-retry-inline">
                            <button type="submit" class="btn-link">Relancer le téléchargement</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
    <p class="muted small">
        Un système de base est téléchargé : il sert à détecter automatiquement
        vos machines et à les dépanner en cas de besoin. Vous pouvez continuer à
        utiliser l’outil pendant le téléchargement.
    </p>
    <?php endif; ?>

    <p class="muted small">
        Les traitements en cours s’actualisent automatiquement. Leur résultat reste visible ici après la fin de l’opération.
    </p>
</div>
