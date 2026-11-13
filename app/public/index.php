<?php
declare(strict_types=1);

// =============================================================================
// Front controller — PXE Tools admin (PHP + HTMX + SQLite)
// =============================================================================

// Autoload PSR-4 minimal pour Admin\
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Admin\\', 6) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, 6));
    $file = __DIR__ . '/../src/' . $rel . '.php';
    if (is_file($file)) require_once $file;
});

use Admin\Auth;
use Admin\Csrf;
use Admin\InitGuard;
use Admin\Router;
use Admin\View;
use Admin\Controllers\AuthController;
use Admin\Controllers\MachineController;
use Admin\Controllers\PresetController;
use Admin\Controllers\InstallationController;
use Admin\Controllers\WipeController;
use Admin\Controllers\DiskTestController;
use Admin\Controllers\JobController;
use Admin\Controllers\TaskController;
use Admin\Controllers\TaskPhaseController;
use Admin\Controllers\PxeController;
use Admin\Controllers\SettingsController;
use Admin\Controllers\ImagesController;
use Admin\Controllers\AnsibleController;
use Admin\Controllers\AnsibleRepoWebhookController;
use Admin\Controllers\HttpsController;
use Admin\Controllers\IpxeController;
use Admin\Controllers\InitController;
use Admin\Controllers\WizardController;
use Admin\Controllers\InternalApiController;

// Session PHP — démarrer AVANT tout output. Skip pour les routes /api/ et
// /healthz pour ne pas créer de session côté clients PXE (pas de cookies).
$earlyPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ((!str_starts_with($earlyPath, '/api/') || Auth::isAdminApi($earlyPath))
    && $earlyPath !== '/healthz') {
    // Save path : dossier persistant possédé par www-data (créé par php-init.sh).
    // Le default Debian (/var/lib/php/sessions, mode 1733) ne fonctionne pas
    // de manière fiable dans le container — on force un path stable.
    $sessionDir = getenv('PXE_SESSION_DIR') ?: '/var/lib/pxe-tools/sessions';
    if (!is_dir($sessionDir)) @mkdir($sessionDir, 0700, true);
    session_save_path($sessionDir);
    // Cookie sécurisé : HttpOnly + SameSite=Strict + Secure si HTTPS détecté.
    // SameSite=Strict : aucun flux cross-site légitime sur cet outil interne ; 1re
    // barrière anti-CSRF, complétée par le token synchronizer (audit F-03, Admin\Csrf).
    // ADMIN_PORT (Spec A.10) permet d'override le port admin TLS depuis .env.
    $adminPort = (int)(getenv('ADMIN_PORT') ?: '8443');
    $secure = ($_SERVER['HTTPS'] ?? '') === 'on'
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
           || (int)($_SERVER['SERVER_PORT'] ?? 0) === $adminPort;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('pxe_admin_sid');
    session_start();
    if (Auth::mode() === 'authentik') {
        $identity = (string)(Auth::user()['username'] ?? '');
        $previous = (string)($_SESSION['_authentik_identity'] ?? '');
        if ($identity !== $previous) {
            session_regenerate_id(true);
            $_SESSION = [];
            if ($identity !== '') $_SESSION['_authentik_identity'] = $identity;
        }
    }
}

// Global error handler — JSON pour les routes /api/, HTML sinon
set_exception_handler(function (\Throwable $e): void {
    error_log('[admin] ' . $e);
    $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    http_response_code(500);
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        echo '<h1>500 — ' . htmlspecialchars($e->getMessage()) . '</h1>';
    }
});

