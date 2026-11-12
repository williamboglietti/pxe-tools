#!/usr/bin/env bash
# Tests unitaires — protection CSRF (audit F-03 : Admin\Csrf + View auto-injection).
# Usage : bash tests/unit/test_csrf.sh
# Exit 0 si tous OK, exit 1 dès un FAIL.
#
# Couvre :
#   - Csrf::token()       synchronizer 64-hex stable par session
#   - Csrf::tokenValid()  champ POST OU header X-CSRF-Token, hash_equals, rejets
#   - Csrf::originValid()  Origin/Referer vs HTTP_HOST + X-Forwarded-Host (tunnel)
#   - View::injectCsrf()  injecte dans <form method=post> same-origin uniquement

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SESS_DIR="/tmp/pxe-tools-test-csrf-sess-$$"
VIEWS="$APP_DIR/app/src/Views"
OUTER="$VIEWS/_csrftest_outer.php"
INNER="$VIEWS/_csrftest_inner.php"
trap 'rm -rf "$SESS_DIR" "$OUTER" "$INNER"' EXIT
mkdir -p "$SESS_DIR"

# Templates jetables pour prouver l'absence de double-injection en rendu imbriqué
# (anti-régression du garde de profondeur). Le outer a un form ET imbrique un
# partial qui a lui aussi un form → on attend 1 champ csrf par form, pas 2.
cat > "$INNER" <<'PHP'
<form method="post" action="/inner"><button>i</button></form>
PHP
cat > "$OUTER" <<'PHP'
<div><form method="post" action="/outer"><button>o</button></form></div>
<?php \Admin\View::partial('_csrftest_inner'); ?>
PHP

php -d session.save_path="$SESS_DIR" -r '
require $argv[1] . "/app/src/Csrf.php";
require $argv[1] . "/app/src/Auth.php";
require $argv[1] . "/app/src/View.php";

use Admin\Csrf;
use Admin\View;

session_start();

$pass = 0; $fail = 0;
$ok   = function (string $t) use (&$pass) { echo "OK  $t\n"; $pass++; };
$ko   = function (string $t, string $d = "") use (&$fail) { echo "FAIL $t\n"; if ($d) echo "     $d\n"; $fail++; };
$is   = function (bool $cond, string $t, string $d = "") use ($ok, $ko) { $cond ? $ok($t) : $ko($t, $d); };

// --- helper : reset état requête ---
$reset = function () {
    $_POST = [];
    foreach (["HTTP_X_CSRF_TOKEN","HTTP_ORIGIN","HTTP_REFERER","HTTP_HOST","HTTP_X_FORWARDED_HOST","HTTP_X_FORWARDED_FOR","REMOTE_ADDR"] as $k) unset($_SERVER[$k]);
};

// ============================ Csrf::token ============================
$t1 = Csrf::token();
$is((bool)preg_match("/^[0-9a-f]{64}$/", $t1), "token() = 64 hex", "got=$t1");
$is(Csrf::token() === $t1, "token() stable dans la session");

// ============================ Csrf::tokenValid ============================
$reset();
$_POST[Csrf::FIELD] = $t1;
$is(Csrf::tokenValid() === true, "tokenValid : POST csrf_token correct");

$reset();
$_POST[Csrf::FIELD] = "deadbeef";
$is(Csrf::tokenValid() === false, "tokenValid : POST csrf_token incorrect rejeté");

$reset();
$_SERVER["HTTP_X_CSRF_TOKEN"] = $t1;
$is(Csrf::tokenValid() === true, "tokenValid : header X-CSRF-Token correct (HTMX)");

$reset();
$is(Csrf::tokenValid() === false, "tokenValid : aucun token fourni rejeté");

// token de session absent → toujours rejeté même si la requête en fournit un
$reset();
$saved = $_SESSION["csrf_token"];
unset($_SESSION["csrf_token"]);
$_POST[Csrf::FIELD] = $saved;
$is(Csrf::tokenValid() === false, "tokenValid : pas de token en session => rejet");
$_SESSION["csrf_token"] = $saved;

