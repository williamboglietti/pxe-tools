<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Catalog\DistroCatalog;
use Admin\Auth;
use Admin\Db;
use Admin\InitGuard;
use Admin\Models\Setting;
use Admin\Models\User;
use Admin\Util\NetworkDetector;
use Admin\View;

/**
 * Wizard d'initialisation au premier boot — flow WordPress-style.
 *
 * Étapes (cf. plan ou-en-es-la-kind-kurzweil.md) :
 *   /init/welcome (hors compteur)
 *   1. /init/db      — choix DB (SQLite/MySQL/MariaDB) + creds + test connexion
 *   2. /init/admin   — création du 1er user (bcrypt). Redirect /login
 *      → l'admin se reconnecte (matérialise visiblement la création du compte
 *        et établit une session propre avant la suite de la configuration).
 *   3. /init/network — interface PXE + IP + DHCP proxy
 *   4. /init/images  — image OS de base (Debian stable) téléchargée en fond.
 *   5. /init/done    — récap + bouton "Terminer" qui pose wizard_completed=1.
 *
 * D'autres images OS s'ajoutent post-wizard via /settings/images (picker +
 * live progress HTMX).
 *
 * InitGuard::state() dispatche entre les étapes selon l'avancement (db.conf
 * présent ? user créé ? loggué ? wizard fini ?). Les routes /init/* restent
 * publiques (whitelist Auth::PUBLIC_PREFIXES) — l'admin n'existe pas encore au
 * début du flow.
 */
final class WizardController
{
    private const NETWORK_CONFIRMATION_KEY = 'wizard_network_confirmation';

    /** GET /init/welcome */
    public function welcome(array $params): void
    {
        // Si le wizard a déjà été complété, redirect vers login (pas de re-run accidentel)
        $this->redirectIfWizardCompleted();
        View::partial('init/wizard/welcome', []);
    }

    // -----------------------------------------------------------------------
    // Étape 1 — Choix de la base de données
    // -----------------------------------------------------------------------

    /** GET /init/db — étape 1 */
    public function dbForm(array $params): void
    {
        $this->redirectIfWizardCompleted();
        $configured = Setting::get(Setting::KEY_DB_INIT_DONE) === '1';

        // Récupère la config courante pour pré-remplir le form (cas reprise wizard).
        $current = [];
        try {
            $current = Db::config();
        } catch (\Throwable) {
            // db.conf absent → form vide avec SQLite sélectionné par défaut
        }

        View::partial('init/wizard/db', [
            'step'    => 1,
            'current' => $current,
            'configured' => $configured,
            'error'   => $_GET['error'] ?? null,
        ]);
    }

