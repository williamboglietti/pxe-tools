<?php
declare(strict_types=1);

namespace Admin;

use Admin\Models\Machine;

/**
 * Helpers de rendu : escape HTML, render template, partial, flash.
 * Pas de moteur de templating externe — pur PHP + include.
 */
final class View
{
    private static array $flashes = [];

    /** Profondeur d'imbrication render()/partial() : l'auto-injection CSRF
     *  n'a lieu qu'au niveau le plus externe (0), sinon double champ par form. */
    private static int $renderDepth = 0;

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Champ caché CSRF à placer dans un form POST (audit F-03). Retourne ''
     * si aucune session active — l'auto-injection devient
     * alors un no-op. Cf. Admin\Csrf.
     */
    public static function csrfField(): string
    {
        $t = Csrf::token();
        if ($t === '') return '';
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . self::e($t) . '">';
    }

    /** Token CSRF brut (pour hx-headers / usage JS). '' si pas de session. */
    public static function csrfToken(): string
    {
        return Csrf::token();
    }

    /**
     * Auto-injection du champ CSRF : insère View::csrfField() juste après
     * chaque balise <form … method="post" …> same-origin du HTML rendu. Évite
     * d'éditer les ~35 templates de forms et garantit qu'aucun n'est oublié
     * (audit F-03). N'altère pas les forms GET ni les actions externes (le
     * token ne doit pas fuir cross-origin). No-op si pas de token.
     */
    private static function injectCsrf(string $html): string
    {
        $field = self::csrfField();
        if ($field === '' || $html === '') return $html;

        $out = preg_replace_callback('/<form\b[^>]*>/i', static function (array $m) use ($field): string {
            $tag = $m[0];
            // Forms POST uniquement (méthode insensible à la casse, guillemets optionnels).
            if (!preg_match('/\bmethod\s*=\s*["\']?\s*post\b/i', $tag)) return $tag;
            // Action externe (http(s):// ou //host) → ne pas injecter (anti-fuite token).
            if (preg_match('~\baction\s*=\s*["\']?\s*(?:https?:)?//~i', $tag)) return $tag;
            return $tag . $field;
        }, $html);

        return $out ?? $html;
    }

    /**
     * Retourne la classe CSS du badge pour un status métier.
     * Mapping centralisé pour cohérence dans toute l'UI.
     */
    public static function statusClass(?string $status): string
    {
        return match ($status) {
            'unknown', 'pending', 'no_role', null, ''               => 'badge-neutral',
            'discovered', 'configured', 'installed', 'wiped', 'tested' => 'badge-info',
            'inventorying', 'installing', 'running', 'in_progress', 'wiping', 'testing' => 'badge-running',
            'provisioned', 'success'                                => 'badge-success',
            'failed', 'aborted', 'error'                            => 'badge-danger',
            'cancelled'                                             => 'badge-neutral',
            default                                                 => 'badge-neutral',
        };
    }

