<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Models\Action;
use Admin\Models\ActionCheckpoints;
use Admin\Models\Installation;
use Admin\Models\Machine;
use Admin\Models\MachineEvent;
use Admin\Models\PxeBootObservation;
use Admin\Models\Setting;
use Admin\Util\NetworkDetector;
use Admin\Db;

/**
 * InternalApiController — endpoints internes pour la rivière de checkpoints.
 *
 * Trois endpoints, tous sans auth user (les clients sont les machines
 * PXE/preseed/postboot et le worker Python local, pas un humain) :
 *
 *   - POST /api/internal/checkpoint   : ping checkpoint authentifié par token
 *   - POST /api/internal/dhcp-event   : signal DHCP émis par le hook dnsmasq
 *   - GET  /postboot/{id}/postboot.sh : sert le script postboot authentifié MAC↔IP
 *
 * POURQUOI controller dédié (vs ajouter à PxeController) :
 *   1. Séparation de responsabilité — PxeController gère le flow PXE legacy
 *      (preseed.php, grub.php, install-start, wipe-progress…). Les
 *      checkpoints sont une couche d'observabilité orthogonale.
 *   2. PxeController fait déjà 554 lignes. Y ajouter le filtre IP CIDR +
 *      redaction token + résolution DHCP-event aurait dilué la responsabilité.
 *   3. Les endpoints internes ont des contraintes sécurité distinctes
 *      (réponse TOUJOURS 200 vide, filtre IP strict) — meilleure
 *      encapsulation isolée.
 *
 * Spec : docs/superpowers/specs/2026-05-16-actions-checkpoints-tracking-design.md
 */
final class InternalApiController
{
    // No implicit network is trusted. An additional bridge CIDR must be configured explicitly.
    private const DOCKER_INTERNAL_CIDR_DEFAULT = '';
    private const SETTING_PXE_SUBNET = 'pxe_subnet';

    /** Setting clé : CIDR Docker interne accepté pour le hook DHCP. */
    private const SETTING_DOCKER_CIDR = 'docker_internal_cidr';

    /**
     * Racine FS hors document root nginx où le worker dépose les scripts
     * postboot rendus (SEC-01). Servis ici par servePostboot() après
     * vérification REMOTE_ADDR vs dernière IP DHCP loggée pour la MAC
     * de l'action. Override possible via env POSTBOOT_OUTPUT_DIR.
     */
    private const POSTBOOT_OUTPUT_DIR_DEFAULT = '/var/lib/pxe-tools/postboot';

    /**
     * TTL (secondes) pour considérer l'IP DHCP loggée encore valide vis-à-vis
     * de la machine qui télécharge le postboot. 15 minutes couvre largement le
     * délai DHCP → preseed late_command → premier wget postboot.
     */
    private const POSTBOOT_DHCP_TTL_S = 900;

    /** Body max accepté sur les endpoints checkpoint (SEC-05). 4KB suffit largement. */
    private const BODY_MAX_BYTES = 4096;

    /**
     * Body max accepté sur appendInstallLog (LOGCAP01). 256KB couvre largement
     * un batch de ~50 lignes syslog d-i (~2-5KB par ligne worst-case).
     * Au-delà → 413 Payload Too Large (signal que le buffer côté target est
     * cassé, pas qu'on l'absorbe silencieusement).
     */
    private const INSTALL_LOG_CHUNK_MAX = 262144;

