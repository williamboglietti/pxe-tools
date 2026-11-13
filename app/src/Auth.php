<?php
declare(strict_types=1);

namespace Admin;

use Admin\Models\User;

/**
 * Auth — Spec B Lot 1.
 *
 * Mode `local` (par défaut) :
 *   - Auth::login($username, $password) vérifie via User::verifyPassword
 *     puis stocke $_SESSION['user_id'].
 *   - Auth::user() lit $_SESSION['user_id'] et retourne le user complet (ou null).
 *   - Auth::logout() détruit la session.
 *   - Auth::middleware($path) redirige vers /login si non authentifié, sauf pour
 *     les routes whitelistées (PXE clients, login, healthz, init wizard).
 *
 * Mode `authentik` : lit X-Forwarded-User uniquement depuis un proxy configuré.
 *
 * Le mode est déterminé par la variable d'env `AUTH_MODE` (default `local`).
 */
final class Auth
{
    private const MODE_LOCAL     = 'local';
    private const MODE_AUTHENTIK = 'authentik';

    /** Whitelist : routes accessibles sans authentification. */
    private const PUBLIC_PREFIXES = [
        '/login',           // form login + POST verify
        '/logout',          // bouton logout (POST mais accessible)
        '/api/',            // PXE clients (discovery, install, wipe, disktest)
        '/healthz',         // sondes
        '/init',            // wizard d'init (Lot 2) accessible sans auth tant que pas configuré
        '/postboot/',       // SEC-01 : postboot.sh servi PHP-auth (MAC↔IP), pas d'auth user
        '/pxe/',            // Refactor checkpoints 2026-05-16 : postboot per-action servi PHP, filtre pxe_subnet
        '/wipe.sh',         // Phase 1 fusion : wipe.sh dynamique, filtre subnet PXE côté PxeController
    ];

    public static function mode(): string
    {
        $m = getenv('AUTH_MODE') ?: self::MODE_LOCAL;
        return in_array($m, [self::MODE_LOCAL, self::MODE_AUTHENTIK], true) ? $m : self::MODE_LOCAL;
    }

    /**
     * Retourne le user authentifié ou null.
     * Mode local : lit $_SESSION['user_id']. Mode authentik : lit X-Forwarded-User
     * uniquement si le pair réseau appartient à la liste de proxys de confiance.
     *
     * @return array|null Row users ou null si non authentifié
     */
    public static function user(): ?array
    {
        if (self::mode() === self::MODE_AUTHENTIK) {
            if (!self::isTrustedProxy((string)($_SERVER['REMOTE_ADDR'] ?? ''))) return null;
            $username = $_SERVER['HTTP_X_FORWARDED_USER'] ?? '';
            if (!is_string($username) || $username === '' || strlen($username) > 256
                || trim($username) !== $username || preg_match('/[\x00-\x1f\x7f,]/', $username)) return null;
            // Mode authentik : on ne crée pas le user en DB, on stub un row volatile
            // (pas de password, pas de last_login). Lot 3 fera la finalisation.
            return ['id' => 0, 'username' => $username, 'password_hash' => '', 'created_at' => null, 'last_login_at' => null];
        }

        // Mode local : session PHP
        if (session_status() !== PHP_SESSION_ACTIVE) return null;
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId) || $userId <= 0) return null;
        return User::findById($userId);
    }

    /** Trust only the actual network peer, never X-Forwarded-For.
     * The same explicitly configured proxies may supply X-Forwarded-Host
     * for the CSRF origin check, including in local authentication mode.
     */
    public static function isTrustedProxy(string $peer): bool
    {
        $address = @inet_pton($peer);
        $configured = trim((string)(getenv('AUTHENTIK_TRUSTED_PROXY_CIDRS') ?: ''));
        if ($address === false || $configured === '') return false;
        $matched = false;
        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);
            $parts = explode('/', $entry);
            if (count($parts) > 2 || $parts[0] === '') return false;
            $network = @inet_pton($parts[0]);
            if ($network === false) return false;
            $size = strlen($network);
            $bits = $size * 8;
            if (count($parts) === 2) {
                if (!preg_match('/\A[0-9]{1,3}\z/D', $parts[1])) return false;
                $bits = (int)$parts[1];
            }
            // A catch-all would make arbitrary direct clients trusted proxies.
            if ($bits < 1 || $bits > $size * 8) return false;
            if (strlen($address) !== $size) continue;
            $whole = intdiv($bits, 8);
            $remaining = $bits % 8;
            if (substr($address, 0, $whole) !== substr($network, 0, $whole)) continue;
            if ($remaining && ((ord($address[$whole]) ^ ord($network[$whole])) & (0xff << (8 - $remaining)))) continue;
            $matched = true;
        }
        return $matched;
    }

    /**
     * Mode local uniquement — vérifie creds et démarre la session.
     *
     * @return bool true si login OK, false sinon
     */
    public static function login(string $username, string $password): bool
    {
        if (self::mode() !== self::MODE_LOCAL) return false;
        $user = User::verifyPassword($username, $password);
        if ($user === null) return false;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        // Régénération anti session-fixation
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['username'] = (string)$user['username'];
        return true;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Middleware : retourne true si la route doit être protégée et que l'utilisateur
     * n'est pas authentifié → l'appelant fait le redirect /login.
     *
     * Doit être appelé AVANT InitGuard (l'init wizard est public, mais l'app
     * elle-même est protégée).
     */
    /** UI endpoints happen to retain historical /api URLs; they are not PXE callbacks. */
    public static function isAdminApi(string $path): bool
    {
        return in_array($path, ['/api/init-status', '/api/init-retry', '/api/init-db-test', '/api/images-status', '/api/init-job-status'], true)
            || str_starts_with($path, '/api/init-retry-job/')
            || (bool)preg_match('~\A/api/machines/[^/]+/task-progress\z~D', $path);
    }

    public static function isProtected(string $path): bool
    {
        if ($path === '/init' || str_starts_with($path, '/init/') || self::isAdminApi($path)) {
            // An SSO-only installation may have no local users even after it is
            // configured. Its setup routes must still require the trusted proxy.
            if (self::mode() === self::MODE_AUTHENTIK) return self::user() === null;
            // Bootstrap is public only until an administrator exists. After that,
            // even a partial wizard contains private configuration and mutations.
            $configured = \Admin\Models\Setting::get('wizard_completed') === '1'
                || \Admin\Models\Setting::get('admin_created') === '1';
            try { $configured = $configured || User::count() > 0; } catch (\Throwable) { /* schema not created */ }
            return $configured && self::user() === null;
        }
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix) || $path === rtrim($prefix, '/')) {
                return false;
            }
        }
        return self::user() === null;
    }
}