// Init guard PRIORITAIRE — dispatch fine-grained selon l'état du wizard
// (refonte WordPress-style avec relogin intermédiaire entre admin et réseau).
// Cf. InitGuard::state() pour le détail des états.
(function () {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

    // Whitelist : clients PXE, healthcheck, login/logout, et toutes les
    // routes du wizard (sinon le dispatcher entre en boucle).
    if (
        str_starts_with($path, '/init')
        || str_starts_with($path, '/api/')
        || str_starts_with($path, '/postboot/')
        || str_starts_with($path, '/pxe/')
        || $path === '/login'
        || $path === '/logout'
        || $path === '/healthz'
        || $path === '/wipe.sh'
    ) {
        return;
    }

    $target = match (InitGuard::state()) {
        InitGuard::STATE_DB_PENDING     => '/init/db',
        InitGuard::STATE_ADMIN_PENDING  => '/init/admin',
        InitGuard::STATE_LOGIN_PENDING  => '/login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/machines'),
        InitGuard::STATE_WIZARD_PENDING => '/init/network',
        default                          => null,  // complete → laisser passer
    };
    if ($target === null) return;

    if (isset($_SERVER['HTTP_HX_REQUEST'])) {
        header('HX-Redirect: ' . $target);
    } else {
        header('Location: ' . $target);
    }
    http_response_code(302);
    exit;
})();

// Auth middleware (Spec B Lot 1) — protège les routes UI. Exécuté APRÈS
// InitGuard pour que le wizard prenne la priorité quand l'admin n'existe pas.
// Les routes publiques (PXE clients, login, healthz, init wizard) sont
// whitelistées dans Auth::isProtected.
(function () {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (!Auth::isProtected($path)) return;
    // Authentifié OK → laisser passer
    if (Auth::user() !== null) return;
    // Non-authentifié → redirect /login avec ?next= pour retour après login
    $next = rawurlencode($_SERVER['REQUEST_URI'] ?? '/');
    if (isset($_SERVER['HTTP_HX_REQUEST'])) {
        header('HX-Redirect: /login?next=' . $next);
    } else {
        header('Location: /login?next=' . $next);
    }
    http_response_code(302);
    exit;
})();

