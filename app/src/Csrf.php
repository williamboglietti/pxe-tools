<?php
declare(strict_types=1);

namespace Admin;

/**
 * Csrf — protection CSRF par synchronizer token (audit F-03).
 *
 * Un token aléatoire par session ($_SESSION['csrf_token']), exposé :
 *   - en champ caché dans les forms POST (View::csrfField + auto-injection
 *     View::injectCsrf, pour ne jamais en oublier un) ;
 *   - en header X-CSRF-Token pour HTMX (hx-headers sur <body>, cf. layout.php).
 *
 * Validé sur toute mutation de l'UI par le middleware du front controller
 * (app/public/index.php), SAUF les endpoints PXE sous /api/ : les clients PXE
 * n'ont ni cookie ni session, ils ne peuvent pas porter de token.
 *
 * Une session dédiée au token est également utilisée en mode Authentik :
 * l'authentification déléguée ne remplace pas la protection des mutations.
 */
final class Csrf
{
    /** Nom du champ de form ET de la clé de session. */
    public const FIELD  = 'csrf_token';
    /** En-tête HTTP porteur du token pour les requêtes HTMX / fetch. */
    public const HEADER = 'X-CSRF-Token';

    private const SESSION_KEY = 'csrf_token';

    /**
     * Token de la session (synchronizer). Créé à la volée au premier appel
     * (rendu d'un form ou du header HTMX). Retourne '' si aucune session active
     * (par exemple les routes /api/ qui ne démarrent pas de session).
     */
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';
        $cur = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($cur) || $cur === '') {
            $cur = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $cur;
        }
        return $cur;
    }

    /**
     * Le token fourni par la requête (champ POST ou header X-CSRF-Token)
     * correspond-il à celui de la session ? Lecture seule : ne crée jamais de
     * token (sinon une requête forgée se validerait elle-même).
     */
    public static function tokenValid(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $known = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($known) || $known === '') return false;

        $sent = $_POST[self::FIELD] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || $sent === '') return false;

        return hash_equals($known, $sent);
    }

    /**
     * Défense en complément du token : l'origine de la requête (Origin, sinon
     * Referer) doit pointer vers notre propre host. Comparaison du host seul
     * (sans port) à HTTP_HOST. HTTP_X_FORWARDED_HOST est pris en compte
     * seulement si le pair réseau est un proxy explicitement autorisé.
     * Ni Origin ni Referer présents → on ne bloque pas (le token reste la
     * défense primaire ; certains clients légitimes omettent ces en-têtes).
     */
    public static function originValid(): bool
    {
        $candidate = null;
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $k) {
            if (!empty($_SERVER[$k]) && is_string($_SERVER[$k])) {
                $candidate = parse_url($_SERVER[$k], PHP_URL_HOST);
                break;
            }
        }
        // Pas d'en-tête exploitable → on s'appuie sur le token.
        if (!is_string($candidate) || $candidate === '') return true;

        $expected = [];
        $host = self::normalizeHost($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '') $expected[] = $host;
        $peer = $_SERVER['REMOTE_ADDR'] ?? '';
        if (is_string($peer) && Auth::isTrustedProxy($peer)) {
            $forwarded = self::normalizeHost($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
            if ($forwarded !== '') $expected[] = $forwarded;
        }
        // Origin présente mais aucun host de référence → on refuse (cas anormal).
        if ($expected === []) return false;

        return in_array(strtolower($candidate), $expected, true);
    }

    /**
     * Décision du middleware de mutation (audit F-03) : la requête est-elle
     * autorisée ? true si la méthode est sûre (GET/HEAD/OPTIONS…), si c'est un
     * endpoint PXE /api/ (exempté — clients sans cookie ni session), ou si le
     * token CSRF ET l'origine sont valides. false → le front controller répond
     * 403, quel que soit le mode d'authentification.
     *
     * Note : les forms PUT/DELETE arrivent en POST (override _method dans
     * Router), donc le filtre POST les couvre déjà au niveau HTTP.
     */
    public static function requestAllowed(string $method, string $path): bool
    {
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return true;
        if (str_starts_with($path, '/api/') && !Auth::isAdminApi($path)) return true;
        return self::tokenValid() && self::originValid();
    }

    /** Host seul, minuscule, sans port — 1er élément si liste X-Forwarded-Host. */
    private static function normalizeHost(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') return '';
        $first = trim(explode(',', $raw)[0]);   // X-Forwarded-Host peut chaîner "h1, h2"
        $host  = explode(':', $first)[0];        // retire le port éventuel
        return strtolower(trim($host));
    }
}
