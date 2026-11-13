<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\Action;
use Admin\Models\BootSession;
use Admin\Models\Discovery;
use Admin\Models\Machine;
use Admin\Models\MachineEvent;
use Admin\Models\PxeBootObservation;
use Admin\Models\Installation;
use Admin\Models\Wipe;
use Admin\Util\DiskIdentity;
use Admin\Util\NetworkDetector;
use Admin\Models\DiskTest;
use Admin\Models\DiskSmartSnapshot;
use Admin\Models\DiskReliabilityHistory;
use Admin\Models\Setting;
use Admin\View;

/**
 * Endpoints PXE — appelés par le preseed pendant l'install.
 * Pas de session utilisateur pour les clients PXE ; les callbacks qui écrivent
 * l'état d'un job exigent un jeton limité à l'action et au boot de l'agent.
 */
final class PxeController
{
    /** Signal HTTP exécuté par `source` dans l'entrée GRUB choisie. */
    public function bootSelected(array $params): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $subnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        if (!self::ipInPxeSubnet($remoteIp, $subnet)) {
            http_response_code(404);
            echo "# boot signal unavailable\n";
            return;
        }
        $idRaw = (string)($_GET['boot_id'] ?? '');
        $id = ctype_digit($idRaw) ? (int)$idRaw : 0;
        $ok = BootSession::recordSelected(
            $id,
            (string)($_GET['token'] ?? ''),
            (string)($_GET['menu'] ?? '')
        );
        if (!$ok) http_response_code(404);
        // Un commentaire est une configuration GRUB valide sans effet de bord.
        echo $ok ? "# boot selection recorded\n" : "# boot selection rejected\n";
    }

    /**
     * POST /api/discovery — body JSON { mac, inventory: {...}, disks: [...], interfaces: [...] }
     * GET  /api/discovery?b64=...   — fallback : JSON encodé en base64 URL-safe
     * POST /api/discovery — formulaire b64 pour les environnements sans curl
     */
    public function discovery(array $params): void
    {
        // Pings de phase : pure télémétrie côté discover.sh.
        // On loggue chaque phase comme événement machine (pour le terminal UI).
        // Pas d'écrasement de l'inventory : on retourne juste après le log.
        if (!empty($_GET['phase'])) {
            $phase = (string)$_GET['phase'];
            $phaseMac = (string)($_GET['mac'] ?? '');
            // Niveau warning/error pour les phases qui signalent un échec
            $level = MachineEvent::LEVEL_INFO;
            if (str_starts_with($phase, 'post_0_') || str_contains($phase, 'fail') || str_contains($phase, 'error')) {
                $level = MachineEvent::LEVEL_WARNING;
            }
            if ($phaseMac !== '') {
                MachineEvent::logByMac($phaseMac, $level, "discover.sh phase: $phase");
                // discover.sh émet déjà ces phases juste avant poweroff.
                // Fermer la fenêtre immédiatement quand le signal arrive ;
                // l'échéance conservée reste le repli si le signal se perd.
                if (in_array($phase, ['action_poweroff', 'action_timeout_poweroff'], true)) {
                    $machine = Machine::findByAnyMac($phaseMac);
                    if ($machine && !empty($machine['awaiting_action_until'])) {
                        Machine::setAwaitingUntil((int)$machine['id'], time());
                    }
                }
            }
            View::json(['status' => 'phase_ack', 'phase' => $phase]);
        }

        // POST JSON, POST formulaire b64, puis GET b64 des anciens clients.
        // Un JSON invalide ou une MAC seule ne doit jamais effacer un inventaire.
        $body = file_get_contents('php://input', false, null, 0,
            \Admin\Util\DiscoveryPayload::MAX_BODY_BYTES + 1);
        try {
            $data = \Admin\Util\DiscoveryPayload::decode((string)$body, $_POST, $_GET);
        } catch (\InvalidArgumentException $error) {
            View::json(['error' => $error->getMessage()], $error->getCode());
        }
        $mac = Machine::normalizeMac($data['mac']);
        if ($mac === '') View::json(['error' => 'invalid mac format'], 400);

        // Le payload entier est stocké en JSON (inventory + disks + interfaces + gpus).
        // discovery_ip = IP du client au moment du POST (REMOTE_ADDR), utile pour
        // afficher l'IP DHCP que la machine a obtenue tant qu'elle n'est pas installée.
        $inventoryJson = json_encode([
            'boot_mac'       => $mac,
            'inventory'      => $data['inventory']      ?? [],
            'disks'          => DiskIdentity::normalizeInventory($data['disks'] ?? []),
            'interfaces'     => $data['interfaces']     ?? [],
            'gpus'           => $data['gpus']           ?? [],
            'usb_devices'    => $data['usb_devices']    ?? [],
            'memory_modules' => $data['memory_modules'] ?? [],
            'display_probe'  => $data['display_probe']  ?? null,
            'boot_probe'     => $data['boot_probe']     ?? null,
            'discovered_at'  => date('c'),
            'discovery_ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Spec D : déduire boot_arch depuis inventory.cpu_arch (uname -m côté
        // discover.sh). Mapping vers la nomenclature Debian netboot.
        $cpuArch = (string)($data['inventory']['cpu_arch'] ?? '');
        $bootArch = match ($cpuArch) {
            'aarch64'           => 'arm64',
            'x86_64'            => 'amd64',
            'armv7l', 'armv6l'  => 'armhf',
            'i686', 'i386'      => 'i386',
            default             => null,  // inconnu / non capturé → preseed.php fallback default
        };

        // Lookup + réconciliation multi-NIC. On collecte TOUS les MACs du payload
        // (primaire + secondaires inventory.interfaces[].mac) et on cherche
        // toutes les machines existantes qui matchent l'une de ces MACs.
        //
        // Cas typique de doublon : grub.php a stubbé une entrée 'inventorying'
        // avec la MAC firmware (NIC qui a fait le DHCP UEFI), tandis que le
        // kernel Linux choisit une autre NIC comme route default → discover.sh
        // POST avec un MAC différent. Sans réconciliation, on créerait une 2e
        // ligne 'discovered' alors que c'est la même machine physique.
        $payloadMacs = [$mac];
        foreach (($data['interfaces'] ?? []) as $iface) {
            $im = Machine::normalizeMac((string)($iface['mac'] ?? ''));
            if ($im !== '' && !in_array($im, $payloadMacs, true)) $payloadMacs[] = $im;
        }
        $matches = Machine::findAllByMacs($payloadMacs);

        // A client-reported interface list is not proof that two registered
        // machines are the same host. Keep both records for administrator
        // review, including an inventorying stub whose primary MAC would
        // otherwise mask the established machine on later lookups.
        if (count($matches) > 1) {
            foreach ($matches as $row) {
                MachineEvent::log((int)$row['id'], MachineEvent::LEVEL_WARNING,
                    'Conflit de découverte multi-NIC : plusieurs fiches correspondent aux MAC déclarées. Vérifiez leur identité avant rapprochement manuel.');
            }
            View::json(['error' => 'conflicting machine identities'], 409);
        }

        $existing = null;
        $mergedIds = [];
        if ($matches !== []) {
            $existing = $matches[0];
        }

        // Fenêtre d'attente post-discovery : 1h pour que l'admin choisisse une
        // action depuis l'UI (Éteindre/Redémarrer). Au-delà, l'endpoint
        // discoveryAction() force un poweroff au prochain poll de la machine.
        $awaitingUntilIso = gmdate('Y-m-d H:i:s', time() + 3600);

        if ($existing) {
            // Statuts "métier" à préserver : un discovery boot ne doit pas
            // écraser un état actif (wiping, configured, installing...).
            $preserveStatuses = ['configured', 'installing', 'installed', 'provisioned', 'wiping'];
            $isSecondaryNic = ($existing['mac'] ?? '') !== $mac;
            $newStatus = in_array($existing['status'], $preserveStatuses, true)
                ? $existing['status'] : 'discovered';
            $updateFields = [
                'inventory_json' => $inventoryJson,
                'last_seen_at'   => date('Y-m-d H:i:s'),
                // L'agent discovery entre toujours dans son poll d'attente
                // après le POST, même si un statut métier est conservé.
                'awaiting_action_until' => $awaitingUntilIso,
            ];
            // Spec D : ne réécrit boot_arch que si reçu (ne pas effacer un
            // boot_arch existant si discover.sh ancien ne capture pas cpu_arch).
            if ($bootArch !== null) {
                $updateFields['boot_arch'] = $bootArch;
            }
            Machine::update((int)$existing['id'], $updateFields);
            if ($newStatus !== $existing['status']) {
                Machine::setStatus((int)$existing['id'], $newStatus);
            }
            if ($isSecondaryNic) {
                error_log("PxeController::discovery: matched secondary NIC $mac → machine_id={$existing['id']} (primary MAC: {$existing['mac']})");
            }
            self::recordDiscoveryCycle((int)$existing['id'], $inventoryJson);
            View::json([
                'status'      => 'updated',
                'machine_id'  => (int)$existing['id'],
                'matched_via' => $isSecondaryNic ? 'secondary_nic' : 'primary_mac',
                'merged_ids'  => $mergedIds,
            ]);
        } else {
            $createFields = [
                'mac'                    => $mac,
                'status'                 => 'discovered',
                'inventory_json'         => $inventoryJson,
                'last_seen_at'           => date('Y-m-d H:i:s'),
                'awaiting_action_until'  => $awaitingUntilIso,
            ];
            if ($bootArch !== null) {
                $createFields['boot_arch'] = $bootArch;
            }
            $id = Machine::create($createFields);
            self::recordDiscoveryCycle($id, $inventoryJson);
            View::json(['status' => 'created', 'machine_id' => $id]);
        }
    }

    /**
     * Spec C — Crée une row discovery (cycle complete) ou complète celle en cours.
     * Le started_at est CURRENT_TIMESTAMP de l'INSERT, ended_at idem si record
     * en 1 seule fois (cas où discover.sh n'a pas pré-créé la row au boot).
     */
    private static function recordDiscoveryCycle(int $machineId, string $inventoryJson): void
    {
        $current = Discovery::currentForMachine($machineId);
        if ($current !== null) {
            Discovery::complete((int)$current['id'], Discovery::STATUS_SUCCESS, $inventoryJson);
        } else {
            $discoveryId = Discovery::startForMachine($machineId, $_SERVER['REMOTE_ADDR'] ?? null);
            Discovery::complete($discoveryId, Discovery::STATUS_SUCCESS, $inventoryJson);
        }
    }

    /**
     * GET/POST /api/discovery-action?mac=... — poll côté discover.sh.
     * Retourne {action: 'wait'|'poweroff'|'reboot', remaining_seconds: N}.
     *
     * Consomme next_action (reset à NULL après lecture) pour éviter de
     * re-déclencher au tick suivant. Si la fenêtre d'attente (awaiting_until)
     * est expirée, force action=poweroff côté serveur (au lieu d'attendre que
     * la machine atteigne son timeout local de 720 ticks).
     *
     * remaining_seconds : temps restant avant auto-poweroff côté serveur. Lu
     * par discover.sh pour afficher un countdown TTY synchronisé avec l'UI.
     */
    public function discoveryAction(array $params): void
    {
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if ($mac === '') View::json(['action' => 'wait', 'remaining_seconds' => 0, 'error' => 'mac required'], 400);

        // MAC inconnue : machine supprimée par admin alors qu'elle pollait
        // encore. Au lieu de la laisser en limbo (wait perpétuel jusqu'au
        // prochain reboot manuel), on la recrée en DB avec status='discovered'
        // + fenêtre d'attente 1h (équivalent au flow discovery normal). Cela
        // la rend re-visible dans /machines et l'admin peut décider d'une
        // action via les boutons Éteindre/Redémarrer.
        if (!Machine::findByAnyMac($mac)) {
            try {
                $newId = Machine::create([
                    'mac'                   => $mac,
                    'status'                => 'discovered',
                    'last_seen_at'          => date('Y-m-d H:i:s'),
                    'awaiting_action_until' => gmdate('Y-m-d H:i:s', time() + 3600),
                ]);
                MachineEvent::log(
                    $newId,
                    MachineEvent::LEVEL_WARNING,
                    "Machine re-créée en DB suite à poll après suppression — choisir une action (Éteindre/Redémarrer) ou auto-poweroff dans 1h"
                );
            } catch (\Throwable $e) {
                // Race ou contrainte DB : on retombe sur le comportement par
                // défaut (wait) pour ce tick, sera retenté au prochain poll.
                error_log('[pxe] discoveryAction re-create failed: ' . $e->getMessage());
            }
            View::json(['action' => 'wait', 'remaining_seconds' => 3600]);
            return;
        }

        $action = Machine::consumeNextAction($mac);
        $remaining = 0;

        if ($action !== 'wait') {
            MachineEvent::logByMac($mac, MachineEvent::LEVEL_ACTION, "Action consommée par la machine : $action");
        } else {
            // Test de disque in-session en attente → la machine l'enchaîne sans
            // reboot. UNIQUEMENT in_session=1 : un test « chemin reboot » a déjà
            // son redémarrage programmé côté worker — le servir ici ferait
            // démarrer le test puis rebooter la machine en plein milieu.
            // Pas de consommation : le passage pending→running au fetch du job
            // fait foi (retry naturel si la machine crashe entre poll et fetch).
            $t = DiskTest::activeByMac($mac);
            if ($t !== null && (int)($t['in_session'] ?? 0) === 1) {
                if (($t['job_status'] ?? '') === 'pending') {
                    MachineEvent::logByMac($mac, MachineEvent::LEVEL_ACTION,
                        'Tests de disque enchaînés sans redémarrage (in-session)');
                    // Checklist /tasks : in-session il n'y a ni boot DHCP ni
                    // bootloader servi (le sidecar dnsmasq ne voit rien) — les
                    // 2 premières étapes du catalogue sont émises ici, à la
                    // remise du test à une machine démarrée (elle polle).
                    // Dédup EXISTS : le poll repasse si l'agent meurt entre
                    // poll et fetch du job (retry naturel pending→running).
                    $aid = isset($t['action_id']) ? (int)$t['action_id'] : 0;
                    if ($aid > 0) {
                        $seen = Db::get()->prepare(
                            'SELECT 1 FROM machine_events WHERE action_id = ? AND checkpoint = ? LIMIT 1'
                        );
                        $inSessionCheckpoints = [
                            'dhcp_request_disktest'       => 'Machine déjà démarrée (test enchaîné in-session)',
                            'grub_config_served_disktest' => 'Tests préparés — remis à la machine sans redémarrage',
                        ];
                        foreach ($inSessionCheckpoints as $code => $msg) {
                            $seen->execute([$aid, $code]);
                            if (!$seen->fetchColumn()) {
                                MachineEvent::log((int)$t['machine_id'], MachineEvent::LEVEL_INFO, $msg, $code, $aid);
                            }
                        }
                    }
                }
                View::json(['action' => 'disktest', 'remaining_seconds' => 0]);
                return;
            }
            // Pas d'action programmée par l'admin : check si la fenêtre
            // d'attente est expirée → force poweroff serveur-side.
            $machine = Machine::findByAnyMac($mac);
            if ($machine && !empty($machine['awaiting_action_until'])) {
                $expires = strtotime((string)$machine['awaiting_action_until'] . ' UTC');
                $remaining = max(0, $expires - time());
                if ($remaining === 0) {
                    $action = 'poweroff';
                    // Garder la deadline expirée : elle permet à l'UI de
                    // montrer l'arrêt même si le ping final ne parvient pas.
                    MachineEvent::logByMac($mac, MachineEvent::LEVEL_ACTION, 'Auto-poweroff serveur après 1h sans action');
                }
            }
        }

        View::json(['action' => $action, 'remaining_seconds' => $remaining]);
    }

    /** GET/POST /api/install-start?mac=... */
    public function installStart(array $params): void
    {
        // The preseed renderer calls this endpoint from the server itself.
        // A network client must not be able to start an installation by MAC.
        if (!in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
            View::json(['error' => 'not found'], 404);
        }
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if ($mac === '') View::json(['error' => 'mac required'], 400);
        // findByAnyMac : si l'install démarre via une NIC ≠ MAC primaire DB
        // (firmware UEFI multi-NIC), on retrouve quand même la machine via
        // ses interfaces secondaires stockées dans inventory_json.
        $m = Machine::findByAnyMac($mac);
        if (!$m) View::json(['error' => 'machine not found'], 404);
        // Récupère l'action_id pré-créé par MachineController::installSubmit (install_only)
        // ou depuis la chaîne wipe→install (action_id dans chain_install_config_json / wipes.action_id).
        // Stocké sous _pending_action_id dans install_config_json pour éviter une colonne supplémentaire.
        $installConfig = Machine::installConfig($m);
        $pendingActionId = isset($installConfig['_pending_action_id'])
            ? (int)$installConfig['_pending_action_id']
            : null;
        // Si pas d'action_id direct, cherche via le wipe actif (cas reinstall)
        if ($pendingActionId === null) {
            $activeWipe = Wipe::latestForMachine((int)$m['id']);
            if ($activeWipe && !empty($activeWipe['action_id'])) {
                $pendingActionId = (int)$activeWipe['action_id'];
            }
        }
        // Le rendu preseed appelle cette route en loopback, mais un nouveau
        // fetch ne doit pas rouvrir une installation d-i déjà terminée.
        if (!in_array((string)$m['status'], ['configured', 'installing'], true)) {
            View::json(['error' => 'installation not available'], 409);
        }
        if ($pendingActionId !== null) {
            $action = Action::findById($pendingActionId);
            if (!$action || (int)$action['machine_id'] !== (int)$m['id']
                || !in_array((string)$action['type'], ['install_only', 'reinstall'], true)
                || !in_array((string)$action['status'], ['pending', 'running'], true)) {
                View::json(['error' => 'installation not available'], 409);
            }
            $stmt = Db::get()->prepare(
                'SELECT install_status FROM installations WHERE machine_id = ? AND action_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([(int)$m['id'], $pendingActionId]);
        } else {
            $stmt = Db::get()->prepare(
                "SELECT id FROM actions WHERE machine_id = ? AND type IN ('install_only','reinstall') LIMIT 1"
            );
            $stmt->execute([(int)$m['id']]);
            if ($stmt->fetch()) View::json(['error' => 'installation not available'], 409);
            $stmt = Db::get()->prepare(
                'SELECT install_status FROM installations WHERE machine_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([(int)$m['id']]);
        }
        $installation = $stmt->fetch();
        if ($installation && (string)$installation['install_status'] !== 'in_progress') {
            View::json(['error' => 'installation already finished'], 409);
        }
        $installId = Installation::startForMachine((int)$m['id'], $pendingActionId);
        Machine::setStatus((int)$m['id'], 'installing');
        View::json(['status' => 'ok', 'installation_id' => $installId]);
    }

    /** GET/POST /api/install-end?mac=...&status=success|failed */
    public function installEnd(array $params): void
    {
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if ($mac === '') View::json(['error' => 'mac required'], 400);
        $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $subnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        if (!self::ipInPxeSubnet($remoteIp, $subnet)) View::json(['error' => 'not found'], 404);
        $statusParam = $_GET['status'] ?? $_POST['status'] ?? 'success';
        $statusParam = in_array($statusParam, ['success', 'failed', 'aborted'], true) ? $statusParam : 'success';
        $m = Machine::findByAnyMac($mac);
        if (!$m) View::json(['error' => 'machine not found'], 404);

        $raw = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($raw === '' && function_exists('apache_request_headers')) {
            foreach ((array)apache_request_headers() as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $raw = (string)$value;
                    break;
                }
            }
        }
        $token = stripos($raw, 'Bearer ') === 0 ? trim(substr($raw, 7)) : '';
        $action = Action::findByToken($token);
        if (!$action || (int)$action['machine_id'] !== (int)$m['id']
            || !in_array((string)$action['type'], ['install_only', 'reinstall'], true)) {
            View::json(['error' => 'not found'], 404);
        }

        $latestInstall = Installation::latestForMachine((int)$m['id']);
        if (!$latestInstall || (int)($latestInstall['action_id'] ?? 0) !== (int)$action['id']) {
            View::json(['error' => 'no matching installation'], 409);
        }
        if (!$latestInstall || $latestInstall['install_status'] !== 'in_progress') {
            // Un ancien callback d'installation ne doit pas réactiver le boot
            // local après un wipe ou un changement de politique.
            if ($latestInstall && $statusParam === 'success'
                && $latestInstall['install_status'] === 'success'
                && in_array($m['status'], ['installed', 'provisioned'], true)) {
                View::json([
                    'status' => 'ok',
                    'machine_status' => $m['status'],
                    'installation_id' => (int)$latestInstall['id'],
                ]);
            }
            View::json(['error' => 'no installation in progress'], 409);
        }

        $machineStatus = $statusParam === 'success' ? 'installed' : 'failed';
        $pdo = Db::get();
        $pdo->beginTransaction();
        try {
            $installId = Installation::endLatest((int)$m['id'], $statusParam);
            Machine::setStatus((int)$m['id'], $machineStatus);
            if ($statusParam === 'success' && $installId !== null) {
                Machine::setLocalBootSource((int)$m['id'], 'pxe');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        View::json([
            'status' => 'ok',
            'machine_status' => $machineStatus,
            'installation_id' => $installId,
        ]);
    }

    /**
     * GET /api/machine-ready?mac=... — signal post-reboot émis par le service
     * systemd `pxe-machine-ready.service` installé sur la machine au late_command.
     * Indique que `network-online.target` et `sshd.service` sont up sur la machine.
     *
     * Effets :
     *   - met à jour `last_seen_at` (utile pour les indicateurs UI)
     *   - logge un `MachineEvent` info
     *   - NE force PAS de transition d'état : le worker poll TCP:22 puis lance
     *     ansible-playbook qui basculera la machine en `provisioned` (success)
     *     ou `failed` selon le retour.
     */
    public function machineReady(array $params): void
    {
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if ($mac === '') View::json(['error' => 'mac required'], 400);
        $m = Machine::findByAnyMac($mac);
        if (!$m) View::json(['error' => 'machine not found'], 404);

        Db::get()->prepare('UPDATE machines SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([(int)$m['id']]);
        MachineEvent::log(
            (int)$m['id'],
            MachineEvent::LEVEL_INFO,
            'Machine signalée online (post-reboot, sshd up)'
        );

        View::json(['status' => 'ok']);
    }

    /**
     * GET /wipe.sh?mac=... — script bash exécuté par partman/early_command du
     * wipe-preseed. Rendu DYNAMIQUE par MAC : disques + modes + token + action
     * inlinés directement dans le script. Plus de round-trip /api/wipe-job.
     *
     * THREAT MODEL : action_token est inliné en clair dans le script servi.
     * Même contrainte qu'avant le refactor — busybox d-i ne peut pas s'auth
     * comme client API. Le filtre subnet PXE est la défense en profondeur :
     * seul un équipement sur le subnet PXE peut obtenir ce script. Un attaquant
     * hors-subnet reçoit 404 silencieux (pas de fuite d'info).
     */
    public function wipeScript(array $params): void
    {
        $remoteIp  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $pxeSubnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        if (!self::ipInPxeSubnet($remoteIp, $pxeSubnet)) {
            http_response_code(404);
            return;
        }

        $mac = Machine::normalizeMac($_GET['mac'] ?? '');
        if ($mac === '') {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "# ERREUR : mac required (?mac=...)\n";
            return;
        }
        $w = Wipe::activeByMac($mac);
        if (!$w) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "# ERREUR : no active wipe job for mac $mac\n";
            return;
        }

        $action = self::activeAgentAction($w, 'wipe');
        if ($action === null) {
            http_response_code(404);
            return;
        }
        // Proxy DHCP may report only a MAC, and TFTP-only mode has no local
        // DHCP hook. In those modes the isolated PXE subnet is the issuance
        // boundary; bind the callback to IP only when DHCP observed the pair.
        $observedIp = PxeBootObservation::recentMatches((int)$w['machine_id'], $mac, $remoteIp)
            ? $remoteIp : null;
        $boot_token = BootSession::issueAgentToken(
            (int)$w['machine_id'], (int)$action['id'], $mac, 'wipe', $observedIp
        );

        // Side-effect : passer le wipe en running dès que le client fetch son
        // script (équivalent au comportement legacy wipeJob).
        if ($w['wipe_status'] === 'pending') {
            Wipe::setStatus((int)$w['id'], 'running');
        }

        $action_token = (string)$action['token'];
        $action_id    = (int)$action['id'];
        $action_type  = (string)$action['type'];

        // Construit DISKS_PARSED au format "name|mode\nname|mode...\n" consommé
        // tel quel par la boucle while du script (plus de parsing JSON côté
        // busybox). Wipe::start valide déjà name (non vide) + mode (in MODES) :
        // pas de sanitization runtime nécessaire. Newline final OBLIGATOIRE
        // sinon le délimiteur heredoc bash 'PARSED_EOF' colle au dernier disque
        // (PHP absorbe le LF qui suit la balise de fermeture du template).
        $disks = json_decode((string)$w['disks_json'], true) ?: [];
        $disks_parsed_lines = [];
        foreach ($disks as $d) {
            $name = (string)($d['name'] ?? '');
            $mode = (string)($d['mode'] ?? '');
            if ($name !== '' && $mode !== '') {
                $identity = is_array($d['identity'] ?? null) ? $d['identity'] : [];
                $serial = ($identity['kind'] ?? '') === 'serial' && ($identity['status'] ?? '') === 'valid'
                    ? strtoupper(trim((string)($identity['value'] ?? ''))) : '';
                $disks_parsed_lines[] = $name . '|' . $mode . '|' . ($serial === '' ? '-' : base64_encode($serial));
            }
        }
        $disks_parsed = implode("\n", $disks_parsed_lines) . "\n";

        $pxe_ip      = Setting::get(Setting::KEY_PXE_IP) ?? '';
        $wipe_id     = (int)$w['id'];
        $post_action = (string)($w['post_action'] ?? 'poweroff');

        header('Content-Type: text/x-shellscript; charset=utf-8');
        header('Cache-Control: no-store');
        include __DIR__ . '/../Views/pxe/wipe.sh.php';
    }

    /**
     * POST /api/wipe-progress?wipe_id=N
     *   body raw, ou ?b64=, ou ?line=...  → append au log.
     */
    public function wipeProgress(array $params): void
    {
        $wipeId = (int)($_GET['wipe_id'] ?? $_POST['wipe_id'] ?? 0);
        // wipe_id=0 = debug ping (avant que le job soit fetched). On retourne ack
        // sans rien écrire en DB — c'est juste pour visibilité dans nginx access.
        if ($wipeId <= 0) {
            View::json(['status' => 'debug_ack']);
        }
        $w = Wipe::find($wipeId);
        if (!$w) View::json(['error' => 'wipe not found'], 404);
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if (!self::agentCallbackAllowed($w, $mac, 'wipe')) View::json(['error' => 'not found'], 404);

        $line = '';
        // Cascade body, b64, line query
        $body = file_get_contents('php://input');
        if ($body !== '' && $body !== false) {
            $line = $body;
        }
        if ($line === '' && !empty($_GET['b64'])) {
            $b64 = strtr($_GET['b64'], '-_', '+/');
            $pad = strlen($b64) % 4;
            if ($pad === 2) $b64 .= '==';
            elseif ($pad === 3) $b64 .= '=';
            $decoded = base64_decode($b64, false);
            if ($decoded !== false) $line = $decoded;
        }
        if ($line === '' && !empty($_GET['line'])) {
            $line = $_GET['line'];
        }
        if ($line !== '') {
            // Toujours terminer par newline pour la lisibilité du log
            if (substr($line, -1) !== "\n") $line .= "\n";
            Wipe::appendLog($wipeId, $line);
        }
        View::json(['status' => 'ok']);
    }

    /**
     * POST /api/wipe-end?wipe_id=N&status=success|failed
     * Finalise le wipe.
     *  - Cas standard : passe la machine en 'wiped' (succès) ou 'failed'.
     *  - Cas wipe-chain (chain_install_config_json non NULL + status=success) :
     *    consomme le snapshot install_config pour passer la machine en
     *    'configured' + next_action='reboot'. La machine PXE-boot le menuentry
     *    install au cycle suivant. Permet la séquence wipe→install en 1 seul
     *    reboot admin.
     */
    public function wipeEnd(array $params): void
    {
        $wipeId = (int)($_GET['wipe_id'] ?? $_POST['wipe_id'] ?? 0);
        $statusParam = $_GET['status'] ?? $_POST['status'] ?? 'success';
        $statusParam = in_array($statusParam, ['success', 'failed'], true) ? $statusParam : 'success';
        if ($wipeId <= 0) View::json(['error' => 'wipe_id required'], 400);
        $w = Wipe::find($wipeId);
        if (!$w) View::json(['error' => 'wipe not found'], 404);

        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if (!self::agentCallbackAllowed($w, $mac, 'wipe')) View::json(['error' => 'not found'], 404);

        if (!Wipe::end($wipeId, $statusParam)) View::json(['status' => 'ignored']);

        $machineId = (int)$w['machine_id'];
        $chainJson = $w['chain_install_config_json'] ?? null;

        // Cas wipe-chain armé + succès : consommer le snapshot install_config
        if ($statusParam === 'success' && $chainJson !== null && $chainJson !== '') {
            $chain = json_decode((string)$chainJson, true);
            if (is_array($chain) && isset($chain['install_config']) && is_array($chain['install_config'])) {
                $hostname = (string)($chain['hostname'] ?? '');
                $domain   = $chain['domain'] ?? null;
                $updates  = [];
                if ($hostname !== '') $updates['hostname'] = $hostname;
                $updates['domain'] = $domain;
                if (!empty($updates)) Machine::update($machineId, $updates);

                Machine::saveInstallConfig($machineId, $chain['install_config']);
                Machine::setStatus($machineId, 'configured');
                Machine::setNextAction($machineId, 'reboot');
                MachineEvent::log(
                    $machineId,
                    MachineEvent::LEVEL_ACTION,
                    "Wipe terminé · installation programmée pour le prochain boot (chain)"
                );
                View::json([
                    'status' => 'ok',
                    'wipe_id' => $wipeId,
                    'machine_status' => 'configured',
                    'chain' => 'consumed',
                ]);
            }
            // JSON malformé : fallback sur le flow standard ci-dessous
        }

        // Flow standard : wipe seul, ou chain failed/malformé
        $machineStatus = $statusParam === 'success' ? 'wiped' : 'failed';
        Machine::setStatus($machineId, $machineStatus);
        View::json([
            'status' => 'ok',
            'wipe_id' => $wipeId,
            'machine_status' => $machineStatus,
        ]);
    }

    // ============================================================
    // Postboot serve — sert les fichiers postboot.sh / pxe-postboot.service
    // rendus par le worker Python par action.
    // ============================================================

    /**
     * GET /pxe/postboot/{action_id}/{file}
     *
     * Sert le postboot.sh ou pxe-postboot.service rendu par worker.py pour
     * une action donnée. Filtre IP `pxe_subnet` strict (même règle que
     * /api/internal/checkpoint), pas d'auth dédiée : le late_command du
     * preseed appelle ces URLs en HTTP simple.
     *
     * POURQUOI une route distincte de InternalApiController::servePostboot :
     * le path /postboot/{id}/postboot.sh existant fait une vérif MAC↔IP
     * stricte (REMOTE_ADDR == dernière IP DHCP loggée), conçue pour un
     * appel post-boot OS. Ici le contexte est différent : on sert depuis
     * le late_command du preseed (Debian installer en cours, IP DHCP de
     * la phase install, MAC non encore loggée comme dhcp_request_install).
     * Le filtre subnet PXE seul est suffisant — la machine est captive du
     * réseau PXE et l'action_id seul ne suffit pas à servir sans
     * vérification additionnelle action.status.
     *
     * Réponse :
     *   - 200 + contenu si tout OK
     *   - 404 si action inexistante / terminale / file inconnu / fichier
     *     manquant côté disque (rendu par worker pas encore fait)
     */
    public function serveActionFile(array $params): void
    {
        $actionId = isset($params['action_id']) ? (int)$params['action_id'] : 0;
        $file     = (string)($params['file'] ?? '');

        $remoteIp  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $pxeSubnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        if (!self::ipInPxeSubnet($remoteIp, $pxeSubnet)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Not found\n";
            return;
        }

        // Whitelist de fichiers servables — évite path traversal et limite
        // l'exposition aux 2 artefacts attendus par le late_command.
        $allowedFiles = [
            'postboot.sh'          => 'text/x-shellscript',
            'pxe-postboot.service' => 'text/plain',
        ];
        if ($actionId <= 0 || !isset($allowedFiles[$file])) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Not found\n";
            return;
        }

        $action = Action::findById($actionId);
        if ($action === null) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Not found\n";
            return;
        }
        if (in_array($action['status'], Action::STATUSES_TERMINAL, true)) {
            // Action déjà clôturée — refuser de servir (préviendrait un replay
            // long après une install morte).
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Not found\n";
            return;
        }
        if (in_array((string)$action['type'], ['install_only', 'reinstall'], true)) {
            $stmt = Db::get()->prepare(
                'SELECT install_status FROM installations WHERE action_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$actionId]);
            $installation = $stmt->fetch();
            // Ces fichiers sont téléchargés par le late_command avant
            // install-end, jamais par l'OS une fois la phase d-i close.
            if (!$installation || (string)$installation['install_status'] !== 'in_progress') {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo "Not found\n";
                return;
            }
        }

        // Le worker rend dans POSTBOOT_OUTPUT_DIR/{action_id}/ (cf. worker.py
        // render_postboot_for_action). On lit l'env override si présent
        // (cohérent avec InternalApiController::POSTBOOT_OUTPUT_DIR_DEFAULT).
        // SYNC avec InternalApiController::POSTBOOT_OUTPUT_DIR_DEFAULT
        // (duplication volontaire — séparation responsabilité cross-controller voulue)
        $outputDir = getenv('POSTBOOT_OUTPUT_DIR') ?: '/var/lib/pxe-tools/postboot';
        $path = $outputDir . '/' . $actionId . '/' . $file;
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Not found\n";
            return;
        }

        // Défense en profondeur contre symlink attack (container escape préalable
        // requis, mais realpath garantit qu'on ne sert jamais hors de $outputDir).
        $realPath = realpath($path);
        $realBase = realpath($outputDir);
        if ($realPath === false || $realBase === false || !str_starts_with($realPath, $realBase . '/')) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Not found\n";
            return;
        }

        http_response_code(200);
        header('Content-Type: ' . $allowedFiles[$file]);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($realPath));
        readfile($realPath);
    }

    /** The job and action must describe the same live machine operation. */
    private static function activeAgentAction(array $job, string $menu): ?array
    {
        $actionId = (int)($job['action_id'] ?? 0);
        $machineId = (int)($job['machine_id'] ?? 0);
        $action = $actionId > 0 ? Action::findById($actionId) : null;
        $allowedTypes = $menu === 'wipe' ? [Action::TYPE_WIPE_ONLY, Action::TYPE_REINSTALL] : [Action::TYPE_DISKTEST];
        if ($action === null || (int)$action['machine_id'] !== $machineId
            || !in_array((string)$action['type'], $allowedTypes, true)
            || !in_array((string)$action['status'], [Action::STATUS_PENDING, Action::STATUS_RUNNING], true)) return null;
        return $action;
    }

    /** A boot token is valid only for its job, machine, MAC and HTTP peer. */
    private static function agentCallbackAllowed(array $job, string $mac, string $menu): bool
    {
        $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $subnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        if ($mac === '' || !self::ipInPxeSubnet($remoteIp, $subnet)) return false;
        $jobStatus = $menu === 'wipe' ? ($job['wipe_status'] ?? null) : ($job['job_status'] ?? null);
        if (!in_array($jobStatus, ['pending', 'running'], true)) return false;
        $machine = Machine::findByAnyMac($mac);
        if ($machine === null || (int)$machine['id'] !== (int)$job['machine_id']) return false;
        $action = self::activeAgentAction($job, $menu);
        if ($action === null) return false;
        $raw = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($raw === '' && function_exists('apache_request_headers')) {
            foreach ((array)apache_request_headers() as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) { $raw = (string)$value; break; }
            }
        }
        $token = stripos($raw, 'Bearer ') === 0 ? trim(substr($raw, 7)) : '';
        return BootSession::agentTokenValid($token, (int)$machine['id'], (int)$action['id'],
            $mac, $menu, $remoteIp);
    }

    /** IPv4 PXE subnet check; never trust a forwarded client address. */
    private static function ipInPxeSubnet(string $ip, string $cidr): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $parsed = NetworkDetector::parseCidr($cidr);
        if ($parsed === null) return false;
        return (ip2long($ip) & ip2long($parsed['netmask'])) === ip2long($parsed['network']);
    }

    // ============================================================
    // Disk tests — endpoints API consommés par disktest.sh
    // ============================================================

    /**
     * GET /api/disktest-job?mac=... — disktest.sh récupère son job.
     * Retourne {test_id, parallel, jobs:[{disk,mode}], post_action}.
     */
    public function diskTestJob(array $params): void
    {
        $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $subnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        if (!self::ipInPxeSubnet($remoteIp, $subnet)) View::json(['error' => 'not found'], 404);
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if ($mac === '') View::json(['error' => 'mac required'], 400);
        $t = DiskTest::activeByMac($mac);
        if (!$t) View::json(['error' => 'no active disk test job'], 404);
        $action = self::activeAgentAction($t, 'disktest');
        if ($action === null) {
            View::json(['error' => 'not found'], 404);
        }
        // Same subnet fallback as wipeScript for proxy DHCP and TFTP-only.
        $observedIp = PxeBootObservation::recentMatches((int)$t['machine_id'], $mac, $remoteIp)
            ? $remoteIp : null;
        $bootToken = BootSession::issueAgentToken(
            (int)$t['machine_id'], (int)$action['id'], $mac, 'disktest', $observedIp
        );

        if ($t['job_status'] === 'pending') {
            DiskTest::setStatus((int)$t['id'], 'running');
            // Checkpoint « start » de la checklist /tasks/{id} — émis serveur-side :
            // en chemin in-session il n'y a ni DHCP ni grub pour le faire.
            MachineEvent::log((int)$t['machine_id'], MachineEvent::LEVEL_ACTION,
                'Tests des disques démarrés', 'disktest_started',
                isset($t['action_id']) ? (int)$t['action_id'] : null);
            // Badge « En cours » sur /tasks : même bascule atomique
            // pending→running que InternalApi::checkpoint (1er checkpoint =
            // l'action a démarré). Nécessaire ici car ce checkpoint n'emprunte
            // pas l'endpoint internal ; sans elle l'action reste « En attente »
            // pendant tout le test. Idempotent sur le chemin reboot (déjà
            // running via le checkpoint DHCP du sidecar).
            if (!empty($t['action_id'])) {
                Db::get()->prepare(
                    'UPDATE actions SET status = ?, ended_at = NULL WHERE id = ? AND status = ?'
                )->execute([Action::STATUS_RUNNING, (int)$t['action_id'], Action::STATUS_PENDING]);
            }
        }
        $jobs = DiskTest::agentJobs(json_decode((string)$t['jobs_json'], true) ?: []);
        header('Cache-Control: no-store');
        View::json([
            'test_id'     => (int)$t['id'],
            'action_id'   => isset($t['action_id']) ? (int)$t['action_id'] : 0,
            'boot_token'  => $bootToken,
            'parallel'    => (int)($t['parallel'] ?? 0) === 1,
            'jobs'        => $jobs,
            'post_action' => $t['post_action'] ?? 'poweroff',
        ]);
    }

    /**
     * GET/POST /api/disktest-disk-progress — progression structurée d'UN disque,
     * postée ~toutes les 10 s par disktest.sh (query string, busybox wget).
     * Params : test_id, disk (URL-encodé), phase, pct, pass, bad, elapsed, eta.
     * Aucune confiance dans l'agent : test actif requis, disque whitelisté sur
     * jobs_json, valeurs clampées. Réponse toujours 200 (l'agent l'ignore).
     */
    public function diskTestDiskProgress(array $params): void
    {
        $src    = $_GET + $_POST;
        $testId = (int)($src['test_id'] ?? 0);
        if ($testId <= 0) View::json(['status' => 'ignored']);
        $t = DiskTest::find($testId);
        if (!$t || !in_array((string)$t['job_status'], ['pending', 'running'], true)) {
            View::json(['status' => 'ignored']); // test fini/annulé : pas d'écriture posthume
        }
        $mac = Machine::normalizeMac($src['mac'] ?? '');
        if (!self::agentCallbackAllowed($t, $mac, 'disktest')) View::json(['error' => 'not found'], 404);

        $disk = rawurldecode((string)($src['disk'] ?? ''));
        $jobs = json_decode((string)$t['jobs_json'], true) ?: [];
        $known = false;
        $mode  = '';
        foreach ($jobs as $j) {
            if (($j['disk'] ?? '') === $disk) { $known = true; $mode = (string)($j['mode'] ?? ''); break; }
        }
        if (!$known) View::json(['status' => 'ignored']);

        $phase = (string)($src['phase'] ?? '');
        if (preg_match('/^[a-z0-9_]{1,24}$/', $phase) !== 1) $phase = '';
        $clampTime = static fn($v) => max(0, min((int)$v, 2764800)); // 32 jours
        $eta = ($src['eta'] ?? '') === '' ? null : $clampTime($src['eta']);

        DiskTest::setDiskProgress($testId, $disk, [
            'mode'      => $mode,
            'phase'     => $phase,
            'pass'      => max(0, min((int)($src['pass'] ?? 1), 99)),
            'pct'       => max(0, min((int)($src['pct'] ?? 0), 100)),
            'bad'       => max(0, min((int)($src['bad'] ?? 0), 1000000000)),
            'elapsed_s' => $clampTime($src['elapsed'] ?? 0),
            'eta_s'     => $eta,
        ]);
        View::json(['status' => 'ok']);
    }

    /**
     * POST /api/disktest-progress?test_id=N
     *   body raw, ou ?b64=, ou ?line=...  → append au log.
     */
    public function diskTestProgress(array $params): void
    {
        $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
        if ($testId <= 0) {
            View::json(['status' => 'debug_ack']);
        }
        $t = DiskTest::find($testId);
        if (!$t) View::json(['error' => 'test not found'], 404);
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if (!self::agentCallbackAllowed($t, $mac, 'disktest')) View::json(['error' => 'not found'], 404);

        $line = '';
        $body = file_get_contents('php://input');
        if ($body !== '' && $body !== false) {
            $line = $body;
        }
        if ($line === '' && !empty($_GET['b64'])) {
            $b64 = strtr($_GET['b64'], '-_', '+/');
            $pad = strlen($b64) % 4;
            if ($pad === 2) $b64 .= '==';
            elseif ($pad === 3) $b64 .= '=';
            $decoded = base64_decode($b64, false);
            if ($decoded !== false) $line = $decoded;
        }
        if ($line === '' && !empty($_GET['line'])) {
            $line = $_GET['line'];
        }
        if ($line !== '') {
            if (substr($line, -1) !== "\n") $line .= "\n";
            DiskTest::appendLog($testId, $line);
        }
        View::json(['status' => 'ok']);
    }

    /**
     * POST /api/disktest-result?test_id=N
     * body JSON : {disk, mode, verdict (passed|failed), bad_blocks, duration_s}
     * Persiste le résultat par disque (les badges fiche disque le réutilisent).
     */
    public function diskTestResult(array $params): void
    {
        $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
        if ($testId <= 0) View::json(['error' => 'test_id required'], 400);
        $test = DiskTest::find($testId);
        if (!$test) View::json(['error' => 'test not found'], 404);
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if (!self::agentCallbackAllowed($test, $mac, 'disktest')) View::json(['error' => 'not found'], 404);

        $body = file_get_contents('php://input');
        $payload = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($payload)) {
            // Fallback : params en query string
            $payload = $_GET + $_POST;
        }
        $disk    = (string)($payload['disk']    ?? '');
        $mode    = (string)($payload['mode']    ?? '');
        $verdict = (string)($payload['verdict'] ?? 'unknown');
        if ($disk === '' || $mode === '') View::json(['error' => 'disk + mode required'], 400);
        if (!in_array($mode, DiskTest::MODES, true))   View::json(['error' => 'invalid mode'], 400);
        if (!in_array($verdict, ['passed','failed','unknown'], true)) $verdict = 'unknown';

        // Série prise dans le job enregistré, jamais dans le message de l'agent ;
        // le chemin réellement testé (après relecture par série) est informatif.
        $serial = null;
        $test = DiskTest::find($testId);
        if (!in_array($test['job_status'] ?? '', ['pending', 'running'], true)) {
            View::json(['status' => 'ignored']);
        }
        foreach (json_decode((string)($test['jobs_json'] ?? ''), true) ?: [] as $job) {
            if (is_array($job) && ($job['disk'] ?? null) === $disk && ($job['mode'] ?? null) === $mode) {
                $serial = DiskTest::jobSerial($job);
                break;
            }
        }
        if ($serial === null) View::json(['error' => 'known disk + mode with serial required'], 409);
        $device = (string)($payload['device'] ?? '');
        DiskTest::setResultForDisk($testId, $disk, $mode, [
            'verdict'    => $verdict,
            'bad_blocks' => isset($payload['bad_blocks']) ? (int)$payload['bad_blocks'] : null,
            'duration_s' => isset($payload['duration_s']) ? (int)$payload['duration_s'] : null,
            'serial'     => $serial,
            'device'     => preg_match('#^/dev/[A-Za-z0-9]{1,16}$#', $device) === 1 ? $device : null,
            'evidence'   => DiskTest::resultEvidence($payload['evidence'] ?? null),
        ]);
        View::json(['status' => 'ok', 'test_id' => $testId]);
    }

    /**
     * POST /api/disktest-smart?test_id=N&disk=<clé du job>&phase=before|after
     * Stocke la sortie JSON SMART brute après vérification de l’identité du job.
     */
    public function diskTestSmart(array $params): void
    {
        $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
        if ($testId <= 0) View::json(['error' => 'test_id required'], 400);
        $test = DiskTest::find($testId);
        if ($test === null) View::json(['error' => 'test not found'], 404);
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if (!self::agentCallbackAllowed($test, $mac, 'disktest')) View::json(['error' => 'not found'], 404);
        $raw = file_get_contents('php://input', false, null, 0, 1_048_577);
        $body = is_string($raw) ? $raw : '';
        $validation = DiskSmartSnapshot::validate(
            $testId,
            $test,
            (string)($_GET['disk'] ?? ''),
            (string)($_GET['phase'] ?? ''),
            $body
        );
        if ($validation['http'] !== 200 || ($validation['response']['status'] ?? null) !== 'ok') {
            View::json($validation['response'], $validation['http']);
        }

        $deviceFields = $validation['payload']['device'] ?? null;
        $deviceName = is_array($deviceFields) && is_string($deviceFields['name'] ?? null)
            ? $deviceFields['name']
            : null;
        if ($deviceName === '' || ($deviceName !== null && strlen($deviceName) > 128)) {
            $deviceName = null;
        }
        DiskSmartSnapshot::store(
            (int)$test['machine_id'],
            $testId,
            (string)$validation['serial'],
            $deviceName,
            (string)($_GET['phase'] ?? ''),
            $body
        );
        View::json(['status' => 'ok']);
    }

    /**
     * POST /api/disktest-end?test_id=N&status=success|failed
     * Finalise le job et passe la machine en 'tested' ou 'failed'.
     */
    public function diskTestEnd(array $params): void
    {
        $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
        $status = $_GET['status'] ?? $_POST['status'] ?? 'success';
        $status = in_array($status, ['success', 'failed'], true) ? $status : 'success';
        if ($testId <= 0) View::json(['error' => 'test_id required'], 400);
        $test = DiskTest::find($testId);
        if (!$test) View::json(['error' => 'test not found'], 404);
        $mac = Machine::normalizeMac($_GET['mac'] ?? $_POST['mac'] ?? '');
        if (!self::agentCallbackAllowed($test, $mac, 'disktest')) View::json(['error' => 'not found'], 404);
        $machineId = (int)$test['machine_id'];
        $result = \Admin\Util\MachineOperation::run($machineId, null, static function (array $machine) use ($machineId, $testId, $status): array {
            $test = DiskTest::find($testId);
            if (!$test || !in_array($test['job_status'], ['pending', 'running'], true)) return ['status' => 'ignored'];
            $outcome = DiskTest::completionOutcome($test, $status);
            $status = $outcome['status'];
            if (!DiskTest::end($testId, $status)) return ['status' => 'ignored'];
            $actionId = isset($test['action_id']) ? (int)$test['action_id'] : null;
            MachineEvent::log($machineId, $status === 'failed' ? MachineEvent::LEVEL_ERROR : MachineEvent::LEVEL_ACTION,
                $outcome['incomplete'] ? 'Tests des disques incomplets ou indéterminés' : 'Tests des disques terminés', 'disktest_finished', $actionId);
            MachineEvent::log($machineId, MachineEvent::LEVEL_INFO, 'Résultats des tests disponibles', 'disktest_results_posted', $actionId);

            $config = Machine::installConfig($machine) ?? [];
            $pendingId = (int)($config['_pending_action_id'] ?? 0);
            $pending = $pendingId > 0 ? Action::findById($pendingId) : null;
            $chain = $pending && (int)$pending['machine_id'] === $machineId
                && $pending['type'] === Action::TYPE_INSTALL_ONLY && $pending['status'] === 'pending';
            $qualificationBlocked = [];
            if ($chain && $status === 'success') {
                $completedTest = $test;
                $completedTest['job_status'] = 'success';
                foreach (json_decode((string)$test['jobs_json'], true) ?: [] as $job) {
                    $serial = DiskTest::jobSerial($job);
                    if ($serial === null) { $qualificationBlocked[] = '?'; continue; }
                    $assessment = DiskReliabilityHistory::fromPair($serial, $completedTest,
                        DiskSmartSnapshot::pairForTest($testId, $serial));
                    $grade = $assessment['verdict']['grade'];
                    if (in_array($grade, ['D', '?'], true)) $qualificationBlocked[] = $grade;
                }
            }
            $postAction = (string)($test['post_action'] ?? 'poweroff');
            if ($chain && $status === 'success' && $qualificationBlocked === []) {
                $machineStatus = 'configured';
                $deferred = !empty($config['wake_now']);
                Machine::setNextAction($machineId, $deferred ? null : 'reboot');
                $postAction = $deferred
                    ? ((int)($test['in_session'] ?? 0) === 1 ? 'wait' : 'poweroff')
                    : ((int)($test['in_session'] ?? 0) === 1 ? 'wait' : 'reboot');
                MachineEvent::log($machineId, MachineEvent::LEVEL_ACTION,
                    $deferred ? 'Tests réussis — installation au prochain démarrage PXE manuel.' : 'Tests réussis — redémarrage vers l’installation demandé.', null, $pendingId);
            } else {
                $machineStatus = $status === 'success' ? 'tested' : 'failed';
                if ($chain && ($status === 'failed' || $qualificationBlocked !== [])) {
                    Action::forceComplete($pendingId);
                    Machine::setNextAction($machineId, null);
                    $cancelReason = 'Installation programmée annulée : le test disque a échoué. Réparez ou remplacez le disque.';
                    if ($outcome['incomplete']) {
                        $cancelReason = 'Installation programmée annulée : un test disque est incomplet ou indéterminé. Relancez les vérifications avant d’installer.';
                    }
                    if ($qualificationBlocked !== []) {
                        $cancelReason = in_array('D', $qualificationBlocked, true)
                            ? 'Installation programmée annulée : un disque est classé D. Choisissez une autre cible après revue du classement.'
                            : 'Installation programmée annulée : la qualification d’un disque est incomplète. Vérifiez les relevés et relancez les tests.';
                    }
                    MachineEvent::log($machineId, MachineEvent::LEVEL_WARNING,
                        $cancelReason, null, $pendingId);
                }
            }
            Machine::setStatus($machineId, $machineStatus);
            if ($postAction === 'wait' && (int)($test['in_session'] ?? 0) === 1) Machine::setAwaitingUntil($machineId, time() + 3600);
            return ['status' => 'ok', 'test_id' => $testId, 'machine_status' => $machineStatus, 'post_action' => $postAction];
        });
        View::json($result);
    }
}
