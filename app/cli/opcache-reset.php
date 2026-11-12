<?php
declare(strict_types=1);

/**
 * CLI utility — reset de l'OPcache PHP-FPM après déploiement.
 *
 * Stratégie : envoie SIGUSR2 au master process php-fpm, ce qui déclenche
 * un graceful reload (les workers existants terminent leurs requêtes en cours,
 * puis les nouveaux workers rechargent le bytecode depuis disque — OPcache
 * vide dans les nouveaux workers).
 *
 * POURQUOI SIGUSR2 et non opcache_reset() :
 *   opcache.enable_cli=0 → opcache_reset() depuis CLI n'agit que sur le pool
 *   CLI, pas sur le pool FPM. SIGUSR2 sur le master FPM est le seul moyen
 *   fiable de vider l'OPcache sans restart container ni dépendance réseau.
 *
 * Idempotent : peut être rejoué 2× sans erreur (SIGUSR2 est toujours sûr).
 *
 * Usage :
 *   docker exec pxe-tools php /app/cli/opcache-reset.php
 *
 * Exit codes :
 *   0  succès — stdout = {"status":"ok","pid":N,"signal":"USR2"}
 *   1  erreur (pidfile absent, processus inexistant, kill échoué)
 *
 * Sécurité : refuse de tourner via SAPI fpm/cgi (CLI only).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$pidFile = '/run/php/php8.4-fpm.pid';

if (!is_readable($pidFile)) {
    fwrite(STDERR, "ERROR: pidfile introuvable ou illisible : $pidFile\n");
    echo json_encode(['status' => 'error', 'reason' => 'pidfile_missing']) . "\n";
    exit(1);
}

$pidRaw = trim((string) file_get_contents($pidFile));
$pid = (int) $pidRaw;

if ($pid <= 0) {
    fwrite(STDERR, "ERROR: PID invalide dans $pidFile : '$pidRaw'\n");
    echo json_encode(['status' => 'error', 'reason' => 'pid_invalid']) . "\n";
    exit(1);
}

// SIGUSR2 = graceful reload php-fpm. Les workers actuels finissent leurs
// requêtes avant de se recycler. Les nouveaux workers démarrent avec un
// OPcache vide → les fichiers PHP fraîchement déployés sont recompilés.
if (!posix_kill($pid, SIGUSR2)) {
    $err = posix_strerror(posix_errno());
    fwrite(STDERR, "ERROR: kill($pid, SIGUSR2) échoué : $err\n");
    echo json_encode(['status' => 'error', 'reason' => 'kill_failed', 'pid' => $pid]) . "\n";
    exit(1);
}

echo json_encode(['status' => 'ok', 'pid' => $pid, 'signal' => 'USR2']) . "\n";
exit(0);