    /**
     * Format FR d'une taille en octets : virgule décimale, espace insécable, unité auto.
     * Base décimale (SI) : 1 Go = 10⁹ octets, cohérent avec l'étiquette des fabricants
     * ("disque 120 GB" → 120 Go affiché). RAM utilise sa propre conversion binaire.
     * Exemples : 1000000000 → "1,0 Go" · 500000 → "500 Ko" · 0 → "0 o".
     */
    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) return '0 o';
        $KB = 1000; $MB = $KB * 1000; $GB = $MB * 1000; $TB = $GB * 1000;
        $fr = static fn(float $n): string => str_replace('.', ',', number_format($n, 1, '.', ''));
        if ($bytes >= $TB) return $fr($bytes / $TB) . "\u{00A0}To";
        if ($bytes >= $GB) return $fr($bytes / $GB) . "\u{00A0}Go";
        if ($bytes >= $MB) return (string)(int)round($bytes / $MB) . "\u{00A0}Mo";
        if ($bytes >= $KB) return (string)(int)round($bytes / $KB) . "\u{00A0}Ko";
        return $bytes . "\u{00A0}o";
    }

    /**
     * Badge HTML prêt à afficher, en pastille à côté du nom "PXE Tools" —
     * même convention que display-control : "BETA · v{semver}".
     */
    public static function appVersionBadge(): string
    {
        return '<span class="app-version-badge">BETA · v' . self::e(Version::displayNumber()) . '</span>';
    }

    /**
     * Libellé d'état "panel" (dérivé du status DB) pour le bandeau d'identité
     * de l'onglet Aperçu. Agrège les statuses techniques en états utilisateur :
     * En ligne / Installation/Effacement/Tests en cours / En attente /
     * Hors ligne après Discovery / En échec.
     * rescue_mode=1 prend la priorité.
     *
     * Retourne ['label' => string, 'class' => string] pour usage avec un .status-dot.
     *
     * @return array{label:string,class:string}
     */
    public static function stateLabel(array $machine): array
    {
        if ((int)($machine['rescue_mode'] ?? 0) === 1) {
            return ['label' => 'Mode rescue', 'class' => 'state-rescue'];
        }
        $status = $machine['status'] ?? 'unknown';
        if (self::isAwaitingAction($machine)
            && !in_array($status, ['installing', 'wiping', 'testing'], true)) {
            return ['label' => 'En attente après inventaire', 'class' => 'state-ready'];
        }
        if (Machine::isOfflineAfterDiscovery($machine)) {
            return ['label' => 'Hors ligne', 'class' => 'state-offline'];
        }
        return match ($status) {
            'provisioned', 'installed' => ['label' => 'En ligne',              'class' => 'state-online'],
            'installing'               => ['label' => 'Installation en cours', 'class' => 'state-running'],
            'wiping'                   => ['label' => 'Effacement en cours',   'class' => 'state-running'],
            'testing'                  => ['label' => 'Tests en cours',        'class' => 'state-running'],
            'inventorying'             => ['label' => 'Inventaire en cours…',  'class' => 'state-running'],
            'failed'                   => ['label' => 'En échec',              'class' => 'state-offline'],
            default                    => ['label' => 'En attente',            'class' => 'state-ready'],
        };
    }

    public static function isAwaitingAction(array $machine): bool
    {
        $raw = $machine['awaiting_action_until'] ?? null;
        return $raw !== null && $raw !== ''
            && strtotime((string)$raw . ' UTC') > time();
    }

    /**
     * Libellé d'affichage français pour un status DB. Retourne la clé brute
     * si elle n'est pas mappée, pour ne pas masquer un nouveau status.
     */
    public static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') return '–';
        return match ($status) {
            'unknown'      => 'inconnue',
            'pending'      => 'en attente',
            'inventorying' => 'inventaire en cours',
            'discovered'   => 'nouveau',
            'configured'   => 'configurée',
            'installing'   => 'installation en cours',
            'installed'    => 'installée',
            'provisioned'  => 'provisionnée',
            'wiping'       => 'effacement en cours',
            'wiped'        => 'effacée',
            'testing'      => 'tests en cours',
            'tested'       => 'testée',
            'running'      => 'en cours',
            'in_progress'  => 'en cours',
            'success'      => 'succès',
            'no_role'      => 'aucun rôle Ansible',
            'failed'       => 'échec',
            'aborted'      => 'interrompu',
            'cancelled'    => 'clôturée manuellement',
            'error'        => 'erreur',
            default        => $status,
        };
    }

    /**
     * Rend le badge "logo de distribution" pour un code distro (debian, ubuntu, ...).
     *
     * Si un fichier SVG existe à `app/public/img/distros/<code>.svg`, il est
     * servi tel quel (charge à toi de l'y déposer — les logos officiels sont
     * sous trademark de leurs projets respectifs, à télécharger depuis leurs
     * sites officiels).
     *
     * Sinon, fallback sur l'initiale colorée historique : carré coloré + 1ère
     * lettre majuscule. La couleur vient de .distro-icon-<code> en CSS.
     *
     * @param string $code  ex: 'debian', 'ubuntu', 'fedora'
     * @param string $extraCls classes additionnelles à concaténer
     */
    public static function distroLogo(string $code, string $extraCls = ''): string
    {
        $code = strtolower(trim($code));
        if ($code === '') return '';
        $cls = trim('distro-icon ' . $extraCls);

        // Chemin filesystem du SVG (depuis app/src/View.php → app/public/img/distros/)
        $svgPath = __DIR__ . '/../public/img/distros/' . $code . '.svg';
        if (is_file($svgPath)) {
            $url = '/img/distros/' . rawurlencode($code) . '.svg?v=' . (int)@filemtime($svgPath);
            return '<span class="' . self::e($cls) . ' distro-icon--svg" aria-hidden="true">'
                 . '<img src="' . self::e($url) . '" alt="" loading="lazy" decoding="async">'
                 . '</span>';
        }

        // Fallback : carré coloré + initiale (compat avec l'existant)
        $letter = strtoupper($code[0] ?? '?');
        return '<span class="' . self::e($cls) . ' distro-icon-' . self::e($code) . '" aria-hidden="true">'
             . self::e($letter)
             . '</span>';
    }

    /** Render a full page with layout. */
    public static function render(string $template, array $data = [], string $title = 'PXE Tools'): void
    {
        $isHtmx = isset($_SERVER['HTTP_HX_REQUEST']);
        extract($data, EXTR_SKIP);
        $contentFile = __DIR__ . '/Views/' . $template . '.php';
        if (!is_file($contentFile)) {
            throw new \RuntimeException("View not found: $template");
        }
        // Buffer la sortie pour auto-injecter le champ CSRF dans les forms POST
        // (audit F-03). Couvre la branche HTMX (fragment) ET la branche layout
        // (qui embarque aussi le form /logout). json() n'est pas bufferisé ici.
        self::$renderDepth++;
        ob_start();
        try {
            if ($isHtmx) {
                // Partial only — pas de layout
                include $contentFile;
            } else {
                $contentRender = function () use ($contentFile, $data) {
                    extract($data, EXTR_SKIP);
                    include $contentFile;
                };
                include __DIR__ . '/Views/layout.php';
            }
        } finally {
            $html = (string) ob_get_clean();
            self::$renderDepth--;
        }
        // Injection une seule fois, au niveau le plus externe : les templates
        // imbriquent View::partial(), un buffer par niveau injecterait en double.
        echo self::$renderDepth === 0 ? self::injectCsrf($html) : $html;
    }

    public static function partial(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $f = __DIR__ . '/Views/' . $template . '.php';
        if (!is_file($f)) {
            throw new \RuntimeException("Partial not found: $template");
        }
        // Même auto-injection CSRF que render(), même garde de profondeur : un
        // partial est soit autonome (fragment HTMX), soit imbriqué dans render()
        // — dans ce dernier cas l'injection est faite par le niveau externe.
        self::$renderDepth++;
        ob_start();
        try {
            include $f;
        } finally {
            $html = (string) ob_get_clean();
            self::$renderDepth--;
        }
        echo self::$renderDepth === 0 ? self::injectCsrf($html) : $html;
    }

    /**
     * Ajoute un flash en session.
     * `$actionUrl` + `$actionLabel` : bouton-lien optionnel dans le toast
     * (ex: voir une page liée). `$jobType` : si fourni, toast.js bascule en
     * mode "live" — poll /api/init-job-status?job_type=... toutes les 1s,
     * met à jour le toast in-place jusqu'à success/failed (cf. toast.js).
     */
    public static function flash(string $type, string $msg, ?string $actionUrl = null, ?string $actionLabel = null, ?string $jobType = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['flashes'][] = [
            'type'         => $type,
            'msg'          => $msg,
            'action_url'   => $actionUrl,
            'action_label' => $actionLabel,
            'job_type'     => $jobType,
        ];
    }

    public static function consumeFlashes(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $f = $_SESSION['flashes'] ?? [];
        unset($_SESSION['flashes']);
        return $f;
    }

    public static function redirect(string $path): void
    {
        // HTMX redirect = HX-Redirect header
        if (isset($_SERVER['HTTP_HX_REQUEST'])) {
            header('HX-Redirect: ' . $path);
            exit;
        }
        header('Location: ' . $path);
        exit;
    }

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Rendu d'un badge SMART : verdict (passed/failed/unknown) en couleur,
     * avec tooltip détaillant les attributs critiques quand dispo.
     * $smart : { mode, verdict, power_on_hours, temperature_c, reallocated, pending, crc_errors }
     */
    public static function smartBadge(?array $smart): string
    {
        if (!is_array($smart)) {
            return '<span class="status-badge badge-neutral" title="Aucune donnée SMART">SMART —</span>';
        }
        $verdict = $smart['verdict'] ?? 'unknown';
        $mode    = $smart['mode']    ?? 'sysfs';
        [$cls, $label] = match ($verdict) {
            'passed' => ['badge-success', 'SMART OK'],
            'failed' => ['badge-danger',  'SMART ÉCHEC'],
            default  => ['badge-neutral', 'SMART —'],
        };
        $tipParts = [];
        if (($smart['power_on_hours'] ?? null) !== null) $tipParts[] = $smart['power_on_hours'] . ' h alim.';
        if (($smart['temperature_c']  ?? null) !== null) $tipParts[] = $smart['temperature_c'] . '°C';
        if (($smart['reallocated']    ?? null) !== null) $tipParts[] = 'realloc:' . $smart['reallocated'];
        if (($smart['pending']        ?? null) !== null) $tipParts[] = 'pending:' . $smart['pending'];
        if (($smart['crc_errors']     ?? null) !== null) $tipParts[] = 'crc:' . $smart['crc_errors'];
        $tip = $mode === 'sysfs' ? 'sysfs uniquement (smartctl indisponible)' : implode(' · ', $tipParts);
        return '<span class="status-badge ' . $cls . '" title="' . self::e($tip ?: $mode) . '">' . self::e($label) . '</span>';
    }

    /**
     * Icône SVG lucide-style + label court pour un type d'interface réseau.
     */
    public static function ifaceTypeIcon(string $type): string
    {
        $icon = match ($type) {
            'wifi'        => self::icon('wifi'),
            'ethernet'    => self::icon('ethernet'),
            'fibre'       => self::icon('sparkles'),
            'infiniband'  => self::icon('zap'),
            default       => self::icon('plug'),
        };
        $label = match ($type) {
            'wifi' => 'Wi-Fi', 'ethernet' => 'Ethernet',
            'fibre' => 'Fibre', 'infiniband' => 'InfiniBand',
            default => 'Autre',
        };
        // Wrapper inline-flex pour aligner l'icône (SVG display:block par défaut
        // via Tailwind preflight) avec le label texte sur la même ligne.
        return '<span style="display:inline-flex;align-items:center;gap:.4rem;">' . $icon . self::e($label) . '</span>';
    }

    /**
     * Helper SVG icônes lucide-style — 24×24, stroke currentColor, fill none.
     * Pattern utilisé partout dans l'UI : remplace les emojis pour rendu
     * cohérent cross-OS et pro. Les classes CSS additionnelles (taille,
     * couleur) doivent être posées sur le wrapper parent — l'inline CSS
     * .icon-svg gère taille par défaut (1em × 1em).
     *
     * @param string $name  Nom court (check, x, warning, info, package, ...)
     * @param string $cls   Classes CSS additionnelles à appliquer au SVG
     */
    public static function icon(string $name, string $cls = ''): string
    {
        $paths = match ($name) {
            'check'    => '<path d="M20 6 9 17l-5-5"/>',
            'x'        => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'circle'   => '<circle cx="12" cy="12" r="10"/>',
            'circle-check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'circle-x' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
            'warning'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'lightbulb' => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
            'package'  => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/>',
            'wrench'   => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2"/><circle cx="12" cy="12" r="3"/>',
            'trash'    => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
            'zap'      => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
            'sparkles' => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
            'wifi'     => '<path d="M5 13a10 10 0 0 1 14 0"/><path d="M8.5 16.5a5 5 0 0 1 7 0"/><path d="M2 8.82a15 15 0 0 1 20 0"/><line x1="12" x2="12.01" y1="20" y2="20"/>',
            'ethernet' => '<rect x="2" y="9" width="20" height="11" rx="2"/><path d="M6 9V6a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v3"/><path d="M6 14h.01"/><path d="M10 14h.01"/><path d="M14 14h.01"/><path d="M18 14h.01"/>',
            'plug'     => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z"/>',
            'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
            'monitor'  => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
            'cpu'      => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
            'shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'hard-drive' => '<line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/>',
            'image'    => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
            'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'lock'     => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'key'      => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
            'play'     => '<polygon points="6 3 20 12 6 21 6 3"/>',
            'pause'    => '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
            'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
            'memory-stick' => '<path d="M6 19v-3"/><path d="M10 19v-3"/><path d="M14 19v-3"/><path d="M18 19v-3"/><path d="M8 11V9"/><path d="M16 11V9"/><path d="M12 11V9"/><path d="M2 15h20"/><path d="M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v1.1a2 2 0 0 0 0 3.837V17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-5.1a2 2 0 0 0 0-3.837Z"/>',
            'moon'     => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
            'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
            'menu'     => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
            'log-out'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            default    => '<circle cx="12" cy="12" r="10"/>',  // fallback: cercle vide
        };
        $clsAttr = $cls !== '' ? ' class="' . self::e($cls) . '"' : '';
        return '<svg' . $clsAttr . ' viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }

    /**
     * Formate un datetime ISO (`YYYY-MM-DD HH:MM:SS`) en français formel :
     * "Le 09/05/2026 à 22:30:20". null/vide → "—".
     */
    public static function frenchDateTime(?string $iso): string
    {
        if ($iso === null || $iso === '') return '—';
        $ts = strtotime($iso);
        if ($ts === false) return self::e($iso);
        return 'Le ' . date('d/m/Y', $ts) . ' à ' . date('H:i:s', $ts);
    }

    /**
     * Mapping vendor PCI ID hex → nom humain (top vendors NIC/USB pour homelab).
     * Source : pci.ids — limité aux IDs courants pour ne pas embarquer la liste
     * complète. Inconnu → renvoie l'ID hex tel quel.
     */
    public static function pciVendorLabel(?string $vid): string
    {
        if ($vid === null || $vid === '') return '';
        return match (strtolower($vid)) {
            '8086' => 'Intel',
            '10de' => 'NVIDIA',
            '1002' => 'AMD/ATI',
            '10ec' => 'Realtek',
            '14e4' => 'Broadcom',
            '1969' => 'Atheros',
            '168c' => 'Atheros (Qualcomm)',
            '1af4' => 'Red Hat (virtio)',
            '15b3' => 'Mellanox',
            '11ab' => 'Marvell',
            '1d6a' => 'Aquantia',
            '8087' => 'Intel (USB)',
            '1106' => 'VIA',
            '14c0' => 'Compaq',
            '1814' => 'Ralink',
            default => $vid,
        };
    }

    /**
     * Label humain pour la classe USB (bDeviceClass en hex). Quand class=00,
     * la classe est définie au niveau interface — on retourne "device" générique.
     */
    public static function usbClassLabel(?string $class): string
    {
        if ($class === null || $class === '') return '—';
        return match (strtolower($class)) {
            '00' => 'Device',
            '01' => 'Audio',
            '02' => 'Comm',
            '03' => 'HID',
            '05' => 'Phys',
            '06' => 'Image',
            '07' => 'Print',
            '08' => 'Storage',
            '09' => 'Hub',
            '0a' => 'Data',
            '0b' => 'SmartCard',
            '0d' => 'Security',
            '0e' => 'Video',
            '0f' => 'Health',
            'dc' => 'Diag',
            'e0' => 'Wireless',
            'ef' => 'Misc',
            'fe' => 'AppSpec',
            'ff' => 'VendorSpec',
            default => '0x' . $class,
        };
    }

    /**
     * Format FR d'une fréquence CPU en MHz : >=1000 → "X,Y GHz", sinon "X MHz".
     * Retourne null si valeur invalide (à utiliser pour skip l'affichage).
     */
    public static function formatCpuFreq(?int $mhz): ?string
    {
        if ($mhz === null || $mhz <= 0) return null;
        if ($mhz >= 1000) {
            $ghz = round($mhz / 1000, 1);
            return str_replace('.', ',', (string)$ghz) . "\u{00A0}GHz";
        }
        return $mhz . "\u{00A0}MHz";
    }

    /**
     * Format compact d'une vitesse en Mbps : null/0 → "—", >=1000 → "X Gbps".
     */
    public static function formatSpeedMbps(?int $mbps): string
    {
        if ($mbps === null || $mbps <= 0) return '—';
        if ($mbps >= 1000 && $mbps % 1000 === 0) return ($mbps / 1000) . "\u{00A0}Gbps";
        if ($mbps >= 1000) return number_format($mbps / 1000, 1, ',', '') . "\u{00A0}Gbps";
        return $mbps . "\u{00A0}Mbps";
    }
}