    /**
     * POST /api/internal/checkpoint?mac=...&code=...
     * Header obligatoire : `Authorization: Bearer <token>` (SEC-02).
     *
     * Validation séquentielle :
     *   1. IP source ∈ {pxe_subnet, loopback, docker_cidr}
     *   2. token (header) existe dans actions ET status ∉ {success, failed}
     *   3. mac normalisée == action.machine_id
     *   4. code ∈ catalogue pour action.type
     *   5. INSERT machine_events (machine_id, action_id, checkpoint, message)
     *
     * Sources légitimes :
     *   - Machines clientes (IP ∈ pxe_subnet) : checkpoints depuis preseed/d-i
     *   - Worker.py interne (IP loopback 127.0.0.1) : checkpoints synthétiques
     *     post-install (os_first_boot_assumed, ansible_started, ansible_finished
     *     avec level=error) — refactor event-driven 2026-05-19.
     *   - Containers Docker (IP ∈ docker_cidr) : si jamais on déporte un job
     *     dans un sidecar futur.
     *
     * Réponse TOUJOURS 200 vide (spec §Échec silencieux). Tout échec
     * validation → error_log avec contexte (token redact 8 chars max),
     * pas de leak via la réponse HTTP.
     */
    public function checkpoint(array $params): void
    {
        // CODE-01 : wrapper le corps complet — un endpoint interne ne doit
        // JAMAIS leak une 500 vers un worker preseed (sinon wget casse et
        // l'installation rate).
        try {
            $remoteIp = $this->remoteIp();
            $token    = $this->readBearerToken();
            $macRaw   = (string)($_GET['mac']  ?? '');
            $code     = (string)($_GET['code'] ?? '');

            // Filtre IP : pxe_subnet (machines clientes) OU loopback / docker_cidr
            // (worker.py interne au container). Le token Bearer reste l'autorité
            // d'authentification — pas de relâchement réel de la sécurité.
            $pxeSubnet  = Setting::get(self::SETTING_PXE_SUBNET, '') ?? '';
            $dockerCidr = Setting::get(self::SETTING_DOCKER_CIDR, self::DOCKER_INTERNAL_CIDR_DEFAULT)
                       ?? self::DOCKER_INTERNAL_CIDR_DEFAULT;
            $ipOk = $this->isLoopback($remoteIp)
                 || $this->ipInCidr($remoteIp, $pxeSubnet)
                 || $this->ipInCidr($remoteIp, $dockerCidr);
            if (!$ipOk) {
                $this->logRejection('checkpoint', 'ip_out_of_subnet', $remoteIp, $token, $code);
                $this->respond200();
                return;
            }

            // Token : non vide + lookup.
            if ($token === '') {
                $this->logRejection('checkpoint', 'token_missing', $remoteIp, $token, $code);
                $this->respond200();
                return;
            }
            $action = Action::findByToken($token);
            if ($action === null) {
                $this->logRejection('checkpoint', 'token_not_found', $remoteIp, $token, $code);
                $this->respond200();
                return;
            }

            // Action déjà terminale → reject (un ping retardé ne doit pas
            // ré-écrire une checklist déjà fermée).
            if (in_array($action['status'], Action::STATUSES_TERMINAL, true)) {
                $this->logRejection('checkpoint', 'action_terminal', $remoteIp, $token, $code);
                $this->respond200();
                return;
            }

            // MAC : normalisation + match avec action.machine_id.
            $mac = Machine::normalizeMac($macRaw);
            if ($mac === '') {
                $this->logRejection('checkpoint', 'mac_invalid', $remoteIp, $token, $code);
                $this->respond200();
                return;
            }
            $machine = Machine::findByAnyMac($mac);
            if ($machine === null || (int)$machine['id'] !== (int)$action['machine_id']) {
                $this->logRejection('checkpoint', 'mac_mismatch', $remoteIp, $token, $code);
                $this->respond200();
                return;
            }

            // Code : doit appartenir au catalogue du type d'action.
            if (!ActionCheckpoints::validCode((string)$action['type'], $code)) {
                $this->logRejection('checkpoint', 'code_invalid', $remoteIp, $token, $code);
                $this->respond200();
                return;
            }

            // Tout est OK : INSERT event.
            // SEC-05 : body capé 4KB côté lecture (drop silencieux si dépassement).
            $body = file_get_contents('php://input', false, null, 0, self::BODY_MAX_BYTES);
            $payload = null;
            if (is_string($body) && $body !== '' && strlen($body) <= self::BODY_MAX_BYTES) {
                $parsed = json_decode($body, true);
                if (is_array($parsed)) {
                    $payload = $parsed;
                }
            }
            if ($code === 'os_first_boot') $payload = $this->firstBootReceiptPayload($payload ?? [], $remoteIp);
            $message = $payload !== null
                ? 'checkpoint:' . $code . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : 'checkpoint:' . $code;

            // Niveau optionnel transmis par le worker Python (ex: level=error
            // pour ansible_finished quand rc!=0). Whitelist stricte : seul 'error'
            // est accepté pour éviter des injections de niveaux arbitraires.
            $levelParam = (string)($_GET['level'] ?? '');
            $level = ($levelParam === MachineEvent::LEVEL_ERROR)
                ? MachineEvent::LEVEL_ERROR
                : MachineEvent::LEVEL_INFO;

            MachineEvent::log(
                (int)$action['machine_id'],
                $level,
                $message,
                $code,
                (int)$action['id']
            );

            // POURQUOI passer en running au 1er checkpoint : la création d'une
            // action laisse status='pending' tant qu'aucun sous-job n'a démarré.
            // Un premier checkpoint signifie que la machine a passé l'étape de
            // déclenchement (DHCP request, preseed loaded…) → on bascule running
            // pour que l'UI active le polling. Pas de transition arrière vers
            // pending (le hook recomputeEndState reste source de vérité pour les
            // transitions terminales).
            //
            // SEC-04 : UPDATE conditionnel atomique single-query. Élimine la
            // race condition lecture→écriture (2 pings concurrents ne peuvent
            // pas ressusciter une action terminale).
            $upd = Db::get()->prepare(
                "UPDATE actions SET status = ?, ended_at = NULL "
              . "WHERE id = ? AND status = ?"
            );
            $upd->execute([Action::STATUS_RUNNING, (int)$action['id'], Action::STATUS_PENDING]);
            // rowCount best-effort : 0 = pas pending (déjà transité ailleurs),
            // 1 = transition appliquée. Pas d'erreur dans les deux cas.

            $this->respond200();
        } catch (\Throwable $e) {
            error_log('[internal-api] checkpoint failed: ' . $e->getMessage());
            $this->respond200();
        }
    }

    /** Server provenance cannot be supplied or overwritten by the client body. */
    private function firstBootReceiptPayload(array $payload, string $remoteIp): array
    {
        $payload['_observed_remote_ip'] = $remoteIp;
        $payload['_received_at_unix'] = time();
        $payload['_proxy_headers_seen'] = !empty($_SERVER['HTTP_FORWARDED'])
            || !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP']);
        return $payload;
    }

