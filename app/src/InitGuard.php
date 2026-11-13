<?php
declare(strict_types=1);

namespace Admin;

use PDO;
use Admin\Models\Setting;

/**
 * Détecte si le serveur PXE a fini son bootstrap (init_jobs all in success/skipped).
 * Utilisé par le front controller pour rediriger vers /init si nécessaire.
 *
 * Stratégie de cache : flag `init_completed` dans la table settings.
 *   - Si flag=1 : O(1), pas de check DB.
 *   - Sinon : SELECT COUNT(*) FROM init_jobs WHERE status NOT IN ('success', 'skipped').
 *     Si 0 → set flag=1, return false. Sinon return true.
 *
 * En cas de regen MOK (Phase 7), on RESET le flag (worker remet des init_jobs en pending).
 */
final class InitGuard
{
    private const FLAG_KEY        = 'init_completed';
    private const WIZARD_FLAG_KEY = 'wizard_completed';

    // Refonte wizard (flow WordPress-style avec relogin intermédiaire).
    // State machine retournée par state() :
    //   db_pending      → /etc/pxe-tools/db.conf absent OU connexion fail
    //   admin_pending   → DB OK mais aucun user n'a été créé
    //   login_pending   → admin existe mais session vide (post création admin)
    //   wizard_pending  → loggué, mais reste réseau/defaults/validation à faire
    //   complete        → tout est fait, laisser passer
    public const STATE_DB_PENDING     = 'db_pending';
    public const STATE_ADMIN_PENDING  = 'admin_pending';
    public const STATE_LOGIN_PENDING  = 'login_pending';
    public const STATE_WIZARD_PENDING = 'wizard_pending';
    public const STATE_COMPLETE       = 'complete';

    /** Seuil au-delà duquel un job 'running' est considéré stale (worker
     * crashé ou bloqué). Le UI affiche un warning + bouton "Forcer relance".
     * Doit être > FLUSH_INTERVAL_S côté worker (2s) avec une marge pour
     * éviter les faux positifs sur jobs qui tournent normalement. */
    public const STALE_THRESHOLD_S = 30;

    /** Titre statique par job_type (affiché en tête, ne change pas pendant le run).
     * Le sous-titre dynamique vient de init_jobs.message (mis à jour live par les
     * scripts via WORKER_JOB_ID side-channel). */
    // Libellés grand public : pas d'acronymes ni de jargon (PXE, MOK, GRUB,
    // dnsmasq…). On décrit CE QUE FAIT l'étape, pas l'outil technique sous-jacent.
    public const JOB_TITLES = [
        'bootstrap:install_pxe_bins'  => 'Préparation du démarrage par le réseau',
        'bootstrap:generate_mok'      => 'Mise en place du démarrage sécurisé',
        'bootstrap:download_images'   => 'Téléchargement des systèmes d’exploitation',
        'bootstrap:build_grub'        => 'Préparation du chargeur de démarrage',
        'bootstrap:render_templates'  => 'Création des menus de démarrage',
        'bootstrap:regen_mok'         => 'Renouvellement du démarrage sécurisé',
        'bootstrap:rebuild_grub'      => 'Reconstruction du chargeur de démarrage',
        'bootstrap:restart_dnsmasq'   => 'Activation du réseau',
        'bootstrap:refresh_catalog'   => 'Mise à jour de la liste des systèmes',
        'bootstrap:refresh_catalog_v2' => 'Mise à jour de la liste des systèmes',
        'bootstrap:cleanup_per_mac_files' => 'Nettoyage des fichiers temporaires',
        'system:factory_reset_wipe'   => 'Remise à zéro du serveur',
        'system:nginx_reload'         => 'Mise à jour de l’interface',
    ];

    /** Préfixes pour job_types paramétrés (suivis d'un ID dynamique).
     * Le matching est fait dans listJobs() après le lookup exact JOB_TITLES. */
    public const JOB_TITLE_PREFIXES = [
        'preset:push_github:' => 'Sauvegarde du modèle sur GitHub',
    ];