    /** POST /init/db — valide creds, écrit db.conf, applique schema + migrate */
    public function dbSubmit(array $params): void
    {
        $this->redirectIfWizardCompleted();
        $configPresent = file_exists(Db::CONFIG_PATH) || is_link(Db::CONFIG_PATH);
        if ($configPresent && !self::existingDbConfigValid()) {
            header('Location: /init/db?error=invalid_existing');
            exit;
        }
        // Keep an existing external configuration intact. The PHP layer can
        // connect to it, but this appliance's worker currently uses SQLite.
        if (Db::driver() !== 'sqlite') {
            header('Location: /init/db?error=external_existing');
            exit;
        }
        if (Setting::get(Setting::KEY_DB_INIT_DONE) === '1' && User::count() > 0) {
            View::redirect('/init/admin');
            return;
        }
        if (!self::isSupportedDbSelection($_POST)) {
            header('Location: /init/db?error=unsupported');
            exit;
        }
        $cfg = $this->parseDbForm($_POST);
        if ($cfg === null) {
            header('Location: /init/db?error=invalid');
            exit;
        }
        // A SQLite selection reuses the effective path (configuration or
        // environment), including a previously configured custom location.
        $cfg = Db::config();

        // Test connexion AVANT d'écrire db.conf — sinon on bloque l'admin en boucle
        // db_pending sur une config bidon.
        $res = Db::testConnection($cfg, true);
        if (!$res['ok']) {
            // L'admin doit voir l'erreur pour corriger ses creds.
            $msg = urlencode(substr((string)$res['error'], 0, 200));
            header("Location: /init/db?error=connection&detail=$msg");
            exit;
        }

        // Écriture atomique db.conf (chmod 0600 dans Db::writeConfig)
        if (!$configPresent) {
            try {
                Db::writeInitialConfig($cfg);
            } catch (\Throwable $e) {
                $msg = urlencode(substr($e->getMessage(), 0, 200));
                header("Location: /init/db?error=write&detail=$msg");
                exit;
            }
        }

        // Applique schema.<driver>.sql + migrate.php côté CLI. Le shell-out vers
        // sqlite3/mariadb-client est dans php-init.sh, mais ici on est déjà en
        // PHP runtime — on lance migrate.php inline (qui pose seeds + ALTER).
        $errPath = $this->applySchemaAndMigrate($cfg);
        if ($errPath !== null) {
            $msg = urlencode(substr($errPath, 0, 200));
            header("Location: /init/db?error=migrate&detail=$msg");
            exit;
        }

        Setting::set(Setting::KEY_DB_INIT_DONE, '1');

        // Déclenche le refresh du catalogue multi-distros dès que la DB existe
        // (au lieu d'attendre la soumission de l'étape /init/images) : l'admin
        // passe encore par création de compte + reconnexion + réseau avant
        // d'atteindre cette étape, ce qui laisse le temps au worker de peupler
        // catalog_entries pour que le picker riche ait des données à afficher.
        $this->enqueueJobIfAbsent(Db::get(), 'bootstrap:refresh_catalog_v2', 'Refresh du catalogue multi-distros');

        header('Location: /init/admin');
        exit;
    }

    /** POST /api/init-db-test — fragment HTMX (vert/rouge) pour bouton "Tester" */
    public function dbTestApi(array $params): void
    {
        if (!self::isSupportedDbSelection($_POST)) {
            http_response_code(400);
            echo '<span class="db-test-result db-test-result--err">MySQL et MariaDB ne sont pas encore pris en charge par le worker.</span>';
            return;
        }
        $cfg = $this->parseDbForm($_POST);
        if ($cfg === null) {
            http_response_code(400);
            echo '<span class="db-test-result db-test-result--err">Champs invalides</span>';
            return;
        }
        $res = Db::testConnection($cfg);
        header('Content-Type: text/html; charset=UTF-8');
        if ($res['ok']) {
            echo '<span class="db-test-result db-test-result--ok">✓ Connexion réussie</span>';
        } else {
            $msg = htmlspecialchars((string)$res['error'], ENT_QUOTES, 'UTF-8');
            echo "<span class=\"db-test-result db-test-result--err\">✗ Échec : $msg</span>";
        }
    }

    // -----------------------------------------------------------------------
    // Étape 2 — Création du compte admin
    // -----------------------------------------------------------------------

    /** GET /init/admin — étape 2 */
    public function adminForm(array $params): void
    {
        // SEC F-01 : wizard terminé → ne plus servir le form (cf. helper).
        $this->redirectIfWizardCompleted();

        View::partial('init/wizard/admin', [
            'step'          => 2,
            'error'         => $_GET['error'] ?? null,
            'existingAdmin' => User::count() > 0 ? User::firstUsername() : null,
        ]);
    }

