<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Auth;
use Admin\Util\ApplianceJobs;
use Admin\View;

final class NativePortController
{
    private function permitted(): bool
    {
        if (getenv('PXE_SERVICE_MANAGER') !== 'systemd' || Auth::user() === null) {
            http_response_code(403);
            echo 'Action non disponible.';
            return false;
        }
        return true;
    }

    public function form(array $params): void
    {
        if (!$this->permitted()) return;
        $jobs = ApplianceJobs::tracked('native_port_job_ids');
        $last = $jobs === [] ? null : $jobs[count($jobs) - 1];
        View::partial('init/wizard/port-80', ['job' => $last, 'missingConsent' => isset($_GET['consent']), 'identityUnavailable' => isset($_GET['identity'])]);
    }

    public function submit(array $params): void
    {
        if (!$this->permitted()) return;
        if (($_POST['confirm_stop_nginx'] ?? null) !== '1') {
            header('Location: /init/port-80?consent=missing');
            return;
        }
        // Bind consent to the current master process and this boot. A queued
        // request must not stop a different service started in the meantime.
        $pid = trim((string)@file_get_contents('/run/nginx.pid'));
        $boot = trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $pid)
            || !preg_match('/\A[0-9a-f-]{36}\z/D', $boot)) {
            header('Location: /init/port-80?identity=unavailable');
            return;
        }
        $stat = (string)@file_get_contents('/proc/' . $pid . '/stat');
        $end = strrpos($stat, ') ');
        $fields = $end === false ? [] : preg_split('/\s+/', substr($stat, $end + 2));
        $start = $fields[19] ?? '';
        if (!is_string($start) || !preg_match('/\A[0-9]+\z/D', $start)) {
            header('Location: /init/port-80?identity=unavailable');
            return;
        }
        $consent = 'nginx-consent-v1:' . $pid . ':' . $start . ':' . time() . ':' . $boot;
        // No command or service-name parameters. The privileged worker also
        // independently verifies every TCP/80 listener immediately before acting.
        ApplianceJobs::enqueue(['system:release_nginx_port' => $consent], [], 'native_port_job_ids');
        header('Location: /init/port-80');
    }
}