    /**
     * @return bool true si l'init n'est pas terminé (page /init à servir).
     *
     * Spec B Lot 2 : prend aussi en compte le wizard d'init utilisateur.
     * needsInit() retourne true si :
     *   - settings.wizard_completed != '1' (wizard pas complété), OU
     *   - init_jobs en cours (bootstrap technique pas fini)
     *
     * Le contrôleur /init aiguille ensuite : wizard pas fini → /init/welcome,
     * sinon écran de progress.
     */
    public static function needsInit(): bool
    {
        // Lot 3 Spec B : en mode authentik, le wizard /init n'est pas déclenché
        // (admin implicite via SSO — pas de user à créer en DB). On suppose
        // que les settings de base seront posées via env / Ansible / API.
        if (Auth::mode() === 'authentik') {
            return false;
        }

        try {
            $pdo = Db::get();
        } catch (\Throwable) {
            // DB pas dispo → on ne sait pas, on laisse passer (pas de redirect)
            return false;
        }

        // Spec B Lot 2 : wizard sentinel. Si !=1, l'admin doit compléter le
        // wizard avant tout accès UI. Pas de cache ici (changement rare et
        // bloquant — on veut détecter immédiatement le passage à 1).
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute([self::WIZARD_FLAG_KEY]);
            $wizardDone = $stmt->fetchColumn();
            if ($wizardDone !== '1') {
                return true;
            }
        } catch (\Throwable) {
            // Table settings absente → DB neuve, wizard à faire
            return true;
        }