    /** POST /init/admin — crée user + redirect /login (pas de session) */
    public function adminSubmit(array $params): void
    {
        // SEC F-01 : wizard terminé → refuser le POST AVANT toute lecture de
        // $_POST. Sinon recreate=1 (User::deleteAll + create) permettrait à un
        // non-authentifié ayant accès au port admin d'écraser le compte
        // légitime (cf. helper pour le détail du choix du sentinel).
        $this->redirectIfWizardCompleted();

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');
        $recreate = ($_POST['recreate'] ?? '') === '1';

        if ($username === '' || $password === '') {
            header('Location: /init/admin?error=missing');
            exit;
        }
        if (strlen($password) < 8) {
            header('Location: /init/admin?error=too_short');
            exit;
        }
        if ($password !== $confirm) {
            header('Location: /init/admin?error=mismatch');
            exit;
        }

        $pdo = Db::get();
        try {
            $pdo->beginTransaction();
            // Acquire SQLite's write lock before checking whether another
            // first-run request has created the account in the meantime.
            $pdo->exec("UPDATE settings SET value=value WHERE key='db_init_done'");
            $existing = User::count();
            if (Setting::get(Setting::KEY_WIZARD_COMPLETED) === '1'
                || (($existing > 0 || Setting::get(Setting::KEY_ADMIN_CREATED) === '1') && Auth::user() === null)) {
                $pdo->rollBack();
                header('Location: /login');
                exit;
            }
            if ($existing > 0 && !$recreate) {
                $pdo->rollBack();
                header('Location: /init/admin?error=username_taken');
                exit;
            }
            if ($existing > 0) User::deleteAll();
            User::create($username, $password);
            Setting::set(Setting::KEY_ADMIN_CREATED, '1');
            $pdo->commit();
        } catch (\PDOException) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: /init/admin?error=username_taken');
            exit;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        // Refonte wizard : on NE crée PAS de session ici. L'admin doit se
        // reconnecter via /login pour matérialiser la création du compte +
        // établir une session propre. AuthController::loginSubmit() route
        // ensuite vers /init/network si wizard_completed != 1.
        header('Location: /login?created=1&next=%2Finit%2Fnetwork');
        exit;
    }

    // -----------------------------------------------------------------------
    // Étape 3 — Réseau (existant, $step changé 1 → 3)
    // -----------------------------------------------------------------------

    /** GET /init/network — étape 3 */
    public function networkForm(array $params): void
    {
        // Garde-fou : l'étape réseau ne doit jamais être servie tant que la DB
        // et le compte admin ne sont pas faits (sinon l'admin est silencieusement
        // sauté). On renvoie sur l'étape manquante, quel que soit le chemin d'accès.
        $st = InitGuard::state();
        if ($st === InitGuard::STATE_DB_PENDING)    { header('Location: /init/db');    exit; }
        if ($st === InitGuard::STATE_ADMIN_PENDING) { header('Location: /init/admin'); exit; }

        $interfaces  = NetworkDetector::listInterfaces();
        $defaultIface = NetworkDetector::pickBestInterface() ?? '';

        $iface = Setting::get(Setting::KEY_PXE_INTERFACE, '') ?: $defaultIface;

        $pxeIp = Setting::get(Setting::KEY_PXE_IP, '') ?: '';
        if ($pxeIp === '' && $iface !== '') {
            $pxeIp = NetworkDetector::suggestOrchestratorIp($iface) ?? '';
        }

        $proxyNet  = Setting::get(Setting::KEY_DHCP_PROXY_NETWORK, '') ?: '';
        $proxyMask = Setting::get(Setting::KEY_DHCP_PROXY_NETMASK, '') ?: '';
        $proxyCidr = '';
        if ($proxyNet !== '' && $proxyMask !== '') {
            $maskLong = ip2long($proxyMask);
            if ($maskLong !== false) {
                $plen = 0;
                $bits = $maskLong;
                while ($bits & 0x80000000) { $plen++; $bits <<= 1; $bits &= 0xFFFFFFFF; }
                $proxyCidr = $proxyNet . '/' . $plen;
            }
        }
        if ($proxyCidr === '' && $iface !== '') {
            $sug = NetworkDetector::suggestProxyNetwork($iface);
            if ($sug !== null) {
                $proxyCidr = $sug['cidr'];
            }
        }

        $current = [
            'pxe_interface'        => $iface,
            'network_override'     => Setting::get('network_override', 'off') === 'on',
            'pxe_ip'      => $pxeIp,
            'pxe_subnet' => Setting::get(Setting::KEY_PXE_SUBNET, '') ?: $proxyCidr,
            'dhcp_proxy_enabled'   => Setting::get(Setting::KEY_DHCP_PROXY_ENABLED, 'off') === 'on',
            'dhcp_proxy_cidr'      => $proxyCidr,
            'https_enabled'        => Setting::get(Setting::KEY_HTTPS_ENABLED, 'off') === 'on',
        ];
        View::partial('init/wizard/network', [
            'step'       => 3,
            'interfaces' => $interfaces,
            'current'    => $current,
            'error'      => $_GET['error'] ?? null,
            'settingsFlow' => ($_GET['from'] ?? '') === 'settings' && InitGuard::state() === InitGuard::STATE_COMPLETE,
        ]);
    }

