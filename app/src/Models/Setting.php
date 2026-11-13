<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;

/**
 * Paramètres globaux du serveur PXE, persistés dans la table `settings`
 * (key-value). Les clés exposées dans l'UI sont déclarées dans self::KNOWN
 * avec leur métadonnée d'affichage (label, options, défaut).
 *
 * Pour ajouter un nouveau paramètre :
 *   1. Ajouter une const KEY_*
 *   2. Ajouter une entrée dans self::KNOWN
 *   3. Lire la valeur via Setting::get(self::KEY_*) là où elle est consommée
 */
final class Setting
{
    public const KEY_DISCOVERY_BOOT_PROTO = 'discovery_boot_proto';

    // Spec B Lot 1+2 — sentinels et configuration wizard d'init
    public const KEY_WIZARD_COMPLETED     = 'wizard_completed';
    public const KEY_PXE_INTERFACE        = 'pxe_interface';
    public const KEY_PXE_IP      = 'pxe_ip';
    public const KEY_PXE_SUBNET  = 'pxe_subnet';
    // Renommée de l'ancienne `dhcp_mode` (valeurs proxy/tftp_only) en
    // `dhcp_proxy_enabled` (on/off) pour éviter la confusion sémantique :
    // tftp_only mélangeait transport boot et signaling DHCP. Voir migrate.php
    // pour le mapping automatique des installations existantes.
    public const KEY_DHCP_PROXY_ENABLED   = 'dhcp_proxy_enabled';
    public const KEY_DHCP_PROXY_NETWORK   = 'dhcp_proxy_network';
    public const KEY_DHCP_PROXY_NETMASK   = 'dhcp_proxy_netmask';
    public const KEY_DEFAULT_DNS          = 'default_dns';            // JSON array d'IPv4 explicitement configurées pour les nouveaux formulaires
    public const KEY_ROOT_PASSWORD_HASH   = 'root_password_hash';
    public const KEY_ANSIBLE_SSH_PUBKEY   = 'ansible_ssh_pubkey';
    public const KEY_DEBIAN_VERSIONS      = 'debian_versions';

    // Spec F — Ansible optionnel
    public const KEY_ANSIBLE_ENABLED       = 'ansible_enabled';        // on / off
    public const KEY_ANSIBLE_SSH_PRIVKEY   = 'ansible_ssh_privkey';    // ancienne valeur en clair, non utilisée par le worker
    public const KEY_ANSIBLE_PLAYBOOKS_DIR = 'ansible_playbooks_dir';  // chemin local container
    public const KEY_ANSIBLE_REPO_URL       = 'ansible_repo_url';       // URL Git du dépôt Ansible
    public const KEY_ANSIBLE_REPO_BRANCH    = 'ansible_repo_branch';    // branche suivie ; défaut main
    public const KEY_ANSIBLE_REPO_PROFILE   = 'ansible_repo_profile';   // manifeste JSON relatif ; vide = dépôt historique complet
    public const KEY_ANSIBLE_REPO_WEBHOOK_ENABLED = 'ansible_repo_webhook_enabled'; // on / off — réception push GitHub pour auto-redeploy
    public const KEY_ANSIBLE_REPO_WEBHOOK_SECRET  = 'ansible_repo_webhook_secret';  // libsodium-chiffré (Admin\Util\Crypto), validation HMAC X-Hub-Signature-256
    public const KEY_GITHUB_PUSH_TOKEN      = 'github_push_token';      // PAT GitHub libsodium-chiffré (Admin\Util\Crypto), pour `git push` HTTPS (deploy key SSH read-only)
    public const KEY_GITHUB_PUSH_USER       = 'github_push_user';       // username GitHub (clair), pour l'URL HTTPS de push
    public const KEY_PRESETS_SEEDED         = 'presets_seeded';         // '1' une fois l'autoseed initial des presets effectué (disaster-recovery, posé par le worker)

