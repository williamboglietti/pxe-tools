<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\MachineEvent;
use Admin\Models\Setting;
use Admin\Util\AnsibleInventoryMapper;
use Admin\Util\AnsibleRedeployEnqueuer;
use Admin\Util\Crypto;

/**
 * Webhook GitHub pour redéploiement automatique Ansible.
 *
 * Endpoint : POST /api/ansible-repo/webhook
 *   - Validation HMAC SHA-256 du payload (X-Hub-Signature-256) AVANT tout parse
 *   - Toggle global Setting::KEY_ANSIBLE_REPO_WEBHOOK_ENABLED = kill-switch
 *   - Parse les fichiers modifiés du commit GitHub
 *   - Mappe fichier → machines impactées via AnsibleInventoryMapper
 *   - Pour chaque machine avec auto_deploy=1 → enqueue init_job
 *     machine:ansible_redeploy:M (idempotent via AnsibleRedeployEnqueuer)
 *
 * Sécurité :
 *   - `/api/` est déjà PUBLIC_PREFIXES (cf. Auth.php), l'auth est faite par
 *     signature HMAC à l'intérieur du handler. Aucun cookie de session requis.
 *   - hash_equals (timing-safe) pour la comparaison HMAC.
 *   - Body limité à 5 MB (les push events GitHub typiques font <100 KB).
 *   - Secret chiffré libsodium (KEY_ANSIBLE_REPO_WEBHOOK_SECRET) stocké via
 *     Crypto::encrypt — Crypto::decrypt retourne null si master key absente.
 */
final class AnsibleRepoWebhookController
{
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function receive(array $params): void
    {
        // 1. Kill-switch global
        if (Setting::get(Setting::KEY_ANSIBLE_REPO_WEBHOOK_ENABLED, 'off') !== 'on') {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "webhook disabled\n";
            return;
        }

        // 2. Lire raw body (limité)
        $body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES);
        if ($body === false || $body === '') {
            http_response_code(400);
            echo "empty body\n";
            return;
        }

        // 3. Validation signature HMAC SHA-256 (AVANT tout parse JSON)
        $sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if (!is_string($sigHeader) || !str_starts_with($sigHeader, 'sha256=')) {
            http_response_code(401);
            echo "missing signature header\n";
            return;
        }

        $secretEncrypted = (string)(Setting::get(Setting::KEY_ANSIBLE_REPO_WEBHOOK_SECRET, '') ?? '');
        if ($secretEncrypted === '') {
            http_response_code(503);
            echo "webhook secret not configured\n";
            return;
        }
        try {
            $secret = Crypto::decrypt($secretEncrypted);
        } catch (\Throwable $e) {
            http_response_code(503);
            error_log('[ansible-webhook] secret decrypt failed: ' . $e->getMessage());
            echo "webhook secret unreadable\n";
            return;
        }
        if (!is_string($secret) || $secret === '') {
            http_response_code(503);
            echo "webhook secret empty after decrypt\n";
            return;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $sigHeader)) {
            http_response_code(401);
            echo "invalid signature\n";
            return;
        }

        // 4. Filtrer par event GitHub
        $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
        if ($event === 'ping') {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['pong' => true]);
            return;
        }
        if ($event !== 'push') {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['ignored' => true, 'event' => $event]);
            return;
        }

        // 5. Parse JSON
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo "invalid json\n";
            return;
        }

        // 6. Agréger fichiers modifiés sur tous les commits
        $changedFiles = [];
        foreach (($payload['commits'] ?? []) as $commit) {
            if (!is_array($commit)) continue;
            foreach (['added', 'modified', 'removed'] as $k) {
                foreach (($commit[$k] ?? []) as $f) {
                    if (is_string($f) && $f !== '') $changedFiles[$f] = true;
                }
            }
        }
        $changedFiles = array_keys($changedFiles);

        // 7. Mapping fichier → hostnames
        $hostnames = AnsibleInventoryMapper::impactedHostnames($changedFiles);

        // 8. Pour chaque machine impactée AVEC auto_deploy=1 → enqueue
        $shortSha = substr((string)($payload['head_commit']['id'] ?? '?'), 0, 7);
        $reasonBase = "Webhook GitHub : commit {$shortSha}";

        $triggered = [];
        $skipped = [];
        if (!empty($hostnames)) {
            $pdo = Db::get();
            $placeholders = implode(',', array_fill(0, count($hostnames), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, hostname FROM machines WHERE hostname IN ($placeholders) AND auto_deploy = 1"
            );
            $stmt->execute($hostnames);
            foreach ($stmt->fetchAll() as $row) {
                $machineId = (int)$row['id'];
                $r = AnsibleRedeployEnqueuer::enqueue($machineId, $reasonBase);
                if ($r['ok']) {
                    $triggered[] = $row['hostname'];
                } else {
                    $skipped[] = ['hostname' => $row['hostname'], 'reason' => $r['reason']];
                    // Log d'audit côté machine pour traçabilité (pourquoi pas déclenché)
                    MachineEvent::log(
                        $machineId,
                        MachineEvent::LEVEL_WARNING,
                        "Webhook GitHub : redéploiement non déclenché ({$r['reason']}) — commit {$shortSha}"
                    );
                }
            }
        }

        // 9. Log fichier best-effort (append, ignore si /var/log/pxe-tools absent)
        $logLine = sprintf(
            "[%s] commit=%s files=%d hostnames_impacted=%d triggered=%d skipped=%d\n",
            date('c'),
            $shortSha,
            count($changedFiles),
            count($hostnames),
            count($triggered),
            count($skipped)
        );
        @file_put_contents('/var/log/pxe-tools/webhook.log', $logLine, FILE_APPEND);

        // 10. Réponse JSON
        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode([
            'commit'    => $shortSha,
            'files'     => count($changedFiles),
            'impacted'  => count($hostnames),
            'triggered' => $triggered,
            'skipped'   => $skipped,
        ]);
    }
}
