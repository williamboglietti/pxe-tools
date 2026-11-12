<?php
declare(strict_types=1);

namespace Admin\Util;

use Admin\Db;
use Admin\Models\Machine;
use Admin\Models\MachineEvent;

/**
 * Enqueue un init_job `machine:ansible_redeploy:M` consommé par worker.py
 * (cf. run_ansible_redeploy_job). Partagé entre :
 *   - MachineController::ansibleRedeploy (bouton manuel "Redéployer")
 *   - AnsibleRepoWebhookController::receive (webhook GitHub auto-redeploy)
 *
 * Garde-fous (refus avec code de raison) :
 *   - machine inexistante
 *   - hostname vide (machine non encore inventoriée)
 *   - ansible_role manquant dans install_config_json
 *   - machine occupée (status IN installing/wiping/testing)
 *   - job déjà pending/running pour cette machine (anti-double-enqueue)
 */
final class AnsibleRedeployEnqueuer
{
    /**
     * @return array{ok:bool, reason:string, job_id?:int, existing_job_id?:int}
     *   reason ∈ { 'enqueued', 'machine_not_found', 'hostname_missing',
     *              'ansible_role_missing', 'machine_busy', 'already_pending' }
     *   job_id : présent si reason='enqueued'
     *   existing_job_id : présent si reason='already_pending' (utile pour rediriger vers le job en cours)
     */
    public static function eligibility(array $machine): array
    {
        if (trim((string)($machine['hostname']??''))==='') return ['ok'=>false,'reason'=>'hostname_missing','message'=>'Nom d’hôte absent.'];
        $config=Machine::installConfig($machine)??[];
        if (trim((string)($config['ansible_role']??''))==='') return ['ok'=>false,'reason'=>'ansible_role_missing','message'=>'Aucun rôle Ansible configuré pour cette machine.'];
        $stmt=Db::get()->prepare("SELECT id FROM init_jobs WHERE job_type=? AND status IN ('pending','running') LIMIT 1");
        $stmt->execute(['machine:ansible_redeploy:'.$machine['id']]);
        $existing=$stmt->fetchColumn();
        if ($existing) return ['ok'=>false,'reason'=>'already_pending','existing_job_id'=>(int)$existing,'message'=>'Un déploiement Ansible est déjà en attente ou en cours.'];
        $guard=MachineOperation::eligibility($machine,'ansible');
        if (!$guard['allowed']) return ['ok'=>false,'reason'=>'machine_busy','message'=>$guard['reason']];
        return ['ok'=>true,'reason'=>'ready','message'=>''];
    }

    public static function enqueue(int $machineId, string $message): array
    {
        try {
            return MachineOperation::run($machineId,null,static function (array $machine) use ($machineId,$message): array {
                $rule=self::eligibility($machine);
                if (!$rule['ok']) return $rule;
                Db::get()->prepare("INSERT INTO init_jobs (job_type,status,message) VALUES (?,'pending',?)")->execute(['machine:ansible_redeploy:'.$machineId,$message]);
                $jobId=(int)Db::get()->lastInsertId();
                MachineEvent::log($machineId,MachineEvent::LEVEL_ACTION,'Redéploiement Ansible planifié : '.$message);
                return ['ok'=>true,'reason'=>'enqueued','job_id'=>$jobId];
            });
        } catch (\RuntimeException $e) {
            if ($e->getCode()===404) return ['ok'=>false,'reason'=>'machine_not_found'];
            throw $e;
        }
    }
}