// CSRF middleware (audit F-03) — protège les mutations de l'UI admin. Exécuté
// APRÈS Auth (les non-authentifiés sont déjà redirigés vers /login). Les
// endpoints PXE sous /api/ sont exemptés : les clients PXE n'ont ni cookie ni
// session, ils ne peuvent pas porter de token. Le token vit en session et est
// injecté en champ caché (auto-injection View) + header X-CSRF-Token (HTMX).
(function () {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (Csrf::requestAllowed($method, $path)) return;  // méthode sûre, /api/ exempté, ou token+origine OK

    error_log('[csrf] rejet ' . $method . ' ' . $path . ' depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(403);
    // Message volontairement non technique : la cause la plus fréquente n'est
    // pas une attaque mais un formulaire resté ouvert avant un redémarrage du
    // serveur (les sessions PHP ne survivent pas à un redéploiement) — un
    // recharger suffit. "Jeton CSRF invalide" sonnait comme une erreur
    // d'identifiants, ce qui n'est jamais le cas ici (le contrôle des
    // identifiants a lieu APRÈS ce filtre, dans AuthController::loginSubmit).
    if (isset($_SERVER['HTTP_HX_REQUEST'])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Page expirée : rechargez-la avant de réessayer.';
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $reloadPath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
        echo '<h1>Page expirée</h1><p>Cette page a été ouverte avant un redémarrage du serveur (ou depuis longtemps) '
           . 'et n\'est plus valide.</p><p><a href="' . $reloadPath . '">Recharger la page</a> puis réessayez.</p>';
    }
    exit;
})();

$router = new Router();

// ---------- Auth (Spec B Lot 1) ----------
$ac = new AuthController();
$router->get('/login',   [$ac, 'loginForm']);
$router->post('/login',  [$ac, 'loginSubmit']);
$router->post('/logout', [$ac, 'logout']);

// ---------- UI ----------
$mc = new MachineController();
$fc = new Admin\Controllers\FleetController();
$pc = new PresetController();
$ic = new InstallationController();

$activity = new Admin\Controllers\ActivityController();
$router->get('/activity', [$activity, 'index']);
$router->get('/activity/list', [$activity, 'fragment']);

// Dashboard = liste machines
$router->get('/',                    [$fc, 'index']);
$router->get('/machines',            [$fc, 'index']);
$router->get('/machines/new',        [$mc, 'newForm']);
$router->post('/machines',           [$mc, 'create']);
$router->post('/machines/bulk-preflight', [$mc, 'bulkPreflight']);
$router->post('/machines/bulk-install', [$mc, 'bulkInstall']);
$router->post('/machines/bulk-delete',  [$mc, 'bulkDelete']);
$router->get('/machines/list-fragment', [$fc, 'fragment']);
$router->get('/machines/{id}/hardware-context.json', [$mc, 'hardwareContextJson']);
$router->get('/machines/{id}/operation-fragment', [$mc, 'operationFragment']);
$router->get('/machines/{id}/status',   [$mc, 'statusFragment']);
$router->post('/machines/{id}/reveal-password', [$mc, 'revealPassword']);
$router->get('/machines/{id}/install', [$mc, 'installWizard']);
$router->post('/machines/{id}/install-preview', [$mc, 'installPreview']);
$router->post('/machines/{id}/install',[$mc, 'installSubmit']);
$router->get('/machines/{id}',       [$mc, 'show']);
$router->put('/machines/{id}',       [$mc, 'update']);
$router->post('/machines/{id}/wipe', [$mc, 'wipe']);
$router->post('/machines/{id}/status', [$mc, 'setStatusManual']);
$router->post('/machines/{id}/power',  [$mc, 'power']);
$router->get('/machines/{id}/events',  [$mc, 'eventsFragment']);
$router->get('/machines/{id}/power-fragment', [$mc, 'powerFragment']);
$router->get('/machines/{id}/boot-fragment', [$mc, 'bootFragment']);
$router->post('/machines/{id}/wake', [$mc, 'wake']);
$router->post('/machines/{id}/reboot',   [$mc, 'reboot']);
$router->post('/machines/{id}/shutdown', [$mc, 'shutdown']);
$router->post('/machines/{id}/wipe-disks', [$mc, 'wipeDisks']);
$router->post('/machines/{id}/disktest',             [$mc, 'diskTestStart']);
$router->post('/machines/{id}/disktest-cancel',      [$mc, 'diskTestCancel']);
$router->post('/machines/{id}/disktest-post-action', [$mc, 'diskTestPostAction']);
$router->post('/machines/{id}/rescue',      [$mc, 'rescueStart']);
$router->post('/machines/{id}/rescue/exit', [$mc, 'rescueStop']);
$router->post('/machines/{id}/rediscover',  [$mc, 'rediscover']);
$router->post('/machines/{id}/ansible/retry',    [$mc, 'ansibleRetry']);
$router->post('/machines/{id}/ansible/redeploy', [$mc, 'ansibleRedeploy']);
$router->post('/machines/{id}/ansible/pull',     [$mc, 'ansiblePull']);
$router->post('/machines/{id}/auto-deploy',      [$mc, 'autoDeployToggle']);
$router->delete('/machines/{id}',    [$mc, 'delete']);

$router->get('/presets',         [$pc, 'index']);
$router->get('/presets/new',     [$pc, 'newForm']);
$router->get('/presets/import',  [$pc, 'importForm']);
$router->post('/presets/import', [$pc, 'importStore']);
$router->post('/presets/pull-github', [$pc, 'pullGithub']);
$router->get('/presets/duplicate',  [$pc, 'duplicate']);
$router->post('/presets',        [$pc, 'create']);
$router->get('/presets/{id}.json',[$pc, 'showJson']);
$router->get('/presets/{id}',    [$pc, 'edit']);
$router->put('/presets/{id}',    [$pc, 'update']);
$router->delete('/presets/{id}', [$pc, 'delete']);
$router->post('/presets/{id}/push-github', [$pc, 'pushGithub']);

$router->get('/installations',                  [$ic, 'index']);
$router->get('/installations/{id}',             [$ic, 'show']);
$router->get('/installations/{id}/log',         [$ic, 'logFragment']);
$router->post('/installations/{id}/cancel',     [$ic, 'cancel']);

$wc = new WipeController();
$router->get('/wipes/{id}',     [$wc, 'show']);
$router->get('/wipes/{id}/log', [$wc, 'logFragment']);

$dtc = new DiskTestController();
$router->get('/disktests/{id}',          [$dtc, 'show']);
$router->get('/disktests/{id}/log',      [$dtc, 'logFragment']);
$router->get('/disktests/{id}/progress', [$dtc, 'progressFragment']);

// Jobs (init_jobs) — page de suivi live (pull repo, redéploiement Ansible)
$jc = new JobController();
$router->get('/jobs/{id}/status', [$jc, 'status']);
$router->get('/jobs/{id}/poll', [$jc, 'poll']);
$router->get('/jobs/{id}',      [$jc, 'show']);
// Status JSON d'un job par job_type (toast live, sans détour par /init)
$router->get('/api/init-job-status', [$jc, 'statusByJobType']);

// Spec C — Vue unifiée des tâches d'une machine (timeline + drill-down)
$tc = new TaskController();
$router->get('/machines/{id}/tasks',         [$tc, 'listFragment']);
$router->get('/machines/{id}/tasks/{task}',  [$tc, 'show']);

// Actions — routes canoniques (numérique pur) + redirections legacy 301.
// /tasks/{action_id}/checklist → partial HTMX (hx-swap="outerHTML" sur #checklist)
// /tasks/{id}   → numérique pur  → showAction (action parente, full HTML)
// /tasks/{task} → type-N format  → showLegacy (301 vers /tasks/{action_id})
// IMPORTANT : la route checklist est enregistrée EN PREMIER (pattern plus spécifique).
// Puis la route numérique avant la route legacy (même pattern [^/]+, ordre
// de parcours dans Router::dispatch garantit la priorité).
$router->get('/tasks/{action_id}/checklist', [$tc, 'checklist']);
$router->get('/tasks/{id}',   [$tc, 'showAction']);
$router->get('/tasks/{task}', [$tc, 'showLegacy']);
// Force-complete : clôture admin d'une action stuck en 'running' alors que
// l'opération est physiquement terminée. Redirige vers la fiche machine.
$router->post('/actions/{id}/force-complete', [$tc, 'forceComplete']);
$router->get('/actions/{id}/log/{kind}', [$tc, 'downloadLog']);

// Suivi temps réel des phases (install/wipe/ansible)
$tpc = new TaskPhaseController();
$router->get('/api/task-phase',                       [$tpc, 'ping']);
$router->get('/api/machines/{id}/task-progress',      [$tpc, 'progress']);
$monitoring = new Admin\Controllers\MonitoringController();
$router->get('/api/monitoring/installs',              [$monitoring, 'installs']);

$sc = new SettingsController();
$router->get('/settings',  [$sc, 'index']);
$router->post('/settings', [$sc, 'update']);
$router->post('/settings/default-dns', [$sc, 'updateDefaultDns']);
$router->post('/settings/regenerate-mok', [$sc, 'regenerateMok']);
$router->post('/settings/rebuild-grub',   [$sc, 'rebuildGrub']);
$router->post('/settings/apply-config',   [$sc, 'applyConfig']);
$router->post('/settings/reset',          [$sc, 'resetMachines']);
$router->post('/settings/factory-reset',   [$sc, 'factoryReset']);

// Spec E — gestion des images OS
$imc = new ImagesController();
$router->get('/settings/images',                       [$imc, 'index']);
$router->get('/settings/images/add',                   [$imc, 'add']);          // v2 picker
$router->post('/settings/images/add',                  [$imc, 'addSubmit']);    // v2 enqueue DLs
$router->get('/api/images-status',                     [$imc, 'statusFragment']);
// refresh AVANT les routes avec {id} (sinon "refresh" est matché comme {id})
$router->post('/settings/images/refresh',              [$imc, 'refresh']);
$router->get('/settings/images/refresh-button',        [$imc, 'refreshButtonFragment']);
$router->post('/settings/images/auto-refresh',         [$imc, 'updateAutoRefresh']);
$router->post('/settings/images/{id}/toggle',          [$imc, 'toggle']);
$router->post('/settings/images/{id}/download',        [$imc, 'download']);
$router->delete('/settings/images/{id}',               [$imc, 'delete']);

// Spec F — module Ansible (toggle activer + form SSH)
$anc = new AnsibleController();
$router->get('/settings/ansible',  [$anc, 'form']);
$router->post('/settings/ansible', [$anc, 'update']);
$router->post('/settings/ansible/vault-unlock', [$anc, 'unlockVault']);

// Webhook GitHub pour redéploiement Ansible automatique (cf. auto_deploy par machine).
// Public via /api/ (PUBLIC_PREFIXES dans Auth) — sécurisé par HMAC SHA-256 X-Hub-Signature-256.
$arwc = new AnsibleRepoWebhookController();
$router->post('/api/ansible-repo/webhook', [$arwc, 'receive']);

// Spec H — iPXE chainload (toggle on/off, OFF par défaut)
$ipc = new IpxeController();
$router->get('/settings/ipxe',  [$ipc, 'form']);
$router->post('/settings/ipxe', [$ipc, 'update']);

// Portail admin HTTPS (toggle on/off, ON par défaut, choix initial dans le
// wizard étape Réseau). Re-toggler ici nécessite restart container pour prendre effet.
$hc = new HttpsController();
$router->get('/settings/https',  [$hc, 'form']);
$router->post('/settings/https', [$hc, 'update']);

// Page d'init du serveur (bootstrap container worker)
$initc = new InitController();
$router->get('/init',                [$initc, 'index']);
$router->get('/api/init-status',     [$initc, 'status']);
$router->post('/api/init-retry',     [$initc, 'retry']);
$router->post('/api/init-retry-job/{id}', [$initc, 'retryJob']);

// Wizard d'init utilisateur (refonte WordPress-style) — accessible sans auth
// tant que les sentinels (db_init_done, admin_created, wizard_completed) ne
// sont pas à 1. Whitelist /init/* dans Auth::PUBLIC_PREFIXES.
// Flow : /init/welcome → /init/db → /init/admin → /login →
//        relogin → /init/network → /init/images → /init/done → /machines.
// D'autres images OS s'ajoutent post-wizard via /settings/images.
$wc = new WizardController();
$router->get('/init/welcome',   [$wc, 'welcome']);
$router->get('/init/db',        [$wc, 'dbForm']);
$router->post('/init/db',       [$wc, 'dbSubmit']);
$router->post('/api/init-db-test', [$wc, 'dbTestApi']);
$router->get('/init/admin',     [$wc, 'adminForm']);
$router->post('/init/admin',    [$wc, 'adminSubmit']);
$router->get('/init/network',   [$wc, 'networkForm']);
$router->post('/init/network',  [$wc, 'networkSubmit']);
$router->get('/init/images',    [$wc, 'imagesForm']);
$router->post('/init/images',   [$wc, 'imagesSubmit']);
$router->get('/init/done',      [$wc, 'done']);
$router->post('/init/complete', [$wc, 'complete']);
$portController = new \Admin\Controllers\NativePortController();
$router->get('/init/port-80', [$portController, 'form']);
$router->post('/init/port-80', [$portController, 'submit']);

// ---------- API (servi sur :80 par le vhost PXE pour les endpoints PXE,
//            et accessible aussi sur :8080) ----------
// (Phase 2/3 : ajouterons /api/discovery, /api/install-start, /api/install-end ici)

// Health
$router->get('/api/health', function () {
    View::json(['status' => 'ok', 'time' => date('c')]);
});

// PXE endpoints — accessibles via :80 (vhost PXE) et :8080 (admin)
$pxe = new PxeController();
$router->get('/api/boot-selected', [$pxe, 'bootSelected']);
$router->post('/api/discovery',     [$pxe, 'discovery']);
$router->get('/api/discovery',      [$pxe, 'discovery']);
$router->get('/api/discovery-action',  [$pxe, 'discoveryAction']);
$router->post('/api/discovery-action', [$pxe, 'discoveryAction']);
$router->get('/api/install-start',  [$pxe, 'installStart']);
$router->post('/api/install-start', [$pxe, 'installStart']);
$router->get('/api/install-end',    [$pxe, 'installEnd']);
$router->post('/api/install-end',   [$pxe, 'installEnd']);
$router->get('/api/machine-ready',  [$pxe, 'machineReady']);
$router->post('/api/machine-ready', [$pxe, 'machineReady']);

// /wipe.sh — script bash rendu dynamiquement par MAC (disques + token inlinés).
// Sert le rôle de l'ancien duo wipe.sh statique + /api/wipe-job (supprimés).
$router->get('/wipe.sh', [$pxe, 'wipeScript']);

// Wipe job — endpoints appelés par wipe.sh (progress + end seuls, fetch /wipe.sh
// fait office de "start" — passe le job en running au moment de la requête).
$router->get('/api/wipe-progress',  [$pxe, 'wipeProgress']);
$router->post('/api/wipe-progress', [$pxe, 'wipeProgress']);
$router->get('/api/wipe-end',       [$pxe, 'wipeEnd']);
$router->post('/api/wipe-end',      [$pxe, 'wipeEnd']);

// Disk tests — endpoints appelés par disktest.sh
$router->get('/api/disktest-job',       [$pxe, 'diskTestJob']);
$router->post('/api/disktest-job',      [$pxe, 'diskTestJob']);
$router->get('/api/disktest-progress',  [$pxe, 'diskTestProgress']);
$router->post('/api/disktest-progress', [$pxe, 'diskTestProgress']);
$router->get('/api/disktest-disk-progress',  [$pxe, 'diskTestDiskProgress']);
$router->post('/api/disktest-disk-progress', [$pxe, 'diskTestDiskProgress']);
$router->post('/api/disktest-result',   [$pxe, 'diskTestResult']);
$router->post('/api/disktest-smart',    [$pxe, 'diskTestSmart']);
$router->get('/api/disktest-end',       [$pxe, 'diskTestEnd']);
$router->post('/api/disktest-end',      [$pxe, 'diskTestEnd']);

// Internal API — checkpoints + DHCP events (spec actions/checkpoints 2026-05-16).
// Réponse TOUJOURS 200 vide (échec silencieux). Filtres IP intégrés au handler.
// POST-only contrairement aux endpoints PXE legacy (boot params en GET).
$internal = new InternalApiController();
$router->post('/api/internal/checkpoint', [$internal, 'checkpoint']);
$router->post('/api/internal/dhcp-event', [$internal, 'dhcpEvent']);
// dnsmasq-event : appelé par le sidecar dnsmasq-tail (loopback) qui parse les
// logs dnsmasq pour émettre les checkpoints DHCP proxy + TFTP serve, cas où
// le hook dhcp-script natif n'est pas invoqué (mode proxy = pas de bail).
$router->post('/api/internal/dnsmasq-event', [$internal, 'dnsmasqEvent']);
// recompute-action : appelé par le worker Python après chaque update direct
// d'un sous-job qui touche un état terminal (ex: installations.ansible_status
// = success | failed | no_role). Le worker met à jour la table en SQL pur et
// court-circuite donc les hooks PHP (Wipe::end, Installation::setAnsibleStatus).
// Cet endpoint déclenche manuellement Action::recomputeEndState pour que
// l'action parente passe à success/failed.
$router->post('/api/internal/action/{id}/recompute', [$internal, 'recomputeAction']);
// LOGCAP01 (2026-05-17) — capture batch log debian-installer côté serveur.
// Posté depuis le late_command preseed via wget --post-file toutes les ~1s
// ou 50 lignes (whichever-first). Auth par Bearer token (actions.token) +
// binding install_id ↔ action_id côté SQL. Filtre IP pxe_subnet.
// 413 si chunk > 256KB, 410 si action terminale, 200+JSON {status, bytes, appended, seq}.
$router->post('/api/internal/install-log', [$internal, 'appendInstallLog']);

// SEC-01 : servage postboot par PHP authentifié MAC↔IP (hors document root nginx).
// L'URL publique /postboot/{id}/postboot.sh reste l'API client (wget côté preseed inchangé).
$router->get('/postboot/{id}/postboot.sh', [$internal, 'servePostboot']);

// Refactor checkpoints 2026-05-16 : route per-action pour servir postboot.sh
// et pxe-postboot.service au late_command du preseed. Auth par filtre subnet
// PXE (le client est l'installateur Debian, pas un user). Cf.
// PxeController::serveActionFile pour la logique de filtrage et la whitelist
// des fichiers servables.
$router->get('/pxe/postboot/{action_id}/{file}', [$pxe, 'serveActionFile']);

// ---------- Dispatch ----------
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$router->dispatch($method, $path);