// ============================ Csrf::originValid ============================
$reset();
$_SERVER["HTTP_HOST"] = "pxe.local:8443";
$_SERVER["HTTP_ORIGIN"] = "https://pxe.local";
$is(Csrf::originValid() === true, "originValid : Origin same-host (port ignoré)");

$reset();
$_SERVER["HTTP_HOST"] = "pxe.local:8443";
$_SERVER["HTTP_ORIGIN"] = "https://evil.example";
$is(Csrf::originValid() === false, "originValid : Origin host étranger rejeté");

$reset();
$_SERVER["HTTP_HOST"] = "pxe.local";
$_SERVER["HTTP_REFERER"] = "https://pxe.local/machines";
$is(Csrf::originValid() === true, "originValid : fallback Referer same-host");

$reset();
$_SERVER["HTTP_HOST"] = "pxe.local";
$_SERVER["HTTP_REFERER"] = "https://evil.example/x";
$is(Csrf::originValid() === false, "originValid : Referer host étranger rejeté");

// Local auth and SSO share the same explicit peer trust boundary. The
// forwarding proxy must itself sanitize the headers from its clients.
$reset();
putenv("AUTH_MODE=local");
putenv("AUTHENTIK_TRUSTED_PROXY_CIDRS");
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "192.0.2.7";
$_SERVER["HTTP_X_FORWARDED_HOST"] = "pxe.tunnel.example";
$_SERVER["HTTP_ORIGIN"] = "https://pxe.tunnel.example";
$is(Csrf::originValid() === false, "originValid : proxy non configuré rejeté");
putenv("AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.8/32");
$_SERVER["HTTP_X_FORWARDED_FOR"] = "192.0.2.8";
$is(Csrf::originValid() === false, "originValid : source déclarée falsifiée ignorée");
$_POST[Csrf::FIELD] = $t1;
$is(Csrf::requestAllowed("POST", "/settings") === false, "mutation avec jeton et proxy non fiable rejetée");
putenv("AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.7/32");
$is(Csrf::originValid() === true, "originValid : hôte transmis par pair fiable accepté");
$is(Csrf::requestAllowed("POST", "/settings") === true, "mutation authentification locale via proxy fiable et jeton acceptée");
unset($_POST[Csrf::FIELD]);
$is(Csrf::requestAllowed("POST", "/settings") === false, "proxy fiable sans jeton rejeté");
putenv("AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.7/32,invalid");
$is(Csrf::originValid() === false, "originValid : liste de confiance partiellement invalide rejetée");
putenv("AUTHENTIK_TRUSTED_PROXY_CIDRS=0.0.0.0/0");
$is(Csrf::originValid() === false, "originValid : tout Internet comme proxy rejeté");
putenv("AUTHENTIK_TRUSTED_PROXY_CIDRS=2001:db8::/48");
$_SERVER["REMOTE_ADDR"] = "2001:db8::7";
$is(Csrf::originValid() === true, "originValid : proxy IPv6 autorisé accepté");
$_SERVER["REMOTE_ADDR"] = "2001:db9::7";
$is(Csrf::originValid() === false, "originValid : proxy IPv6 hors réseau rejeté");
putenv("AUTHENTIK_TRUSTED_PROXY_CIDRS");

$reset();
$_SERVER["HTTP_HOST"] = "pxe.local";
$is(Csrf::originValid() === true, "originValid : ni Origin ni Referer => non bloquant (token primaire)");

// ============================ View::injectCsrf ============================
$ref = new ReflectionMethod(View::class, "injectCsrf");  // privé accessible via Reflection (PHP >= 8.1)
$inject = fn (string $h): string => (string) $ref->invoke(null, $h);

$needle = "name=\"" . Csrf::FIELD . "\"";

$h = $inject("<form method=\"post\" action=\"/machines/1/wipe\"><button>x</button></form>");
$is(str_contains($h, $needle) && str_contains($h, $t1), "injectCsrf : champ ajouté dans <form method=post>", $h);

