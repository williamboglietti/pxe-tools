<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Db;
use Admin\Models\Action;
use Admin\Models\Machine;
use Admin\Models\MachineEvent;
use RuntimeException;

/** One lock and one set of eligibility rules for all machine mutation entry points. */
final class MachineOperation
{
    public static function eligibility(array $machine, string $operation = 'install'): array
    {
        $id = (int)$machine['id'];
        $deferred = $operation === 'install_deferred' && ($machine['status'] ?? '') === 'testing';
        $stmt = Db::get()->prepare("SELECT id,type,status FROM actions WHERE machine_id=? AND status IN ('pending','running') ORDER BY id DESC");
        $stmt->execute([$id]);
        $actions = $stmt->fetchAll();
        foreach ($actions as $action) {
            if ($deferred && $action['type'] === Action::TYPE_DISKTEST) continue;
            if ($operation==='power' && $machine['status']==='configured' && $action['status']==='pending' && $action['type']===Action::TYPE_INSTALL_ONLY) continue;
            return ['allowed'=>false,'reason'=>'Une opération est déjà programmée ou en cours. Consultez sa progression.','action_id'=>(int)$action['id']];
        }
        if (in_array($machine['status'] ?? '', ['installing','wiping','inventorying','testing'], true) && !$deferred) {
            return ['allowed'=>false,'reason'=>'Opération en cours : ' . $machine['status'] . '. Attendez sa fin.','action_id'=>null];
        }
        if (!empty($machine['rescue_mode']) && !in_array($operation, ['power','rescue_exit'], true)) {
            return ['allowed'=>false,'reason'=>'Désactivez le mode récupération avant cette opération.','action_id'=>null];
        }
        // Legacy rows without a parent action must still block a conflicting launch.
        foreach (['wipes'=>"wipe_status IN ('pending','running')",'installations'=>"(install_status IN ('pending','running','installing','in_progress') OR ansible_status IN ('pending','running','in_progress'))",'discoveries'=>"discovery_status IN ('pending','running','in_progress')",'disk_tests'=>"job_status IN ('pending','running')"] as $table=>$where) {
            if ($deferred && $table==='disk_tests') continue;
            $stmt=Db::get()->prepare("SELECT id FROM $table WHERE machine_id=? AND $where LIMIT 1"); $stmt->execute([$id]);
            if ($stmt->fetchColumn()) return ['allowed'=>false,'reason'=>'Un suivi actif existe encore. Consultez l’activité ou corrigez le suivi en mode Expert.','action_id'=>null];
        }
        $power=Db::get()->prepare("SELECT id FROM init_jobs WHERE job_type IN (?,?) AND status IN ('pending','running') LIMIT 1");
        $power->execute(['machine:reboot:'.$id,'machine:shutdown:'.$id]);
        if ($power->fetchColumn()) return ['allowed'=>false,'reason'=>'Une commande de redémarrage ou d’arrêt est en attente ou en cours. Attendez son résultat.','action_id'=>null];
        $rebootName=Db::driver()==='mysql'?"CONCAT('action:reboot:',a.id)":"'action:reboot:' || a.id";
        $power=Db::get()->prepare("SELECT a.id FROM init_jobs j JOIN actions a ON j.job_type=$rebootName WHERE a.machine_id=? AND j.status IN ('pending','running') LIMIT 1");
        $power->execute([$id]);
        if ($powerAction=$power->fetchColumn()) return ['allowed'=>false,'reason'=>'Le redémarrage d’une opération est en attente ou en cours.','action_id'=>(int)$powerAction];
        $stmt=Db::get()->prepare("SELECT id FROM init_jobs WHERE job_type=? AND status IN ('pending','running') LIMIT 1");
        $stmt->execute(['machine:ansible_redeploy:'.$id]);
        if ($stmt->fetchColumn()) return ['allowed'=>false,'reason'=>'Un déploiement Ansible est en attente ou en cours.','action_id'=>null];
        $jobName=Db::driver()==='mysql'?"CONCAT('action:ansible_retry:',a.id)":"'action:ansible_retry:' || a.id";
        $stmt=Db::get()->prepare("SELECT a.id FROM init_jobs j JOIN actions a ON j.job_type=$jobName WHERE a.machine_id=? AND j.status IN ('pending','running') LIMIT 1");
        $stmt->execute([$id]);
        if ($actionId=$stmt->fetchColumn()) return ['allowed'=>false,'reason'=>'Un réessai Ansible est en attente ou en cours.','action_id'=>(int)$actionId];
        return ['allowed'=>true,'reason'=>'','action_id'=>null];
    }

    /** The no-op UPDATE takes a write/row lock before reading (SQLite and MySQL). */
    public static function run(int $id, ?string $operation, callable $callback): mixed
    {
        $pdo=Db::get(); $owns=!$pdo->inTransaction();
        if ($owns) {
            // MySQL's repeatable-read snapshot can predate an awaited image lock.
            // Read committed makes validation observe a deletion that committed
            // while this operation was waiting, instead of scheduling a missing image.
            if (Db::driver() === 'mysql') $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('UPDATE machines SET id=id WHERE id=?')->execute([$id]);
            $machine=Machine::find($id);
            if (!$machine) throw new RuntimeException('Machine introuvable.',404);
            if ($operation!==null) {
                $rule=self::eligibility($machine,$operation);
                if (!$rule['allowed']) throw new RuntimeException($rule['reason'],409);
            }
            $result=$callback($machine);
            if ($owns) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function correctStatus(int $id, string $status): int
    {
        if (!in_array($status,Machine::STATUSES,true)) throw new RuntimeException('Statut invalide.',422);
        return self::run($id,null,static function (array $machine) use ($id,$status): int {
            $pdo=Db::get();
            $stmt=$pdo->prepare("SELECT id FROM actions WHERE machine_id=? AND status IN ('pending','running')"); $stmt->execute([$id]);
            $ids=$stmt->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($ids as $actionId) Action::forceComplete((int)$actionId);
            // Close legacy rows as well; this is administrative bookkeeping, not remote termination.
            foreach (['wipes'=>['wipe_status',"'pending','running'"],'installations'=>['install_status',"'pending','running','installing','in_progress'"],'discoveries'=>['discovery_status',"'pending','running','in_progress'"],'disk_tests'=>['job_status',"'pending','running'"]] as $table=>[$column,$states]) {
                $pdo->prepare("UPDATE $table SET $column='aborted',ended_at=CURRENT_TIMESTAMP WHERE machine_id=? AND $column IN ($states)")->execute([$id]);
            }
            $pdo->prepare("UPDATE installations SET ansible_status='aborted' WHERE machine_id=? AND ansible_status IN ('pending','running','in_progress')")->execute([$id]);
            $pdo->prepare("UPDATE init_jobs SET status='failed',message=? WHERE job_type IN (?, ?, ?) AND status='pending'")->execute(['Clôture administrative : demande en attente annulée.',"machine:reboot:$id","machine:shutdown:$id","machine:ansible_redeploy:$id"]);
            Machine::setStatus($id,$status);
            Machine::setNextAction($id,null); Machine::setAwaitingUntil($id,null);
            MachineEvent::log($id,MachineEvent::LEVEL_ACTION,'Correction administrative du suivi : '.$machine['status'].' → '.$status.'. '.count($ids).' action(s) clôturée(s). Aucun arrêt distant garanti.');
            return count($ids);
        });
    }
}
