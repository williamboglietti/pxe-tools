<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\InitGuard;
use Admin\Models\Setting;
use Admin\Models\Machine;
use Admin\View;

/**
 * Paramètres globaux du serveur PXE. Une page, un form, persistance
 * en DB via Setting::set(). Validation : la valeur soumise doit appartenir
 * aux options déclarées dans Setting::KNOWN.
 *
 * Actions destructives :
 *   - regenerateMok() : génère un nouveau MOK keypair + rebuild GRUB EFI signé.
 *     ATTENTION : invalide les machines déjà enrôlées en Secure Boot.
 *   - rebuildGrub()   : reconstruit grubx64.efi (utile si IP serveur PXE change).
 */
final class SettingsController
{
    public function index(array $params): void
    {
        View::render('settings/index', [
            'settings' => Setting::allKnown(),
            'jobs' => \Admin\Util\ApplianceJobs::tracked(),
        ], 'Paramètres');
    }

    public function update(array $params): void
    {
        $values = [];
        foreach (Setting::KNOWN as $key => $meta) {
            $raw = $_POST[$key] ?? null;
            if ($raw === null || !is_string($raw)) continue;
            if (!array_key_exists($raw, $meta['options'])) {
                View::flash('error', 'Valeur invalide pour : ' . $meta['label']);
                View::redirect('/settings');
                return;
            }
            $values[$key] = $raw;
        }
        \Admin\Util\SettingsMutation::apply($values);
        View::flash('success', 'Paramètres enregistrés');
        View::redirect('/settings');
    }