    /** POST /init/network — sauve + redirect /init/done */
    public function networkSubmit(array $params): void
    {
        $state = InitGuard::state();
        if ($state === InitGuard::STATE_DB_PENDING) { header('Location: /init/db'); exit; }
        if ($state === InitGuard::STATE_ADMIN_PENDING) { header('Location: /init/admin'); exit; }
        if ($state === InitGuard::STATE_LOGIN_PENDING) { header('Location: /login?next=%2Finit%2Fnetwork'); exit; }
        $settingsFlow = ($_GET['from'] ?? '') === 'settings' && InitGuard::state() === InitGuard::STATE_COMPLETE;
        $interfaces = NetworkDetector::listInterfaces();
        try {
            $values = \Admin\Util\NetworkConfiguration::validate($_POST, $interfaces);
            if ($state === InitGuard::STATE_WIZARD_PENDING) {
                // Save confirmation atomically with the exact validated values.
                $values[self::NETWORK_CONFIRMATION_KEY] = self::networkConfirmation($values);
            }
            \Admin\Util\SettingsMutation::apply($values);
        } catch (\Throwable $error) {
            http_response_code(422);
            $current = $_POST;
            foreach (['dhcp_proxy_enabled', 'https_enabled', 'network_override'] as $key) $current[$key] = isset($_POST[$key]);
            View::partial('init/wizard/network', ['step' => 3, 'interfaces' => $interfaces, 'current' => $current,
                'settingsFlow' => $settingsFlow,
                'error' => $error instanceof \InvalidArgumentException ? $error->getMessage() : 'save_failed']);
            return;
        }

        if ($settingsFlow) {
            View::flash('info', 'Réseau enregistré. Les contrôles d’accès utilisent immédiatement la nouvelle plage ; demandez l’application pour actualiser les services réseau.');
            header('Location: /settings');
            exit;
        }
        header('Location: /init/images');
        exit;
    }

    // -----------------------------------------------------------------------
    // Étape 4 — Image OS de base
    // -----------------------------------------------------------------------

    /** GET /init/images — étape 4 : picker riche (identique à /settings/images/add) */
    public function imagesForm(array $params): void
    {
        $this->requireConfirmedNetwork();
        View::partial('init/wizard/images', [
            'step'    => 4,
            'distros' => DistroCatalog::all(),
            'grouped' => \Admin\Models\CatalogEntry::groupedForPicker(),
            'counts'  => \Admin\Models\CatalogEntry::countsByDistro(),
        ]);
    }

    /** POST /init/images — enqueue les versions cochées (rows[] "distro:version_full:arch") */
    public function imagesSubmit(array $params): void
    {
        $this->requireConfirmedNetwork();
        $pdo = Db::get();

        // Filet de sécurité : idempotent, no-op si déjà enqueue depuis dbSubmit().
        $this->enqueueJobIfAbsent($pdo, 'bootstrap:refresh_catalog_v2', 'Refresh du catalogue multi-distros');

        // rows[] = mêmes tuples "distro:version_full:arch" que le picker de
        // /settings/images/add — même mutation (OsImage::ensureForDistroVersion,
        // idempotent) que ImagesController::addSubmit.
        $rows = $_POST['rows'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $tuple) {
                if (!is_string($tuple)) continue;
                $parts = explode(':', $tuple);
                if (count($parts) !== 3) continue;
                [$distro, $vf, $arch] = $parts;
                \Admin\Models\OsImage::ensureForDistroVersion($distro, $vf, $arch);
            }
        }

        // Debian de base seedé D'OFFICE, indépendamment de la sélection : c'est
        // le pré-requis du mode découverte (discovery) et du rescue (kernel/initrd
        // Debian netboot). Idempotent.
        $this->seedLatestImageForDistro($pdo, 'debian');

