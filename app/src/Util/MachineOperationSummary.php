<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Db;
use Admin\Models\Machine;
use Admin\View;

/** Small public snapshot for coordinated operation polling; never includes config secrets or logs. */
final class MachineOperationSummary
{
    public static function forMachine(array $machine): array
    {
        $operation = MachineOperation::eligibility($machine);
        $ansible = AnsibleRedeployEnqueuer::eligibility($machine);
        $rules = [
            'operation' => $operation,
            'install' => MachineOperation::eligibility($machine, 'install_deferred'),
            'power' => MachineOperation::eligibility($machine, 'power'),
            'ansible' => ['allowed' => $ansible['ok'], 'reason' => $ansible['message'], 'job_id' => $ansible['existing_job_id'] ?? null],
        ];
        $query = Db::get()->prepare('SELECT id,install_status,ansible_status FROM installations WHERE machine_id=? ORDER BY id DESC LIMIT 1');
        $query->execute([(int)$machine['id']]);
        $installation = $query->fetch() ?: null;
        $query = Db::get()->prepare("SELECT id,type,status FROM actions WHERE machine_id=? AND status IN ('pending','running') ORDER BY id");
        $query->execute([(int)$machine['id']]);
        $actions = $query->fetchAll();
        $machineId = (int)$machine['id'];
        $retry = Db::driver() === 'mysql' ? "CONCAT('action:ansible_retry:',a.id)" : "'action:ansible_retry:' || a.id";
        $reboot = Db::driver() === 'mysql' ? "CONCAT('action:reboot:',a.id)" : "'action:reboot:' || a.id";
        $query = Db::get()->prepare("SELECT j.id,j.job_type,j.status FROM init_jobs j LEFT JOIN actions a ON j.job_type=$retry OR j.job_type=$reboot WHERE j.job_type IN (?,?,?) OR a.machine_id=? ORDER BY j.id DESC LIMIT 12");
        $query->execute(["machine:ansible_redeploy:$machineId", "machine:reboot:$machineId", "machine:shutdown:$machineId", $machineId]);
        $jobs = $query->fetchAll();
        $state = [
            'id' => (int)$machine['id'],
            'label' => Machine::displayLabel($machine),
            'mac' => $machine['mac'],
            'status' => $machine['status'],
            'observation' => View::stateLabel($machine),
            'last_seen_at' => $machine['last_seen_at'] ?? null,
            'next_action' => $machine['next_action'] ?? null,
            'rescue_mode' => !empty($machine['rescue_mode']),
            'awaiting_action_until' => $machine['awaiting_action_until'] ?? null,
            'has_inventory' => !empty(json_decode($machine['inventory_json'] ?? 'null', true)),
            'has_config' => !empty($machine['install_config_json']),
            'rules' => $rules,
            'actions' => $actions,
            'installation' => $installation,
            'jobs' => $jobs,
        ];
        return ['state' => $state, 'signature' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR))];
    }
}
