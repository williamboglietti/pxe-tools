<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Auth;
use Admin\Models\Setting;
use Admin\View;

/**
 * Routes d'authentification (Spec B Lot 1) :
 *   GET  /login       → form
 *   POST /login       → vérification creds → redirect dashboard ou re-render avec erreur
 *   POST /logout      → destroy session → redirect /login
 *
 * Mode `authentik` (Lot 3) : ces routes restent accessibles mais le login local
 * n'est pas utilisé — l'auth se fait via le forward-auth nginx (X-Forwarded-User).
 */
final class AuthController
{
    public function loginForm(array $params): void
    {
        // Si déjà authentifié → redirect dashboard
        if (Auth::user() !== null) {
            header('Location: ' . self::localNext($_GET['next'] ?? null));
            exit;
        }
        // Lot 3 Spec B : en mode authentik, le form /login n'a pas de sens
        // (auth gérée en amont par nginx auth_request). Si on tombe ici sans
        // X-Forwarded-User, c'est que le proxy auth est mal configuré.
        if (Auth::mode() === 'authentik') {
            http_response_code(401);
            echo "Mode authentik actif — accès SSO requis. Vérifiez la configuration nginx auth_request.\n";
            exit;
        }
        // partial() bypasse le layout (qui contient la nav + bouton logout) :
        // login.php est un HTML standalone autosuffisant.
        View::partial('auth/login', [
            'error' => $_GET['error'] ?? null,
            'next' => self::localNext($_GET['next'] ?? null),
            'created' => ($_GET['created'] ?? '') === '1',
        ]);
    }

    public function loginSubmit(array $params): void
    {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $next = self::localNext($_POST['next'] ?? $_GET['next'] ?? null);

        if ($username === '' || $password === '') {
            http_response_code(422);
            View::partial('auth/login', ['error' => 'missing', 'next' => $next]);
            return;
        }

        if (!Auth::login($username, $password)) {
            // Anti-bruteforce minimal : sleep aléatoire 200-500ms (rend le brute slow).
            usleep(random_int(200000, 500000));
            http_response_code(401);
            View::partial('auth/login', ['error' => 'invalid', 'next' => $next]);
            return;
        }

        // Login OK. Cas reprise du wizard (admin vient de créer son compte) :
        // wizard_completed != 1 → continuer le wizard sur /init/network.
        if (Setting::get(Setting::KEY_WIZARD_COMPLETED) !== '1') {
            header('Location: /init/network');
            exit;
        }

        header('Location: ' . $next);
        exit;
    }

    /** Only same-origin absolute paths; reject browser-normalized slashes and header controls. */
    private static function localNext(mixed $value): string
    {
        if (!is_string($value) || $value === '') return '/machines';
        $decoded = rawurldecode($value);
        if (!str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
            || str_contains($decoded, '\\') || preg_match('/[\x00-\x20\x7f]/', $decoded)
            || parse_url($decoded, PHP_URL_HOST) !== null) return '/machines';
        return $value;
    }

    public function logout(array $params): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }
}