    /**
     * POST /api/internal/dhcp-event?mac=...&ip=...
     *
     * Appelé par le hook dnsmasq du container (docker/dnsmasq/dhcp-hook.sh).
     * IP source obligatoirement loopback OU réseau Docker interne. JAMAIS
     * pxe_subnet (ce serait une machine cliente — une cliente ne doit pas
     * pouvoir falsifier les events DHCP).
     *
     * Cherche une action en cours pour la MAC. Si oui ET de type wipe/install,
     * résout le checkpoint (dhcp_request_wipe vs dhcp_request_install) selon
     * la phase courante (présence d'event wipe_reboot_signaled/wipe_poweroff).
     *
     * Pas d'action en cours → ignore silencieux. Réponse toujours 200 vide.
     */
    public function dhcpEvent(array $params): void
    {
        // CODE-01 : wrapper le corps complet — pareil contrat que checkpoint().
        try {
            $remoteIp = $this->remoteIp();
            $macRaw   = (string)($_GET['mac'] ?? '');
            $ip       = (string)($_GET['ip']  ?? '');

            // Filtre IP : loopback ou Docker interne uniquement.
            if (!$this->isLoopback($remoteIp)) {
                $dockerCidr = Setting::get(self::SETTING_DOCKER_CIDR, self::DOCKER_INTERNAL_CIDR_DEFAULT)
                    ?? self::DOCKER_INTERNAL_CIDR_DEFAULT;
                if (!$this->ipInCidr($remoteIp, $dockerCidr)) {
                    $this->logRejection('dhcp-event', 'ip_not_local', $remoteIp, '', '');
                    $this->respond200();
                    return;
                }
            }

            $mac = Machine::normalizeMac($macRaw);
            if ($mac === '') {
                $this->respond200();
                return;
            }

            $machine = Machine::findByAnyMac($mac);
            if ($machine === null) {
                // Early registration : matérialise la machine en DB dès le
                // premier signal DHCP (avant que discover.sh n'ait fini son
                // inventaire ~5-10s plus tard). Status 'inventorying' (badge
                // running visible dans /machines) → discover.sh basculera
                // vers 'discovered' une fois l'inventaire posté via
                // /api/discovery (qui fait UPSERT, cf. PxeController:141).
                try {
                    $newId = Machine::create([
                        'mac'          => $mac,
                        'status'       => 'inventorying',
                        'last_seen_at' => date('Y-m-d H:i:s'),
                    ]);
                    MachineEvent::log(
                        $newId,
                        MachineEvent::LEVEL_INFO,
                        "Machine vue en DHCP (IP $ip) — en attente de discover.sh"
                    );
                    $this->recordPxeBoot($newId, $mac, $ip);
                } catch (\Throwable $e) {
                    // Race condition (2 DHCP simultanés sur même MAC), peu
                    // probable. Le 2e create lève une exception unique
                    // constraint — silencieux côté hook.
                    error_log('[internal-api] dhcpEvent early-reg failed: ' . $e->getMessage());
                }
                $this->respond200();
                return;
            }
            $machineId = (int)$machine['id'];
            $this->recordPxeBoot($machineId, $mac, $ip);

            // Cherche action en cours (running ou pending) pour la machine.
            // On prend la plus récente (cas reinstall : 2 actions chainable mais
            // 1 seule running à la fois).
            $stmt = Db::get()->prepare(
                "SELECT id, type FROM actions "
              . "WHERE machine_id = ? AND status IN ('running', 'pending') "
              . "ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$machineId]);
            $row = $stmt->fetch();

            if (!$row) {
                // Pas d'action en cours → silencieux (machine peut booter pour
                // d'autres raisons : rescue, manuel…).
                $this->respond200();
                return;
            }
            $actionId = (int)$row['id'];
            $type     = (string)$row['type'];

            // CODE-03 : pour disktest on suit dhcp_request_disktest. Discovery
            // n'a pas de checkpoint DHCP catalogué (catalogue vide — à définir
            // en Worker_INSTR). Skip silencieux via la garde validCode() ci-dessous.
            $checkpoint = null;
            if ($type === Action::TYPE_REINSTALL) {
                $checkpoint = $this->hasWipeFinishedEvent($actionId)
                    ? 'dhcp_request_install'
                    : 'dhcp_request_wipe';
            } elseif ($type === Action::TYPE_INSTALL_ONLY) {
                $checkpoint = 'dhcp_request_install';
            } elseif ($type === Action::TYPE_WIPE_ONLY) {
                $checkpoint = 'dhcp_request_wipe';
            } elseif ($type === Action::TYPE_DISKTEST) {
                $checkpoint = 'dhcp_request_disktest';
            }

            // Si le code n'est pas dans le catalogue (ex: discovery sans
            // checkpoint catalogué), on skip silencieusement.
            if ($checkpoint === null || !ActionCheckpoints::validCode($type, $checkpoint)) {
                $this->respond200();
                return;
            }

            $message = 'checkpoint:' . $checkpoint . ' ' . json_encode(
                ['dhcp_ip' => $ip],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            MachineEvent::log(
                $machineId,
                MachineEvent::LEVEL_INFO,
                $message,
                $checkpoint,
                $actionId
            );

            $this->respond200();
        } catch (\Throwable $e) {
            error_log('[internal-api] dhcpEvent failed: ' . $e->getMessage());
            $this->respond200();
        }
    }

    /**
     * POST /api/internal/dnsmasq-event — appelé par le sidecar dnsmasq-tail
     * qui parse les logs dnsmasq en mode proxy DHCP (où le hook dhcp-script
     * n'est pas invoqué). Mêmes garanties que dhcpEvent (200 toujours, filtre
     * IP loopback strict, early-reg machine inconnue).
     *
     * Types d'event acceptés :
     *   - type=dhcp_proxy : événement PXE proxy DHCP (équivalent fonctionnel
     *     de dhcpEvent : early-reg + checkpoint dhcp_request_*)
     *   - type=tftp_serve : fichier servi par TFTP. Lookup machine via la
     *     dernière dhcp_request_* avec IP matchant (TTL 5 min). Émet
     *     grub_config_served_wipe/install si le fichier est un bootloader
     *     reconnu (lpxelinux.0, grubnetx64.efi, grubnetaa64.efi).
     */
    public function dnsmasqEvent(array $params): void
    {
        try {
            $remoteIp = $this->remoteIp();
            $type     = (string)($_GET['type'] ?? $_POST['type'] ?? '');
            $macRaw   = (string)($_GET['mac']  ?? $_POST['mac']  ?? '');
            $ip       = (string)($_GET['ip']   ?? $_POST['ip']   ?? '');
            $file     = (string)($_GET['file'] ?? $_POST['file'] ?? '');

            // Filtre IP strict : loopback ou Docker interne (jamais subnet PXE,
            // car le sidecar tourne dans le même container que ce controller).
            if (!$this->isLoopback($remoteIp)) {
                $dockerCidr = Setting::get(self::SETTING_DOCKER_CIDR, self::DOCKER_INTERNAL_CIDR_DEFAULT)
                    ?? self::DOCKER_INTERNAL_CIDR_DEFAULT;
                if (!$this->ipInCidr($remoteIp, $dockerCidr)) {
                    $this->logRejection('dnsmasq-event', 'ip_not_local', $remoteIp, '', '');
                    $this->respond200();
                    return;
                }
            }

            if ($type === 'dhcp_proxy') {
                $this->handleDnsmasqDhcpProxy($macRaw, $ip);
            } elseif ($type === 'tftp_serve') {
                $this->handleDnsmasqTftpServe($ip, $file);
            }
            // Type inconnu = silencieux (forward compat si on ajoute d'autres
            // events plus tard).

            $this->respond200();
        } catch (\Throwable $e) {
            error_log('[internal-api] dnsmasqEvent failed: ' . $e->getMessage());
            $this->respond200();
        }
    }

    /**
     * POST /api/internal/action/{id}/recompute — déclenche manuellement
     * Action::recomputeEndState pour une action donnée.
     *
     * POURQUOI : le worker Python met à jour installations.ansible_status
     * directement en SQL (update_install) pour les états terminaux
     * (success | failed | no_role), court-circuitant
     * Installation::setAnsibleStatus côté PHP qui appellerait normalement
     * recomputeEndState. Sans cet endpoint, l'action parente reste 'running'
     * indéfiniment alors que tous les sous-jobs sont terminaux.
     *
     * Filtre IP : loopback OU subnet Docker interne (le worker tourne dans
     * le même container que ce controller, donc cgnat 127.0.0.1 attendu ;
     * fallback CIDR docker pour le cas où la route compose réseau interne
     * change).
     */
    public function recomputeAction(array $params): void
    {
        try {
            $remoteIp = $this->remoteIp();
            if (!$this->isLoopback($remoteIp)) {
                $dockerCidr = Setting::get(self::SETTING_DOCKER_CIDR, self::DOCKER_INTERNAL_CIDR_DEFAULT)
                    ?? self::DOCKER_INTERNAL_CIDR_DEFAULT;
                if (!$this->ipInCidr($remoteIp, $dockerCidr)) {
                    $this->logRejection('recompute-action', 'ip_not_local', $remoteIp, '', '');
                    $this->respond200();
                    return;
                }
            }
            $actionId = isset($params['id']) ? (int)$params['id'] : 0;
            if ($actionId > 0) {
                Action::recomputeEndState($actionId);
            }
            $this->respond200();
        } catch (\Throwable $e) {
            error_log('[internal-api] recomputeAction failed: ' . $e->getMessage());
            $this->respond200();
        }
    }

    /**
     * POST /api/internal/install-log?install_id=<id>&seq=<n>
     * Header obligatoire : `Authorization: Bearer <token>` (actions.token).
     * Body : text/plain — chunk de log syslog d-i, max 256KB (sinon 413).
     *
     * LOGCAP01 — capture batch du log debian-installer côté serveur. Posté
     * depuis le late_command preseed via `wget --post-file` toutes les ~1s
     * (whichever-first 1s OU 50 lignes).
     *
     * Validation séquentielle :
     *   1. IP source dans pxe_subnet (cohérent checkpoint endpoint).
     *   2. Token (header) → résolution Action.
     *   3. Action non terminale (410 sinon — évite tampering post-fact).
     *   4. install_id appartient bien à l'action (JOIN installations × actions).
     *   5. Body ≤ 256KB (413 sinon).
     *   6. Append via Installation::appendInstallLog (truncate gracieux à 5MB).
     *
     * Différences vs `checkpoint()` :
     *   - PAS de réponse 200 vide systématique : on retourne un statut JSON
     *     (ok|truncated) pour permettre au client de détecter le saturate et
     *     stopper le tail (économise réseau pour les installs de plusieurs
     *     heures avec log verbose après le 5MB cap).
     *   - 401/410/413 explicites côté HTTP (vs silent 200) — le client ici
     *     est un wget côté preseed, on peut se permettre d'être verbeux car
     *     le retour ne casse pas l'install (--max-time + || true côté shell).
     *   - Pas de leak token dans error_log (redact 8 chars max, pareil que
     *     logRejection).
     */
    public function appendInstallLog(array $params): void
    {
        try {
            $remoteIp  = $this->remoteIp();
            $token     = $this->readBearerToken();
            $installId = isset($_GET['install_id']) ? (int)$_GET['install_id'] : 0;
            $seq       = isset($_GET['seq'])        ? (int)$_GET['seq']        : 0;

            // Filtre IP : reject si hors pxe_subnet.
            $pxeSubnet = Setting::get(self::SETTING_PXE_SUBNET, '') ?? '';
            if (!$this->ipInCidr($remoteIp, $pxeSubnet)) {
                $this->logRejection('install-log', 'ip_out_of_subnet', $remoteIp, $token, (string)$installId);
                $this->respondJson(401, ['error' => 'unauthorized']);
                return;
            }

            // Validation token : non vide.
            if ($token === '') {
                $this->logRejection('install-log', 'token_missing', $remoteIp, $token, (string)$installId);
                $this->respondJson(401, ['error' => 'invalid token']);
                return;
            }
            if ($installId <= 0) {
                $this->logRejection('install-log', 'install_id_invalid', $remoteIp, $token, (string)$installId);
                $this->respondJson(400, ['error' => 'install_id required']);
                return;
            }

            // Résolution Action + binding install_id ↔ action.
            // hash_equals via Action::findByToken (qui doit déjà être timing-safe ;
            // si pas, c'est un finding pour une autre passe — pas pour LOGCAP01).
            $action = Action::findByToken($token);
            if ($action === null) {
                $this->logRejection('install-log', 'token_not_found', $remoteIp, $token, (string)$installId);
                $this->respondJson(401, ['error' => 'invalid token']);
                return;
            }

            // Action terminale → 410 Gone (évite tampering log post-fact).
            if (in_array($action['status'], Action::STATUSES_TERMINAL, true)) {
                $this->logRejection('install-log', 'action_terminal', $remoteIp, $token, (string)$installId);
                $this->respondJson(410, ['error' => 'action terminated']);
                return;
            }

            // Binding install_id ↔ action_id : on lit la row installation et on
            // vérifie qu'elle pointe sur l'action portée par le token.
            $stmt = Db::get()->prepare('SELECT id, action_id FROM installations WHERE id = ?');
            $stmt->execute([$installId]);
            $inst = $stmt->fetch();
            // Release SQLite's read snapshot before appendInstallLog acquires
            // BEGIN IMMEDIATE; otherwise a competing writer can make the
            // transaction upgrade fail immediately despite busy_timeout.
            $stmt->closeCursor();
            if (!$inst || (int)$inst['action_id'] !== (int)$action['id']) {
                $this->logRejection('install-log', 'install_action_mismatch', $remoteIp, $token, (string)$installId);
                $this->respondJson(401, ['error' => 'invalid token']);
                return;
            }

            // Read body (limite 256KB pour éviter d'allouer une string géante).
            $body = file_get_contents('php://input', false, null, 0, self::INSTALL_LOG_CHUNK_MAX + 1);
            $body = is_string($body) ? $body : '';
            if (strlen($body) > self::INSTALL_LOG_CHUNK_MAX) {
                $this->logRejection('install-log', 'chunk_too_large', $remoteIp, $token, (string)$installId);
                $this->respondJson(413, ['error' => 'chunk too large']);
                return;
            }
            if ($body === '') {
                // Pas une erreur — on accepte un POST vide (le client a appelé
                // sans rien dans le buffer, cas race condition flush vide).
                $this->respondJson(200, [
                    'status'   => 'ok',
                    'bytes'    => 0,
                    'appended' => 0,
                    'seq'      => $seq,
                ]);
                return;
            }

            // Append. error_log côté model si truncate (pas ici, évite dup).
            $result = Installation::appendInstallLog($installId, $body);

            $this->respondJson(200, [
                'status'   => $result['status'],
                'bytes'    => $result['bytes'],
                'appended' => $result['appended'],
                'seq'      => $seq,
            ]);
        } catch (\Throwable $e) {
            error_log('[internal-api] appendInstallLog failed: ' . $e->getMessage());
            $this->respondJson(500, ['error' => 'internal error']);
        }
    }

    /**
     * Sous-handler : équivalent fonctionnel de dhcpEvent (early-reg +
     * checkpoint dhcp_request_*) pour les events PXE proxy DHCP parsés des
     * logs dnsmasq par le sidecar.
     */
    private function handleDnsmasqDhcpProxy(string $macRaw, string $ip): void
    {
        $mac = Machine::normalizeMac($macRaw);
        if ($mac === '') return;

        $machine = Machine::findByAnyMac($mac);
        if ($machine === null) {
            try {
                $newId = Machine::create([
                    'mac'          => $mac,
                    'status'       => 'inventorying',
                    'last_seen_at' => date('Y-m-d H:i:s'),
                ]);
                MachineEvent::log(
                    $newId,
                    MachineEvent::LEVEL_INFO,
                    "Machine vue en DHCP proxy" . ($ip !== '' ? " (IP $ip)" : '') . " — en attente de discover.sh"
                );
                $this->recordPxeBoot($newId, $mac, $ip);
            } catch (\Throwable $e) {
                error_log('[internal-api] dnsmasq-tail early-reg failed: ' . $e->getMessage());
            }
            return;
        }
        $machineId = (int)$machine['id'];
        $this->recordPxeBoot($machineId, $mac, $ip);

        // Cherche action en cours pour la machine, comme dhcpEvent.
        $stmt = Db::get()->prepare(
            "SELECT id, type FROM actions "
          . "WHERE machine_id = ? AND status IN ('running', 'pending') "
          . "ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$machineId]);
        $row = $stmt->fetch();
        if (!$row) return;

        $actionId = (int)$row['id'];
        $type     = (string)$row['type'];

        $checkpoint = null;
        if ($type === Action::TYPE_REINSTALL) {
            if ($this->hasWipeFinishedEvent($actionId)) {
                // REINSTCKPT01 (Fix Q) — Distinction boot post-wipe→install vs boot
                // post-install : si install_status='success', la Debian a déjà fini
                // d'installer et ce DHCP est le reboot post-install → checkpoint
                // install_reboot_done (signal terminal capté par dnsmasq-tail).
                // Sinon, c'est le boot normal vers l'installateur.
                $checkpoint = $this->hasInstallSuccessEvent($actionId)
                    ? 'install_reboot_done'
                    : 'dhcp_request_install';
            } else {
                $checkpoint = 'dhcp_request_wipe';
            }
        } elseif ($type === Action::TYPE_INSTALL_ONLY) {
            // REINSTCKPT01 (Fix Q) — même logique : si install terminée, reboot
            // post-install → install_reboot_done ; sinon boot normal vers install.
            $checkpoint = $this->hasInstallSuccessEvent($actionId)
                ? 'install_reboot_done'
                : 'dhcp_request_install';
        } elseif ($type === Action::TYPE_WIPE_ONLY) {
            $checkpoint = 'dhcp_request_wipe';
        } elseif ($type === Action::TYPE_DISKTEST) {
            $checkpoint = 'dhcp_request_disktest';
        }

        if ($checkpoint === null || !ActionCheckpoints::validCode($type, $checkpoint)) {
            return;
        }

        // Une étape ne se réalise qu'une fois par action. dnsmasq journalise 2 échanges PXE par
        // démarrage (offre, puis requête) : sans ce garde, l'étape serait enregistrée en double.
        $done = Db::get()->prepare('SELECT 1 FROM machine_events WHERE action_id = ? AND checkpoint = ? LIMIT 1');
        $done->execute([$actionId, $checkpoint]);
        if ($done->fetchColumn()) return;

        // dnsmasq 2.90 en proxy DHCP ne journalise que la MAC du client (l'IP n'existe pas dans
        // l'échange) : on enregistre ce qui a été observé, sans inventer d'IP.
        $seen = ['mac' => $mac, 'src' => 'dnsmasq-tail'];
        if ($ip !== '') $seen['dhcp_ip'] = $ip;
        $message = 'checkpoint:' . $checkpoint . ' ' . json_encode($seen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        MachineEvent::log($machineId, MachineEvent::LEVEL_INFO, $message, $checkpoint, $actionId);
    }

    /**
     * Sous-handler : event TFTP serve (fichier envoyé à une IP). Émet le
     * checkpoint grub_config_served_* si le fichier est un bootloader PXE
     * reconnu. Lookup machine via dernier dhcp_request_* avec IP matchant
     * dans les 5 dernières minutes (TTL court — si la machine a re-DHCP
     * entre temps, l'IP a pu être réattribuée à une autre).
     */
    private function handleDnsmasqTftpServe(string $ip, string $fileName): void
    {
        // Filtre fichiers : seulement les bootloaders, pas les kernels/initrd
        // (sinon spam). Le bootloader est servi 1× par boot PXE, signal fiable.
        $isBootloader = in_array(
            $fileName,
            ['lpxelinux.0', 'grubnetx64.efi', 'grubnetaa64.efi', 'grubx64.efi'],
            true
        );
        if (!$isBootloader || $ip === '') return;

        // Lookup machine via dernier dhcp_request_* avec cet IP (TTL 5 min).
        // Le message des events DHCP contient `"dhcp_ip":"X"` — on cherche par
        // LIKE %X% sur les events récents.
        $stmt = Db::get()->prepare(
            "SELECT machine_id, action_id FROM machine_events "
          . "WHERE checkpoint IN ('dhcp_request_wipe', 'dhcp_request_install', 'dhcp_request_disktest') "
          . "  AND message LIKE ? "
          . "  AND created_at >= datetime('now', '-5 minutes') "
          . "ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['%"dhcp_ip":"' . $ip . '"%']);
        $row = $stmt->fetch();
        if (!$row || empty($row['machine_id']) || empty($row['action_id'])) return;

        $machineId = (int)$row['machine_id'];
        $actionId  = (int)$row['action_id'];

        // Récupère l'action pour déterminer wipe vs install vs disktest.
        $action = Action::findById($actionId);
        if ($action === null) return;
        $actionType = (string)$action['type'];

        $checkpoint = null;
        if ($actionType === Action::TYPE_REINSTALL) {
            $checkpoint = $this->hasWipeFinishedEvent($actionId)
                ? 'grub_config_served_install'
                : 'grub_config_served_wipe';
        } elseif ($actionType === Action::TYPE_INSTALL_ONLY) {
            $checkpoint = 'grub_config_served_install';
        } elseif ($actionType === Action::TYPE_WIPE_ONLY) {
            $checkpoint = 'grub_config_served_wipe';
        } elseif ($actionType === Action::TYPE_DISKTEST) {
            $checkpoint = 'grub_config_served_disktest';
        }

        if ($checkpoint === null || !ActionCheckpoints::validCode($actionType, $checkpoint)) {
            return;
        }

        // Dédup : dnsmasq sert souvent lpxelinux.0 deux fois par cycle PXE
        // (1× abort TFTP du PXE ROM Intel + 1× retry réussi). Sans dédup,
        // grub_config_served_* est loggué 2× pour le même cycle, pollue la
        // timeline. Skip si on a déjà loggé ce checkpoint pour cette action
        // dans la fenêtre récente (10s).
        $dupStmt = Db::get()->prepare(
            "SELECT 1 FROM machine_events "
          . "WHERE action_id = ? AND checkpoint = ? "
          . "  AND created_at >= datetime('now', '-10 seconds') "
          . "LIMIT 1"
        );
        $dupStmt->execute([$actionId, $checkpoint]);
        if ($dupStmt->fetchColumn()) return;

        $message = 'checkpoint:' . $checkpoint . ' ' . json_encode(
            ['bootloader' => $fileName, 'ip' => $ip, 'src' => 'dnsmasq-tail'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        MachineEvent::log($machineId, MachineEvent::LEVEL_INFO, $message, $checkpoint, $actionId);
    }

    /**
     * GET /postboot/{id}/postboot.sh
     *
     * SEC-01 — Sert le script postboot rendu par le worker, hors document
     * root nginx, après vérification :
     *   - action_id existe + non terminale (TTL implicite via status)
     *   - dernier event dhcp_request_install/dhcp_request_wipe loggué pour
     *     la MAC de l'action est ≤ POSTBOOT_DHCP_TTL_S
     *   - REMOTE_ADDR == l'IP DHCP extraite de cet event
     *
     * Sinon : 200 vide + logRejection (silence radio défensif, jamais 403).
     */
    public function servePostboot(array $params): void
    {
        try {
            $actionId = isset($params['id']) ? (int)$params['id'] : 0;
            $remoteIp = $this->remoteIp();

            if ($actionId <= 0) {
                $this->logRejection('postboot', 'action_id_invalid', $remoteIp, '', '');
                $this->respond200();
                return;
            }

            $action = Action::findById($actionId);
            if ($action === null) {
                $this->logRejection('postboot', 'action_not_found', $remoteIp, '', '');
                $this->respond200();
                return;
            }
            if (in_array($action['status'], Action::STATUSES_TERMINAL, true)) {
                $this->logRejection('postboot', 'action_terminal', $remoteIp, '', '');
                $this->respond200();
                return;
            }

            $machine = Machine::find((int)$action['machine_id']);
            if ($machine === null) {
                $this->logRejection('postboot', 'machine_not_found', $remoteIp, '', '');
                $this->respond200();
                return;
            }
            $machineId = (int)$machine['id'];

            // Cherche le dernier event dhcp_request_* pour cette MAC dans le
            // TTL. On cible n'importe lequel des codes DHCP catalogués (install
            // ou wipe selon la phase) — le postboot.sh est servi après l'install,
            // donc on attend dhcp_request_install en priorité mais on accepte
            // wipe (cas re-render best-effort).
            $stmt = Db::get()->prepare(
                "SELECT message FROM machine_events "
              . "WHERE machine_id = ? "
              . "AND checkpoint IN ('dhcp_request_install', 'dhcp_request_wipe') "
              . "AND created_at >= datetime('now', '-' || ? || ' seconds') "
              . "ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$machineId, self::POSTBOOT_DHCP_TTL_S]);
            $evtRow = $stmt->fetch();
            if (!$evtRow) {
                $this->logRejection('postboot', 'no_recent_dhcp', $remoteIp, '', '');
                $this->respond200();
                return;
            }

            $dhcpIp = $this->extractDhcpIp((string)$evtRow['message']);
            if ($dhcpIp === '' || $remoteIp !== $dhcpIp) {
                $this->logRejection('postboot', 'ip_mismatch', $remoteIp, '', '');
                $this->respond200();
                return;
            }

            $outputDir = getenv('POSTBOOT_OUTPUT_DIR') ?: self::POSTBOOT_OUTPUT_DIR_DEFAULT;
            $path = $outputDir . '/' . (int)$action['id'] . '/postboot.sh';
            if (!is_file($path) || !is_readable($path)) {
                $this->logRejection('postboot', 'file_missing', $remoteIp, '', '');
                $this->respond200();
                return;
            }

            http_response_code(200);
            header('Content-Type: text/x-shellscript');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } catch (\Throwable $e) {
            error_log('[internal-api] servePostboot failed: ' . $e->getMessage());
            $this->respond200();
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Lit le token depuis le header Authorization: Bearer <token> (SEC-02).
     * Fallback apache_request_headers() si HTTP_AUTHORIZATION n'est pas
     * propagé (cas certains PHP-FPM/sapi). Retourne '' si absent.
     */
    private function readBearerToken(): string
    {
        $raw = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($raw === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp($name, 'Authorization') === 0) {
                        $raw = (string)$value;
                        break;
                    }
                }
            }
        }
        if ($raw === '') return '';
        // Strip prefix `Bearer ` (case-insensitive). Verif_INFRA01 item #5 :
        // strict prefix check — un header sans préfixe `Bearer ` ne doit pas
        // être accepté comme token brut (sécurité dégradée : un client mal
        // formé qui passerait `Authorization: <token>` aurait pu auth par
        // accident). Désormais on retourne null explicite → l'appelant reject.
        if (stripos($raw, 'Bearer ') !== 0) {
            return '';
        }
        return trim(substr($raw, 7));
    }

    /**
     * Vérifie si l'action $actionId a déjà un event signalant la fin du wipe.
     * Utilisé pour départager dhcp_request_wipe vs dhcp_request_install
     * dans le flow reinstall.
     */
    private function hasWipeFinishedEvent(int $actionId): bool
    {
        try {
            $stmt = Db::get()->prepare(
                "SELECT 1 FROM machine_events "
              . "WHERE action_id = ? "
              . "AND checkpoint IN ('wipe_reboot_signaled', 'wipe_poweroff') "
              . "LIMIT 1"
            );
            $stmt->execute([$actionId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // En cas d'erreur DB on assume pas encore fini (1ère phase).
            // Verif_INFRA01 item #3 : observabilité minimale — sans ce log,
            // une corruption DB serait totalement silencieuse (le DHCP
            // checkpoint tomberait alors systématiquement sur wipe).
            error_log('[internal-api] hasWipeFinishedEvent failed (action_id=' . $actionId . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * REINSTCKPT01 (Fix Q) — Vérifie si l'install Debian est terminée avec succès
     * pour cette action (installations.install_status = 'success'). Utilisé pour
     * distinguer le reboot post-install du boot normal vers l'installateur.
     *
     * POURQUOI install_status et pas un checkpoint machine_events : le checkpoint
     * install_reboot (late_command) est supprimé (Fix P). Le seul signal fiable
     * de fin d'install Debian est la transition SQL posée par installEnd() via
     * /api/install-end dans le late_command. Best-effort : en cas d'erreur DB,
     * on assume pas encore terminé (→ dhcp_request_install normal, sans perte).
     */
    private function hasInstallSuccessEvent(int $actionId): bool
    {
        try {
            $stmt = Db::get()->prepare(
                "SELECT 1 FROM installations "
              . "WHERE action_id = ? AND install_status = 'success' "
              . "LIMIT 1"
            );
            $stmt->execute([$actionId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[internal-api] hasInstallSuccessEvent failed (action_id=' . $actionId . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Extrait l'IP DHCP du message JSON d'un event checkpoint:dhcp_request_*.
     * Format : `checkpoint:dhcp_request_install {"dhcp_ip":"192.0.2.42"}`.
     * Retourne '' si parsing impossible.
     */
    private function extractDhcpIp(string $message): string
    {
        $jsonStart = strpos($message, '{');
        if ($jsonStart === false) return '';
        $json = substr($message, $jsonStart);
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) return '';
        $ip = (string)($parsed['dhcp_ip'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Réponse 200 vide stricte (spec §Échec silencieux).
     * SEC-14 : headers Cache-Control + X-Content-Type-Options (défense en profondeur).
     */
    private function respond200(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: 0');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // Pas d'echo : body vide.
        exit;
    }

    /**
     * Réponse JSON typée (LOGCAP01 — endpoint install-log retourne un statut
     * structuré pour permettre au client de détecter le saturate). Distinct
     * de respond200() qui reste muet pour les autres endpoints (spec §Échec
     * silencieux strictement appliqué aux checkpoints).
     */
    private function respondJson(int $code, array $body): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Only the local DHCP hook can establish a boot MAC/IP pair. */
    private function recordPxeBoot(int $machineId, string $mac, string $ip): void
    {
        $subnet = Setting::get(self::SETTING_PXE_SUBNET, '') ?? '';
        if ($this->ipInCidr($ip, $subnet)) {
            PxeBootObservation::record($machineId, $mac, $ip);
        }
    }

    /** Extrait l'IP source (REMOTE_ADDR). */
    private function remoteIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * Vérifie si une IP est dans un CIDR donné (IPv4).
     * filter_var précède parseCidr : on veut distinguer "IP invalide"
     * (rejet net) de "IP valide hors réseau" (rejet logique).
     *
     * IPv4-only par design : ipv6.disable=1 dans kernel cmdline du serveur PXE
     * (cf. KERNEL_OPTS render_template.py) et tout le flow PXE/preseed/dnsmasq
     * fonctionne en IPv4 strict. Toute IP IPv6 entrante est implicitement
     * rejetée par filter_var(..., FLAG_IPV4) — comportement voulu (silence
     * radio défensif côté endpoints internes).
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if ($ip === '' || $cidr === '') return false;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;

        $parsed = NetworkDetector::parseCidr($cidr);
        if ($parsed === null) return false;

        $ipLong   = ip2long($ip);
        $netLong  = ip2long($parsed['network']);
        $maskLong = ip2long($parsed['netmask']);
        if ($ipLong === false || $netLong === false || $maskLong === false) {
            return false;
        }
        return ($ipLong & $maskLong) === ($netLong & $maskLong);
    }

    /**
     * Détecte loopback IPv4 (127.0.0.0/8) ou IPv6 (::1 sous toutes ses formes).
     *
     * SEC-06 : normalisation via inet_pton qui parse en binaire et couvre
     * nativement les variantes IPv6 :
     *   - 127.0.0.1 (et tout 127.x.x.x)
     *   - ::1, 0:0:0:0:0:0:0:1 (forme longue)
     *   - ::ffff:127.0.0.1 (IPv4-mapped IPv6, cas Docker)
     */
    private function isLoopback(string $ip): bool
    {
        if ($ip === '') return false;
        $packed = @inet_pton($ip);
        if ($packed === false) return false;
        // IPv4 loopback : 127.0.0.0/8 → premier byte 0x7F.
        if (strlen($packed) === 4) {
            return ord($packed[0]) === 0x7F;
        }
        // IPv6.
        if (strlen($packed) === 16) {
            // ::1 strict (15 zéros + 0x01).
            if ($packed === str_repeat("\x00", 15) . "\x01") return true;
            // ::ffff:127.0.0.0/104 (IPv4-mapped vers loopback IPv4).
            // 10 octets zéro + 0xff 0xff + 4 octets IPv4 dont premier 0x7F.
            $v4MappedPrefix = str_repeat("\x00", 10) . "\xff\xff";
            if (substr($packed, 0, 12) === $v4MappedPrefix && ord($packed[12]) === 0x7F) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log error_log() avec redact token : on ne garde que les 8 premiers
     * caractères pour permettre debug ops sans exposer un secret valide en log.
     */
    private function logRejection(
        string $endpoint,
        string $reason,
        string $ip,
        string $token,
        string $code
    ): void {
        $tokRedact = $token === '' ? '(empty)' : substr($token, 0, 8) . '…';
        // Pre-sanitize $code/$reason (SEC-10) : pas de newline ou char exotique
        // dans error_log (évite log injection si $code arrive sale via $_GET).
        $codeSafe   = $code === '' ? '(empty)' : preg_replace('/[^a-z0-9_]/i', '_', $code);
        $reasonSafe = preg_replace('/[^a-z0-9_]/i', '_', $reason);
        error_log(sprintf(
            '[internal-api] %s reject reason=%s ip=%s token=%s code=%s',
            $endpoint,
            $reasonSafe,
            $ip,
            $tokRedact,
            $codeSafe
        ));
    }
}