    /**
     * DNS par défaut (texte libre IPv4) — hors du flux enum `update()`. Validé
     * IPv4 puis stocké en JSON dans le réglage `default_dns`, consommé par
     * Preset::defaultDns() (install + modèle). Une liste vide retire le choix.
     */
    public function updateDefaultDns(array $params): void
    {
        $raw = $_POST['default_dns'] ?? [];
        if (!is_array($raw)) $raw = [$raw];
        $valid = [];
        foreach ($raw as $ip) {
            if (!is_string($ip)) {
                View::flash('error', 'DNS invalide : adresse IPv4 attendue.');
                View::redirect('/settings');
                return;
            }
            $ip = trim($ip);
            if ($ip === '') continue;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                View::flash('error', "DNS invalide : $ip — adresse IPv4 attendue.");
                View::redirect('/settings');
                return;
            }
            $valid[] = $ip;
        }
        $valid = array_values(array_unique($valid));
        Setting::set(Setting::KEY_DEFAULT_DNS, json_encode($valid));
        View::flash('success', 'DNS par défaut enregistrés.');
        View::redirect('/settings');
    }

    /**
     * Regénération MOK keypair + rebuild GRUB EFI.
     * Workflow :
     *   1. Insère 2 init_jobs (regen_mok puis rebuild_grub)
     *   2. Reset le cache InitGuard → la prochaine requête redirige vers /init
     *   3. Worker pickup les jobs, exécute scripts avec REGEN=1 / REBUILD=1
     */
    public function regenerateMok(array $params): void
    {
        $ids = \Admin\Util\ApplianceJobs::enqueue([
            'bootstrap:regen_mok' => 'Régénération de la clé MOK (Secure Boot)',
            'bootstrap:rebuild_grub' => 'Reconstruction du GRUB EFI avec la nouvelle MOK',
        ]);
        InitGuard::resetCache();
        View::flash('warning', 'Régénération MOK demandée. Après succès, les machines Secure Boot devront enrôler la nouvelle clé.', '/init?jobs=' . implode(',', $ids), 'Suivre les traitements');
        View::redirect('/init?jobs=' . implode(',', $ids));
    }

    /**
     * Rebuild seulement du GRUB EFI (avec la MOK existante).
     * Utile quand l'IP du serveur PXE change (l'IP est embedded dans le
     * binaire EFI signé).
     */
    public function rebuildGrub(array $params): void
    {
        $ids = \Admin\Util\ApplianceJobs::enqueue(['bootstrap:rebuild_grub' => 'Reconstruction du GRUB EFI (IP serveur PXE courante)']);
        InitGuard::resetCache();
        View::flash('info', 'Reconstruction du GRUB EFI demandée.', '/jobs/' . $ids[0], 'Suivre le traitement');
        View::redirect('/jobs/' . $ids[0]);
    }

    /**
     * Régénère les menus et applique explicitement la configuration réseau.
     * La tâche réseau rend sa propre configuration avant de relancer dnsmasq.
     */
    public function applyConfig(array $params): void
    {
        $ids = \Admin\Util\ApplianceJobs::enqueue([
            'bootstrap:build_grub' => 'Build du GRUB EFI signé MOK (IP serveur PXE)',
            'bootstrap:render_templates' => 'Régénération des configurations PXE depuis les paramètres',
            'bootstrap:restart_dnsmasq' => 'Application de la configuration réseau (relance dnsmasq)',
        ]);
        InitGuard::resetCache();
        View::flash('info', 'Application demandée : GRUB EFI, configurations PXE et redémarrage dnsmasq. Aucun succès n’est encore confirmé.', '/init?jobs=' . implode(',', $ids), 'Suivre les traitements');
        View::redirect('/init?jobs=' . implode(',', $ids));
    }

    /**
     * Réinitialise les machines : DELETE FROM machines + cascade vers
     * discoveries / installations / wipes, puis enqueue un cleanup des
     * fichiers PXE per-MAC. Préserve presets, settings, comptes admin,
     * MOK et images OS.
     *
     * Defense-in-depth : exige $_POST['confirm']==='RESET' (le bouton UI
     * est aussi disabled côté JS tant que le champ texte != 'RESET').
     * Avant la suppression, compte les éléments ;
     * count avant wipe, cleanup post-truncate enqueueé séparément.
     */
    public function resetMachines(array $params): void
    {
        if (($_POST['confirm'] ?? '') !== 'RESET') {
            View::flash('error', 'Confirmation invalide — tapez RESET dans le champ');
            View::redirect('/settings');
            return;
        }

        $pdo = Db::get();
        $count = 0;
        $menuLock = null;
        try {
            [$menuLock] = Machine::lockPxeMenus();
            $count = (int)$pdo->query('SELECT COUNT(*) FROM machines')->fetchColumn();
            if (Db::driver() === 'sqlite') $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM machines');
            $pdo->prepare("INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)")->execute(['bootstrap:cleanup_per_mac_files', 'Nettoyage des fichiers PXE après réinitialisation du parc']);
            $jobId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            View::flash('error', 'Erreur durant le reset : ' . $e->getMessage());
            View::redirect('/settings');
            return;
        } finally {
            if (is_resource($menuLock)) { flock($menuLock, LOCK_UN); fclose($menuLock); }
        }

        InitGuard::resetCache();

        View::flash(
            'success',
            $count > 0
                ? "$count machine(s) supprimée(s). Nettoyage des fichiers PXE demandé."
                : 'Aucune machine à supprimer. Nettoyage des fichiers PXE demandé.',
            '/jobs/' . $jobId, 'Suivre le nettoyage'
        );
        View::redirect('/settings');
    }

    /**
     * Factory reset complet : remet l'appliance à l'état « install neuve » →
     * retour au wizard /init. Vide TOUTES les tables de données (settings, users,
     * machines, presets, images, catalogue) côté PHP, puis enqueue un job worker
     * (root) qui efface les artefacts disque (images netboot, bootloaders TFTP,
     * MOK) et relance le bootstrap.
     *
     * Distinct de resetMachines() (qui ne touche qu'aux hôtes).
     *
     * Confirmation : $_POST['confirm']==='REINITIALISER' (token distinct de
     * 'RESET' du reset machines, pour éviter un clic réflexe sur la mauvaise action).
     */
    public function factoryReset(array $params): void
    {
        if (($_POST['confirm'] ?? '') !== 'REINITIALISER') {
            View::flash('error', 'Confirmation invalide — tapez REINITIALISER dans le champ');
            View::redirect('/settings');
            return;
        }

        $pdo = Db::get();

        // Toutes les tables de données (init_jobs est géré à part juste après).
        // FK off pendant le wipe pour ignorer l'ordre des dépendances.
        $tables = [
            'machines', 'discoveries', 'installations', 'wipes', 'actions',
            'machine_events', 'disk_tests', 'task_phases',
            'presets', 'os_images', 'catalog_entries',
            'settings', 'users',
        ];

        $menuLock = null;
        try {
            [$menuLock] = Machine::lockPxeMenus();
            $pdo->exec(Db::driver() === 'sqlite' ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
            $pdo->beginTransaction();
            foreach ($tables as $t) {
                $pdo->exec("DELETE FROM $t");
            }
            // Purge les jobs init passés puis enqueue le wipe artefacts (worker root).
            $pdo->exec('DELETE FROM init_jobs');
            // A worker may still be completing an older job while reset runs.
            // Keep the job sequence so its late update cannot target the newly
            // queued reset job through a reused identifier.
            if (Db::driver() === 'sqlite') $pdo->exec("DELETE FROM sqlite_sequence WHERE name <> 'init_jobs'");
            $pdo->prepare("INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)")
                ->execute(['system:factory_reset_wipe', 'Effacement des artefacts PXE + re-bootstrap']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec(Db::driver() === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
            View::flash('error', 'Erreur durant la réinitialisation : ' . $e->getMessage());
            View::redirect('/settings');
            return;
        } finally {
            if (is_resource($menuLock)) { flock($menuLock, LOCK_UN); fclose($menuLock); }
        }
        $pdo->exec(Db::driver() === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');

        // Session admin orpheline (compte supprimé) → on la détruit pour repartir
        // sur un /login propre après recréation du compte au wizard.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }

        InitGuard::resetCache();
        // settings + users vidés → InitGuard renvoie au wizard /init.
        View::redirect('/init');
    }
}