$h = $inject("<form method=\"get\" action=\"/search\"><input></form>");
$is(!str_contains($h, $needle), "injectCsrf : pas d injection dans <form method=get>", $h);

$h = $inject("<form method=\"post\" action=\"https://evil.example/steal\"></form>");
$is(!str_contains($h, $needle), "injectCsrf : pas d injection si action externe (anti-fuite)", $h);

$h = $inject("<form method=\"post\" action=\"//evil.example/steal\"></form>");
$is(!str_contains($h, $needle), "injectCsrf : pas d injection si action //host externe", $h);

$h = $inject("<form ACTION=\"/x\" METHOD=POST>");
$is(str_contains($h, $needle), "injectCsrf : casse/guillemets tolérés (METHOD=POST)", $h);

$h = $inject("<div>texte sans form</div>");
$is($h === "<div>texte sans form</div>", "injectCsrf : HTML sans form inchangé");

$n = substr_count($inject("<form method=post action=/a></form><form method=post action=/b></form>"), $needle);
$is($n === 2, "injectCsrf : injecte dans chaque form (n=$n attendu 2)");

// ============================ Csrf::requestAllowed (décision middleware) ============================
// Contexte d origine same-host pour les cas légitimes.
$reset();
$_SERVER["HTTP_HOST"]   = "pxe.local";
$_SERVER["HTTP_ORIGIN"] = "https://pxe.local";

$is(Csrf::requestAllowed("GET",  "/machines") === true,  "requestAllowed : GET autorisé (méthode sûre)");
$is(Csrf::requestAllowed("HEAD", "/machines") === true,  "requestAllowed : HEAD autorisé");
$is(Csrf::requestAllowed("POST", "/api/discovery") === true,           "requestAllowed : POST /api/ exempté (client PXE)");
$is(Csrf::requestAllowed("POST", "/api/internal/checkpoint") === true, "requestAllowed : POST /api/internal exempté");

// Mutations hors /api/ SANS token → rejet (chemin 403).
$is(Csrf::requestAllowed("POST",   "/machines/1/wipe") === false,      "requestAllowed : POST /machines/1/wipe sans token => 403");
$is(Csrf::requestAllowed("POST",   "/settings/factory-reset") === false, "requestAllowed : POST factory-reset sans token => 403");
$is(Csrf::requestAllowed("DELETE", "/machines/1") === false,           "requestAllowed : DELETE réel sans token => 403");
$is(Csrf::requestAllowed("POST",   "/presets/5") === false,            "requestAllowed : POST (form _method=DELETE) sans token => 403");

// Mutation hors /api/ AVEC token valide + origine same-host → autorisé.
$_POST[Csrf::FIELD] = $t1;
$is(Csrf::requestAllowed("POST", "/machines/1/wipe") === true,   "requestAllowed : POST + token valide + origine OK");
$is(Csrf::requestAllowed("PUT",  "/machines/1") === true,        "requestAllowed : PUT + token valide + origine OK");

// Token valide mais origine étrangère → rejet (défense complémentaire).
$_SERVER["HTTP_ORIGIN"] = "https://evil.example";
$is(Csrf::requestAllowed("POST", "/machines/1/wipe") === false,  "requestAllowed : token valide mais origine étrangère => 403");

// ===================== Auto-injection end-to-end (anti double-injection) =====================
// Rendu imbriqué : outer (form /outer) qui appelle partial(inner) (form /inner).
ob_start();
View::partial("_csrftest_outer");
$nested = (string) ob_get_clean();
$cn = substr_count($nested, $needle);
$is($cn === 2, "render imbriqué : 1 champ par form, pas de double-injection (n=$cn attendu 2)", $nested);

// Rendu autonome (fragment HTMX) : 1 form → 1 champ.
ob_start();
View::partial("_csrftest_inner");
$standalone = (string) ob_get_clean();
$cs = substr_count($standalone, $needle);
$is($cs === 1, "partial autonome : champ injecté exactement une fois (n=$cs attendu 1)", $standalone);

echo "\n--- $pass OK / $fail FAIL ---\n";
exit($fail === 0 ? 0 : 1);
' "$APP_DIR"