        InitGuard::resetCache();
        header('Location: /init/done');
        exit;
    }

    // -----------------------------------------------------------------------
    // Étape 5 — Validation / récap
    // -----------------------------------------------------------------------

    /** GET /init/done — étape 5 */
    public function done(array $params): void
    {
        $this->requireConfirmedNetwork();
        View::partial('init/wizard/done', ['step' => 5]);
    }

    /** POST /init/complete — pose wizard_completed=1 + applique la conf + redirect /machines */
    public function complete(array $params): void
    {
        $this->requireConfirmedNetwork();

        // Lot 3 Spec B : applique la conf réseau du wizard immédiatement.
        $pdo = Db::get();
        // build_grub APRÈS le wizard : il embarque l'IP serveur PXE (posée à
        // l'étape réseau) dans le GRUB EFI signé. Au bootstrap initial il skip
        // (pas encore d'IP) ; ici l'IP existe → le GRUB UEFI est enfin construit.
        // Messages = sous-titre live grand public (sans jargon). Le titre
        // d'étape vient de InitGuard::JOB_TITLES ; ce message le complète.
        $jobs = [
            'bootstrap:build_grub'       => 'Sécurisation du démarrage avec l’adresse de votre serveur',
            'bootstrap:render_templates' => 'Préparation des options proposées à vos machines',
            'bootstrap:restart_dnsmasq'  => 'Mise en service du réseau',
        ];
        $pdo->beginTransaction();
        try {
            // Recheck under the same transaction as completion and enqueueing:
            // a concurrent settings change must not validate an older choice.
            if (InitGuard::state() !== InitGuard::STATE_WIZARD_PENDING || !self::networkIsConfirmed()) {
                $pdo->rollBack();
                header('Location: /init/network?error=confirm_network');
                return;
            }
            Setting::set(Setting::KEY_WIZARD_COMPLETED, '1');
            $hasJob = $pdo->prepare("SELECT COUNT(*) FROM init_jobs WHERE job_type = ? AND status IN ('pending','running')");
            foreach ($jobs as $jobType => $msg) {
                $hasJob->execute([$jobType]);
                if ((int)$hasJob->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)")
                        ->execute([$jobType, $msg]);
                }
            }
            $pdo->prepare("DELETE FROM settings WHERE key = ?")->execute(['init_completed']);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        // On envoie sur /init (page de progression) plutôt que directement sur
        // /machines : l'admin voit l'avancement des jobs bootstrap + du
        // téléchargement de l'image Debian de base. InitController redirigera
        // automatiquement vers /machines quand tout sera prêt. L'admin reste
        // libre de naviguer ailleurs entre-temps (state() vaut COMPLETE ici).
        header('Location: /init');
        exit;
    }

    // -----------------------------------------------------------------------
    // Helpers privés
    // -----------------------------------------------------------------------

    /** Refuse out-of-order setup and settings changed since their confirmation. */
    private function requireConfirmedNetwork(): void
    {
        $state = InitGuard::state();
        $target = match ($state) {
            InitGuard::STATE_DB_PENDING => '/init/db',
            InitGuard::STATE_ADMIN_PENDING => '/init/admin',
            InitGuard::STATE_LOGIN_PENDING => '/login?next=%2Finit%2Fnetwork',
            InitGuard::STATE_COMPLETE => '/init',
            default => null,
        };
        if ($target !== null) { header('Location: ' . $target); exit; }
        if (!self::networkIsConfirmed()) {
            header('Location: /init/network?error=confirm_network');
            exit;
        }
    }

    private static function networkIsConfirmed(): bool
    {
        $keys = ['pxe_interface', 'pxe_ip', 'dhcp_proxy_enabled', 'pxe_subnet', 'https_enabled', 'network_override'];
        $values = [];
        foreach ($keys as $key) $values[$key] = Setting::get($key, '') ?? '';
        if ($values['dhcp_proxy_enabled'] === 'on') {
            foreach (['dhcp_proxy_network', 'dhcp_proxy_netmask'] as $key) $values[$key] = Setting::get($key, '') ?? '';
        }
        $saved = Setting::get(self::NETWORK_CONFIRMATION_KEY, '') ?? '';
        return $saved !== '' && hash_equals($saved, self::networkConfirmation($values));
    }

    private static function networkConfirmation(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * SEC F-01 : une fois le wizard terminé, les étapes publiques du wizard
     * (welcome + /init/admin form & submit) ne doivent plus rien traiter —
     * sinon un non-authentifié ayant accès au port admin pourrait écraser le
     * compte légitime via recreate=1 (User::deleteAll + User::create).
     *
     * On gate sur le sentinel DIRECT settings.wizard_completed, et NON sur
     * InitGuard::state() === COMPLETE : state() s'arrête à STATE_LOGIN_PENDING
     * tant que Auth::user() est null — ce qui est précisément le cas d'un
     * client sans session (l'attaquant). Un gate sur state() ne fermerait donc
     * rien. La (re)création de compte post-setup doit passer par une route
     * authentifiée de gestion de compte, pas par ce wizard public.
     *
     * Redirige vers /login puis exit (aucun traitement en aval).
     */
    private function redirectIfWizardCompleted(): void
    {
        if (Setting::get(Setting::KEY_WIZARD_COMPLETED) === '1') {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Parse + valide les champs du form /init/db. Retourne la config
     * normalisée prête pour Db::testConnection() / Db::writeConfig() ou null
     * si invalide.
     */
    private function parseDbForm(array $post): ?array
    {
        $driver = strtolower(trim((string)($post['driver'] ?? 'sqlite')));
        if (!in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            return null;
        }
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'path' => '/var/lib/pxe-tools/pxe-tools.sqlite'];
        }
        // MySQL / MariaDB : whitelist regex stricte (pas d'injection au DSN)
        $host = trim((string)($post['host'] ?? ''));
        $db   = trim((string)($post['database'] ?? ''));
        $user = trim((string)($post['username'] ?? ''));
        $pass = (string)($post['password'] ?? '');
        $port = (int)($post['port'] ?? 3306);

        if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $host)) return null;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $db))     return null;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $user))   return null;
        if ($port < 1 || $port > 65535)                return null;

        return [
            'driver'   => $driver,
            'host'     => $host,
            'port'     => $port,
            'database' => $db,
            'username' => $user,
            'password' => $pass,
            'prefix'   => '',
        ];
    }

    private static function isSupportedDbSelection(array $post): bool
    {
        $driver = $post['driver'] ?? 'sqlite';
        return is_string($driver) && strtolower(trim($driver)) === 'sqlite';
    }

    private static function existingDbConfigValid(): bool
    {
        if (is_link(Db::CONFIG_PATH) || !is_file(Db::CONFIG_PATH) || !is_readable(Db::CONFIG_PATH)) return false;
        $raw = @file_get_contents(Db::CONFIG_PATH);
        $config = $raw === false ? null : json_decode($raw, true);
        if (!is_array($config) || !isset($config['driver']) || !is_string($config['driver'])) return false;
        $driver = strtolower($config['driver']);
        if (in_array($driver, ['mysql', 'mariadb'], true)) return true;
        if ($driver !== 'sqlite') return false;
        $path = array_key_exists('path', $config) ? $config['path'] : Db::DEFAULT_SQLITE_PATH;
        return is_string($path) && str_starts_with($path, '/') && !preg_match('/[\x00-\x1f\x7f]/', $path);
    }

    /**
     * Applique le schema (schema.<driver>.sql) puis lance migrate.php côté CLI.
     * Retourne null si OK, ou un message d'erreur.
     */
    private function applySchemaAndMigrate(array $cfg): ?string
    {
        $schemaPath = __DIR__ . '/../../db/schema.' . ($cfg['driver'] === 'sqlite' ? 'sqlite' : 'mysql') . '.sql';
        if (!is_file($schemaPath)) {
            return "schema introuvable : $schemaPath";
        }
        try {
            $pdo = Db::get();  // Db::writeConfig() a reset le cache → utilise la nouvelle config
            $sql = file_get_contents($schemaPath);
            // SQLite et MySQL acceptent tous deux le multi-statement via exec()
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            return 'schema: ' . $e->getMessage();
        }
        // Lance migrate.php inline (autoload PSR-4 dans le même process)
        $migratePath = __DIR__ . '/../../db/migrate.php';
        if (!is_file($migratePath)) {
            return "migrate.php introuvable : $migratePath";
        }
        try {
            // require dans une closure pour isoler le scope global
            (function () use ($migratePath) {
                require $migratePath;
            })();
        } catch (\Throwable $e) {
            return 'migrate: ' . $e->getMessage();
        }
        return null;
    }

    /** Enqueue un init_job si aucun du même type n'est déjà pending/running. */
    private function enqueueJobIfAbsent(\PDO $pdo, string $jobType, string $message): void
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM init_jobs WHERE job_type = ? AND status IN ('pending','running')");
        $st->execute([$jobType]);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)")
                ->execute([$jobType, $message]);
        }
    }

    /**
     * Seed la dernière version stable d'une distro directement dans os_images
     * (enabled=1/pending) → le worker la télécharge en fond, sans dépendre du
     * refresh catalogue asynchrone (catalog_entries peut être vide ici).
     *
     * discoverVersions() peut faire un appel réseau (Debian, ≤8s, fallback
     * statique MAPPING) ; enveloppé en try/catch → on skippe la distro sur échec
     * (le job refresh_catalog_v2 + l'ajout manuel via /settings/images rattrapent).
     * Gère iso_extract (Ubuntu : iso_url renseigné, kernel/initrd null) et codename
     * null (Rocky/Fedora/CentOS/Alma). La création est atomique, même si une
     * autre requête a choisi la même image entre-temps.
     */
    private function seedLatestImageForDistro(\PDO $pdo, string $distroCode): void
    {
        $cat = DistroCatalog::get($distroCode);
        if ($cat === null) return;

        // Sélection de la « dernière stable » à seeder :
        //  - PRIORITÉ à une entrée channel='stable' + extract_method='direct' :
        //    pour Debian (hybride) ça force la CURRENT direct alignée miroir et
        //    écarte les archives 'iso_extract' (qui portent aussi channel='stable')
        //    → l'image de base (pré-requis discovery/rescue) est bien la directe,
        //    peu importe l'ordre d'émission de discoverVersions().
        //  - FALLBACK sur la 1re entrée stable de n'importe quelle méthode : sans
        //    ça, une distro 100% iso_extract (Ubuntu) ne seedait JAMAIS — cocher
        //    Ubuntu au wizard ne téléchargeait rien. discoverVersions() émet la
        //    version courante en tête → $firstStable = la plus récente.
        $stable = null;
        $firstStable = null;
        try {
            foreach ($cat->discoverVersions() as $entry) {
                if (($entry['channel'] ?? null) !== 'stable') continue;
                if ($firstStable === null) $firstStable = $entry;
                if (($entry['extract_method'] ?? 'direct') === 'direct') {
                    $stable = $entry;
                    break;
                }
            }
        } catch (\Throwable) {
            return; // découverte indisponible (réseau) → skip, rattrapé en aval
        }
        if ($stable === null) $stable = $firstStable;   // distro sans 'direct' (Ubuntu)
        if ($stable === null) return;

        $vf       = (string)$stable['version_full'];
        $arch     = 'amd64';
        $urls     = $cat->urlsFor($vf, $arch);
        $codename = $stable['codename'] ?? null;   // null OK (Rocky/Fedora/CentOS/Alma)
        $major    = $stable['major'] ?? null;

        // The conflict target matches the partial SQLite index exactly. Do not
        // restart an active/completed download when requests arrive together.
        $pdo->prepare(
            "INSERT INTO os_images
               (distro, version, arch, kernel_url, initrd_url, firmware_url, iso_url,
                extract_method, enabled, status, codename, major, version_full, channel)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending', ?, ?, ?, 'stable')
             ON CONFLICT(distro, version_full, arch) WHERE version_full IS NOT NULL
             DO UPDATE SET enabled=1,
               status=CASE WHEN os_images.status IN ('downloaded','downloading') THEN os_images.status ELSE 'pending' END,
               error_msg=CASE WHEN os_images.status IN ('downloaded','downloading') THEN os_images.error_msg ELSE NULL END"
        )->execute([
            $distroCode,
            $vf,                          // `version` legacy = version_full
            $arch,
            $urls['kernel']   ?? null,
            $urls['initrd']   ?? null,
            $urls['firmware'] ?? null,
            $urls['iso']      ?? null,    // renseigné pour Ubuntu (iso_extract), null sinon
            $stable['extract_method'] ?? $cat->extractMethod(),   // par-row (hybride), fallback classe
            $codename, $major, $vf,
        ]);
    }
}
