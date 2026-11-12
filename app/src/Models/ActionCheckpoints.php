<?php
declare(strict_types=1);

namespace Admin\Models;

/**
 * ActionCheckpoints — catalogue statique des checkpoints par type d'action.
 *
 * POURQUOI catalogue hardcodé en PHP et non en DB : les checkpoints sont des
 * jalons structurels du flow PXE/installer/ansible. Ils évoluent avec le code
 * des templates (preseed, wipe.sh, postboot.sh) et non avec les données runtime.
 * Une table DB ajouterait de la complexité de migration sans valeur ajoutée.
 *
 * POURQUOI l'ordre du catalogue est significatif : l'UI affiche les checkpoints
 * dans cet ordre indépendamment de l'ordre temporel de réception (network jitter
 * peut désordonner les pings). L'ordre = ordre logique attendu dans le flow.
 *
 * IMPORTANT : PAS de contrainte UNIQUE sur (action_id, checkpoint) en DB.
 * Un même code peut arriver plusieurs fois (machine retry). L'UI déduplique
 * en n'affichant que la première occurrence par code (spec §Idempotence).
 */
final class ActionCheckpoints
{
    /**
     * Catalogue complet des checkpoints par type d'action.
     *
     * Structure de chaque entrée :
     *   'code'  — identifiant unique dans le catalogue du type, loggué en DB
     *             dans machine_events.checkpoint
     *   'label' — libellé affiché dans l'UI (FR, vouvoiement non applicable ici
     *             car c'est un label de jalon technique pour l'admin)
     *   'phase' — groupement logique pour l'affichage par section :
     *             'wipe' | 'install' | 'post' | 'disktest'
     *   'kind'  — (optionnel, défaut 'done') sémantique d'affichage :
     *             - 'done' : cocher ✓ dès réception (events ponctuels : DHCP arrivé,
     *               TFTP servi, preseed loaded, system installed, reboot signaled).
     *             - 'start' : afficher spinner ⟳ tant que le checkpoint SUIVANT
     *               dans le catalogue n'est pas reçu. Évite la coche prématurée
     *               sur les phases longues (partitionnement, wipe.sh, ansible)
     *               qui sont marquées au DÉBUT mais durent plusieurs minutes.
     *
     * @var array<string, list<array{code: string, label: string, phase: string, kind?: string}>>
     */
    public const CATALOG = [

        // --- reinstall : wipe enchaîné + install (15 checkpoints) ---
        // POURQUOI 15 : couvre les 2 phases complètes (6 wipe + 6 install + 3 post)
        // pour un reinstall complet. C'est le catalogue de référence dont
        // install_only et wipe_only sont des sous-ensembles.
        // REINSTCKPT01 — Catalogue reinstall refondu (Fix P + Fix S) :
        //   - base_system_installed RETIRÉ (Fix S) : checkpoint intermédiaire
        //     instable émis depuis late_command alors que base-install est déjà
        //     terminé → sémantique trompeuse. Rows historiques non migrées.
        //   - install_reboot RETIRÉ (Fix P) : plus émis depuis preseed.php.j2.
        //     Signal terminal = install_reboot_done (code distinct) émis par le
        //     sidecar dnsmasq-tail quand la machine re-boote en PXE post-install
        //     (Install::install_status='success' + DHCP capté). worker.py attend
        //     ce signal avant wait_for_ssh (Fix R).
        //   - dhcp_request_install + grub_config_served_install conservés en
        //     phase 'install' : ces checkpoints couvrent le boot POST-WIPE vers
        //     l'installateur Debian (phase install normale). Pas de confusion
        //     avec le boot post-install : le second DHCP émet install_reboot_done
        //     (code distinct) via handleDnsmasqDhcpProxy.
        'reinstall' => [
            ['code' => 'dhcp_request_wipe',         'label' => 'Démarrage de la machine',                          'phase' => 'wipe'],
            ['code' => 'grub_config_served_wipe',    'label' => 'Préparation de l\'effacement',                    'phase' => 'wipe'],
            ['code' => 'preseed_loaded_wipe',        'label' => 'Lancement du programme d\'effacement',            'phase' => 'wipe'],
            ['code' => 'wipe_script_started',        'label' => 'Effacement des disques',                          'phase' => 'wipe', 'kind' => 'start'],
            ['code' => 'wipe_disks_done',            'label' => 'Disques effacés',                                 'phase' => 'wipe'],
            ['code' => 'wipe_reboot_signaled',       'label' => 'Redémarrage vers l\'installation',                'phase' => 'wipe'],
            ['code' => 'dhcp_request_install',       'label' => 'Démarrage de l\'installation',                    'phase' => 'install'],
            ['code' => 'grub_config_served_install', 'label' => 'Préparation de l\'installation',                  'phase' => 'install'],
            ['code' => 'preseed_loaded_install',     'label' => 'Lancement de l\'installeur',                      'phase' => 'install'],
            ['code' => 'partitioning_started',       'label' => 'Installation du système d\'exploitation',         'phase' => 'install', 'kind' => 'start'],
            ['code' => 'late_command_started',       'label' => 'Finalisation de l\'installation',                 'phase' => 'install', 'kind' => 'start'],
            ['code' => 'install_reboot_done',        'label' => 'Installation terminée, redémarrage de la machine','phase' => 'install'],
            ['code' => 'os_first_boot',              'label' => 'Démarrage du système installé',                   'phase' => 'post'],
            ['code' => 'ansible_started',            'label' => 'Configuration de la machine',                     'phase' => 'post', 'kind' => 'start'],
            ['code' => 'ansible_finished',           'label' => 'Machine prête',                                   'phase' => 'post'],
        ],

        // --- install_only : sous-ensemble de reinstall (sans phase wipe) ---
        // REINSTCKPT01 (Fix S + Fix P) — base_system_installed + install_reboot
        // retirés, install_reboot_done ajouté (cf. catalogue reinstall pour détail).
        'install_only' => [
            ['code' => 'dhcp_request_install',       'label' => 'Démarrage de la machine',                         'phase' => 'install'],
            ['code' => 'grub_config_served_install', 'label' => 'Préparation de l\'installation',                  'phase' => 'install'],
            ['code' => 'preseed_loaded_install',     'label' => 'Lancement de l\'installeur',                      'phase' => 'install'],
            ['code' => 'partitioning_started',       'label' => 'Installation du système d\'exploitation',         'phase' => 'install', 'kind' => 'start'],
            ['code' => 'late_command_started',       'label' => 'Finalisation de l\'installation',                 'phase' => 'install', 'kind' => 'start'],
            ['code' => 'install_reboot_done',        'label' => 'Installation terminée, redémarrage de la machine','phase' => 'install'],
            ['code' => 'os_first_boot',              'label' => 'Démarrage du système installé',                   'phase' => 'post'],
            ['code' => 'ansible_started',            'label' => 'Configuration de la machine',                     'phase' => 'post', 'kind' => 'start'],
            ['code' => 'ansible_finished',           'label' => 'Machine prête',                                   'phase' => 'post'],
        ],

        // --- wipe_only : les 6 premiers checkpoints de reinstall avec poweroff ---
        // POURQUOI wipe_poweroff et non wipe_reboot_signaled : un wipe seul
        // s'arrête en poweroff (pas de reboot vers install). Code distinct pour
        // que l'UI puisse afficher "Poweroff programmé" au lieu de "Reboot programmé".
        'wipe_only' => [
            ['code' => 'dhcp_request_wipe',      'label' => 'Démarrage de la machine',                          'phase' => 'wipe'],
            ['code' => 'grub_config_served_wipe','label' => 'Préparation de l\'effacement',                     'phase' => 'wipe'],
            ['code' => 'preseed_loaded_wipe',    'label' => 'Lancement du programme d\'effacement',             'phase' => 'wipe'],
            ['code' => 'wipe_script_started',    'label' => 'Effacement des disques',                           'phase' => 'wipe', 'kind' => 'start'],
            ['code' => 'wipe_disks_done',        'label' => 'Disques effacés',                                  'phase' => 'wipe'],
            ['code' => 'wipe_poweroff',          'label' => 'Effacement terminé — extinction de la machine',    'phase' => 'wipe'],
        ],

        // --- discovery : placeholder vide ---
        // POURQUOI vide : les checkpoints discovery seront définis quand
        // discover.sh sera instrumenté (étape ultérieure). Laisser vide maintenant
        // ne bloque pas le rollout des autres types. (cf. context.md §Questions ouvertes)
        'discovery' => [],

        // --- disktest : 5 checkpoints ---
        // POURQUOI 5 : couvre le flow DHCP → bootloader → démarrage test → fin
        // → remontée résultats. PAS d'étape « kernel loaded » : contrairement à
        // wipe/install (preseed_loaded_* = requête HTTP du d-i observable), rien
        // ne transite par PHP entre le bootloader servi et le fetch du job — une
        // telle étape n'aurait aucun émetteur possible (resterait grise à vie).
        // En chemin in-session, dhcp/grub sont émis par discoveryAction à la
        // remise du test (pas de boot PXE → le sidecar dnsmasq ne voit rien).
        'disktest' => [
            ['code' => 'dhcp_request_disktest',       'label' => 'Démarrage de la machine',                       'phase' => 'disktest'],
            ['code' => 'grub_config_served_disktest', 'label' => 'Préparation des tests',                         'phase' => 'disktest'],
            ['code' => 'disktest_started',            'label' => 'Tests des disques (SMART, badblocks)',          'phase' => 'disktest', 'kind' => 'start'],
            ['code' => 'disktest_finished',           'label' => 'Tests terminés',                                'phase' => 'disktest'],
            ['code' => 'disktest_results_posted',     'label' => 'Résultats disponibles',                         'phase' => 'disktest'],
        ],
    ];

    /**
     * Retourne le catalogue de checkpoints pour un type d'action donné.
     *
     * @return list<array{code: string, label: string, phase: string}>
     */
    public static function forType(string $type): array
    {
        return self::CATALOG[$type] ?? [];
    }

    /**
     * Vérifie si un code de checkpoint est valide pour un type d'action donné.
     * Utilisé par Worker_INFRA pour valider les pings entrants sur /api/internal/checkpoint.
     */
    public static function validCode(string $type, string $code): bool
    {
        $catalog = self::forType($type);
        foreach ($catalog as $entry) {
            if ($entry['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retourne la liste des phases distinctes dans l'ordre d'apparition du catalogue.
     * Utilisé par l'UI pour construire les sections groupées de la checklist.
     *
     * @return list<string>
     */
    public static function phases(string $type): array
    {
        $seen   = [];
        $phases = [];
        foreach (self::forType($type) as $entry) {
            $phase = $entry['phase'];
            if (!isset($seen[$phase])) {
                $seen[$phase] = true;
                $phases[]     = $phase;
            }
        }
        return $phases;
    }
}