    // Portail admin TLS (configuré dans le wizard étape Réseau, re-toggle via /settings/https).
    // 'on'  : nginx sert l'UI admin en HTTPS sur ADMIN_PORT (8443) + redirect 8080→8443.
    //          Cert auto-signé généré par bootstrap-tls.sh (CN=$TLS_CN).
    // 'off' : nginx sert l'UI admin en HTTP seul sur ADMIN_HTTP_PORT (8080). Pas de
    //          cert généré, pas de redirect. Cookies session sans flag secure.
    public const KEY_HTTPS_ENABLED          = 'https_enabled';

    // Spec H — iPXE chainload (HTTP boot natif, opt-in, pattern netboot.xyz)
    public const KEY_IPXE_ENABLED          = 'ipxe_enabled';           // on / off (default off)

    // Refonte wizard (DB en step 1, relogin entre admin et réseau) — sentinels intermédiaires
    public const KEY_DB_INIT_DONE          = 'db_init_done';           // 1 après application schema + migrate
    public const KEY_ADMIN_CREATED         = 'admin_created';          // 1 après création du 1er user dans le wizard

    // NOTE: DEBIAN_RELEASES const retirée en v2 — migré vers
    // Admin\Catalog\DebianCatalog::MAPPING (source de vérité unique pour les
    // métadonnées Debian + 5 autres distros via DistroCatalog).

    /**
     * Métadonnées d'affichage des paramètres exposés.
     * Schéma : ['label' => string, 'help' => string, 'options' => [val => label], 'default' => string]
     *
     * @var array<string,array{label:string,help:string,options:array<string,string>,default:string}>
     */
    public const KNOWN = [
        self::KEY_DISCOVERY_BOOT_PROTO => [
            'label'   => 'Mode de boot pour le discovery',
            'help'    => 'Protocole utilisé par GRUB pour télécharger le kernel/initrd lors du 1er boot d\'une machine inconnue ou d\'un Re-discover. HTTP est beaucoup plus rapide ; TFTP reste utile sur firmware ancien ou VMs où le HTTP boot UEFI est instable. Ne s\'applique qu\'au discovery — le mode de boot des autres opérations (install, wipe, disktest) reste configurable par machine.',
            'options' => [
                'http' => 'HTTP — rapide, recommandé sur hardware récent',
                'tftp' => 'TFTP — universel, fallback VMs / firmware anciens',
            ],
            'default' => 'http',
        ],
    ];

    /** Dedicated Ansible fields have defaults without appearing in the generic selects. */
    private const DEFAULTS = [
        self::KEY_ANSIBLE_REPO_BRANCH => 'main',
        self::KEY_ANSIBLE_REPO_PROFILE => '',
    ];

    /**
     * Lit la valeur d'un setting. Retourne $default (ou la valeur dans KNOWN
     * si la clé y est déclarée et $default est null) si absente ou table absente.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if ($default === null) {
            $default = self::KNOWN[$key]['default'] ?? self::DEFAULTS[$key] ?? null;
        }
        try {
            $keyQ = Db::quoteIdent('key');
            $stmt = Db::get()->prepare("SELECT value FROM settings WHERE $keyQ = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? (string)$row['value'] : $default;
        } catch (\Throwable $e) {
            // table absente (avant migration) → fallback default
            return $default;
        }
    }

    /** UPSERT atomique d'une valeur. updated_at est posé par trigger SQLite / ON UPDATE MySQL. */
    public static function set(string $key, string $value): void
    {
        $sql = Db::upsert('settings', ['key'], ['key', 'value']);
        Db::get()->prepare($sql)->execute([$key, $value]);
    }

    /**
     * Retourne la liste des paramètres exposés avec leur valeur actuelle
     * (issue de la DB ou défaut), prête à rendre dans la vue.
     *
     * @return array<string,array{label:string,help:string,options:array<string,string>,default:string,current:string}>
     */
    public static function allKnown(): array
    {
        $out = [];
        foreach (self::KNOWN as $key => $meta) {
            $current = self::get($key);
            $meta['current'] = $current !== null ? $current : $meta['default'];
            $out[$key] = $meta;
        }
        return $out;
    }
}