        // Cache : flag init_completed=1 dans settings (init_jobs technique)
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute([self::FLAG_KEY]);
            $cached = $stmt->fetchColumn();
            if ($cached === '1') {
                return false;
            }
        } catch (\Throwable) {
            // Table settings absente (DB neuve) → continuer le check
        }

        // Si la table init_jobs n'existe pas (DB vraiment neuve), on assume que
        // le worker n'a pas encore eu le temps de l'enqueue. needsInit = true.
        // NB : on ne compte QUE les jobs réellement en cours (pending/running).
        // Un job 'failed' ne doit pas bloquer l'UI — il est terminé (même mal)
        // et un re-show de l'init wizard ne ferait que masquer le vrai problème
        // (cf. bug observé : restart_dnsmasq fail intermittent → wizard ré-affiché
        // à chaque demande de page après chaque deploy).
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM init_jobs WHERE status IN ('pending', 'running')");
            $pending = (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            // init_jobs n'existe pas → DB pas migrée, init pas démarré
            return true;
        }

        if ($pending === 0) {
            // Tout est success/skipped → cache positif et OK
            try {
                $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, '1')")
                    ->execute([self::FLAG_KEY]);
            } catch (\Throwable) {
                // Pas grave, on recheckera la prochaine requête
            }
            return false;
        }

        return true;
    }

    /**
     * Spec B Lot 2 : détecte spécifiquement si le wizard utilisateur n'est
     * pas complété (= /init/welcome doit prendre la main, pas /init progress).
     */
    public static function wizardPending(): bool
    {
        try {
            $keyQ = Db::quoteIdent('key');
            $stmt = Db::get()->prepare("SELECT value FROM settings WHERE $keyQ = ?");
            $stmt->execute([self::WIZARD_FLAG_KEY]);
            return $stmt->fetchColumn() !== '1';
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Refonte wizard : state machine fine-grained pour le dispatcher du front
     * controller. Retourne un STATE_* selon l'étape où l'admin doit être routé.
     *
     * Ordre des checks (court-circuite dès le 1er match) :
     *   1. db.conf absent ou PDO fail   → STATE_DB_PENDING
     *   2. users table vide AND !admin_created → STATE_ADMIN_PENDING
     *   3. admin créé MAIS Auth::user()=null → STATE_LOGIN_PENDING
     *   4. loggué AND wizard_completed != 1 → STATE_WIZARD_PENDING
     *   5. → STATE_COMPLETE
     */
    public static function state(): string
    {
        // Mode authentik : SSO implicite, pas de wizard utilisateur
        if (Auth::mode() === 'authentik') {
            return self::STATE_COMPLETE;
        }

        // 1. DB : la connexion doit fonctionner ET le sentinel db_init_done=1
        //    doit être posé (= step /init/db validée). Sinon on force le wizard
        //    DB. La back-compat legacy SQLite est gérée par php-init.sh qui
        //    auto-génère db.conf + pose db_init_done=1 si orchestrator.sqlite
        //    existe (cf. Phase F).
        try {
            $pdo = Db::get();
            $pdo->query('SELECT 1 FROM settings LIMIT 1')->fetch();
        } catch (\Throwable) {
            return self::STATE_DB_PENDING;
        }
        if (Setting::get(Setting::KEY_DB_INIT_DONE) !== '1') {
            return self::STATE_DB_PENDING;
        }

        // 2. Admin : on regarde users table + sentinel admin_created
        try {
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (\Throwable) {
            // Table users absente (schema pas appliqué) → DB pending
            return self::STATE_DB_PENDING;
        }
        $adminCreatedFlag = Setting::get(Setting::KEY_ADMIN_CREATED);
        if ($userCount === 0 && $adminCreatedFlag !== '1') {
            return self::STATE_ADMIN_PENDING;
        }

        // 3. Login : admin créé mais session vide
        if (Auth::user() === null) {
            return self::STATE_LOGIN_PENDING;
        }

        // 4. Wizard : reste réseau/defaults/validation
        if (self::wizardPending()) {
            return self::STATE_WIZARD_PENDING;
        }

        return self::STATE_COMPLETE;
    }

    /**
     * Reset le cache (à appeler par le SettingController quand on regen MOK,
     * pour que la prochaine requête déclenche un re-check).
     */
    public static function resetCache(): void
    {
        try {
            $pdo = Db::get();
            $pdo->prepare("DELETE FROM settings WHERE key = ?")->execute([self::FLAG_KEY]);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Récupère la liste de tous les init_jobs (pour affichage UI). Enrichit
     * chaque row avec :
     *   - title (statique, depuis JOB_TITLES)
     *   - sub_message (= message si != title, sinon NULL → affichage 1 seule ligne)
     *   - stale (bool, true si status='running' et updated_at > STALE_THRESHOLD_S)
     *   - stale_age_s (int, ancienneté du dernier heartbeat en secondes)
     *
     * @return list<array<string,mixed>>
     */
    public static function listJobs(): array
    {
        try {
            $pdo = Db::get();
            // Dédup par job_type : on garde le row avec l'id le plus haut
            // (= ré-essai le plus récent), pour ne pas afficher les zombies
            // reaped + les jobs re-enqueued après. L'UI doit montrer l'état
            // courant, pas l'historique.
            // Age du heartbeat = unix UTC now - unix UTC du heartbeat. Helpers
            // Db::* pour rester portable SQLite/MySQL (strftime vs UNIX_TIMESTAMP).
            $now = Db::nowEpoch();
            $col = Db::unixEpoch('COALESCE(updated_at, started_at)');
            $rows = $pdo->query(
                "SELECT id, job_type, status, progress_percent, message, "
                . "       started_at, ended_at, updated_at, "
                . "       ($now - $col) AS age_s "
                . "FROM init_jobs "
                . "WHERE id IN (SELECT MAX(id) FROM init_jobs GROUP BY job_type) "
                . "ORDER BY id"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        if (!$rows) return [];
        // L'ordre d'affichage SUIT l'ordre d'EXÉCUTION (worker FIFO par id) :
        // l'ORDER BY id du SQL ci-dessus suffit, on ne re-trie PAS par un ordre
        // conceptuel figé. Sinon une étape exécutée tôt (id petit) mais classée
        // « tardive » dans cet ordre figé s'afficherait 'success' SOUS des étapes
        // encore 'pending' — illogique. Avec l'ordre d'exécution, les étapes
        // terminées restent toujours au-dessus de celles en cours / à venir.
        foreach ($rows as &$r) {
            $jobType = $r['job_type'] ?? '';
            // Lookup exact d'abord ; sinon match dynamique par préfixe
            // (jobs paramétrés type preset:push_github:<id>).
            $title = self::JOB_TITLES[$jobType] ?? null;
            if ($title === null) {
                foreach (self::JOB_TITLE_PREFIXES as $prefix => $label) {
                    if (str_starts_with($jobType, $prefix)) {
                        $title = $label;
                        break;
                    }
                }
            }
            // Message DB = sous-message live (e.g. "initrd.gz · 42/40 MB · 11 MB/s").
            $msg      = $r['message'] ?? null;
            $msgClean = ($msg !== null && trim((string)$msg) !== '') ? trim((string)$msg) : null;
            if ($title === null) {
                // job_type inconnu : le message (lisible) fait office de titre,
                // sinon le slug brut en dernier recours — jamais le slug brut si
                // un message existe (évite « system:factory_reset_wipe » à l'écran).
                $title = $msgClean ?? $jobType;
                $r['sub_message'] = null;
            } else {
                // Sous-message seulement s'il diffère du titre statique (legacy).
                $r['sub_message'] = ($msgClean !== null && $msgClean !== $title) ? $msgClean : null;
            }
            $r['title'] = $title;

            $r['stale'] = false;
            $age = $r['age_s'];
            $r['stale_age_s'] = $age !== null ? max(0, (int)$age) : 0;
            if (($r['status'] ?? '') === 'running' && $age !== null && (int)$age > self::STALE_THRESHOLD_S) {
                $r['stale'] = true;
            }
        }
        unset($r);
        return $rows;
    }

    /**
     * Calcule un % global moyenné sur tous les init_jobs.
     * - Pas de jobs → 0
     * - Tous success/skipped → 100
     * - Sinon : moyenne pondérée (running compte progress_percent, success=100, pending=0, failed=0)
     */
    public static function overallProgress(array $jobs): int
    {
        if (empty($jobs)) return 0;
        $total = 0;
        foreach ($jobs as $j) {
            $total += match ($j['status']) {
                'success', 'skipped' => 100,
                'running'            => max(10, (int)($j['progress_percent'] ?? 0)),
                default              => 0,  // pending, failed
            };
        }
        return (int)floor($total / count($jobs));
    }

    /**
     * @return bool true si au moins un job est en 'failed' (UI doit afficher
     * un bouton "Retry" qui repasse les failed en 'pending')
     */
    public static function hasFailures(array $jobs): bool
    {
        foreach ($jobs as $j) {
            if ($j['status'] === 'failed') return true;
        }
        return false;
    }

    /**
     * Lot 2 : l'image Debian de base est-elle prête (= téléchargée) ?
     *
     * C'est le pré-requis du mode découverte et du rescue (kernel/initrd Debian
     * netboot). InitController s'en sert pour ne PAS rediriger vers /machines
     * tant que cette image n'est pas downloaded — sinon discovery/rescue
     * n'auraient pas leur kernel.
     *
     * Retourne true si :
     *   - il existe une row Debian enabled+downloaded, OU
     *   - aucune row Debian enabled n'existe (rien à attendre — fail-open).
     * Retourne false uniquement si Debian a été seedé (enabled=1) mais n'est pas
     * encore téléchargé.
     *
     * try/catch → fail-open (return true) : on ne bloque jamais l'UI sur une
     * erreur DB.
     */
    public static function baseImageReady(): bool
    {
        try {
            $pdo = Db::get();
            // extract_method='direct' obligatoire : seule la version COURANTE
            // Debian (netboot miroir) sert réellement le discovery/rescue — c'est
            // elle que render_template.py (default netboot path) et le symlink
            // codename retiennent. Une archive 'iso_extract' downloaded ne pose
            // PAS le symlink codename → discovery 404. On l'exclut donc des deux
            // compteurs pour rester cohérent avec ces deux consommateurs.
            $ready = (int)$pdo
                ->query("SELECT COUNT(*) FROM os_images WHERE distro='debian' AND enabled=1 AND status='downloaded' AND COALESCE(extract_method,'direct')='direct'")
                ->fetchColumn();
            if ($ready > 0) {
                return true;
            }
            $seeded = (int)$pdo
                ->query("SELECT COUNT(*) FROM os_images WHERE distro='debian' AND enabled=1 AND COALESCE(extract_method,'direct')='direct'")
                ->fetchColumn();
            // Aucune row Debian direct enabled → rien à attendre.
            return $seeded === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Lot 2 : un téléchargement d'image (table os_images) est-il en cours ?
     *
     * Les downloads d'images ne passent PAS par init_jobs (chemin worker séparé),
     * donc hasActiveJobs() y est aveugle. InitController s'en sert pour ne pas
     * rediriger vers /machines tant qu'une image cochée au wizard (Debian de base
     * OU autres distros) finit de télécharger — sinon la section de progression
     * qu'on vient d'ajouter devient inutile (l'admin est éjecté pendant le DL).
     *
     * fail-open inverse de baseImageReady : sur erreur DB → false (on ne BLOQUE
     * pas la redirection ; baseImageReady garde, lui, le verrou du pré-requis).
     */
    public static function downloadsInProgress(): bool
    {
        try {
            return (int)Db::get()
                ->query("SELECT COUNT(*) FROM os_images WHERE enabled=1 AND status IN ('pending','downloading')")
                ->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lot 2 : images à afficher dans la section « Téléchargement des images »
     * de la page /init. Toutes les rows enabled=1 (peu importe le status), pour
     * que l'admin suive l'avancement du download de l'image de base + des autres
     * distros cochées au wizard.
     *
     * try/catch → [] : la section disparaît simplement en cas d'erreur DB.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listBaseDownloads(): array
    {
        try {
            $rows = array_values(array_filter(
                \Admin\Models\OsImage::allFlat(),
                static fn (array $r): bool => (int)($r['enabled'] ?? 0) === 1
            ));
            // allFlat() trie par distro (alpha) pour le browse /settings/images,
            // mais ici on veut l'ORDRE RÉEL DE TÉLÉCHARGEMENT : le worker pioche
            // FIFO par id ASC (cf. worker.py fetch_pending_image_download). On
            // réaligne donc l'affichage sur cet ordre — sinon l'image en cours
            // (Debian, seedée en premier) apparaît au milieu de la liste.
            usort($rows, static fn (array $a, array $b): int => ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)));
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }
}
