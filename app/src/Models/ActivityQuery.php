<?php
declare(strict_types=1);
namespace Admin\Models;
use Admin\Db;

/** Read-only projection; canonical actions and historical URLs retain their identities. */
final class ActivityQuery
{
    private static function source(): string
    {
        // Legacy MySQL schemas give MAC a case-insensitive collation distinct
        // from hostname; UNION requires one explicit collation for the target.
        $target = 'COALESCE(m.hostname,m.mac)' . (Db::driver() === 'mysql' ? ' COLLATE utf8mb4_unicode_ci' : '');
        return "SELECT a.id, 'action' AS source, a.type, a.status, a.machine_id,
          $target AS target, a.created_by AS author, a.created_at,
          COALESCE(a.ended_at,(SELECT MAX(e.created_at) FROM machine_events e WHERE e.action_id=a.id),a.created_at) AS updated_at,
          (SELECT e.message FROM machine_events e WHERE e.action_id=a.id ORDER BY e.id DESC LIMIT 1) AS message
          FROM actions a JOIN machines m ON m.id=a.machine_id
          UNION ALL SELECT j.id,'job',j.job_type,j.status,NULL,'Appliance',NULL,j.created_at,COALESCE(j.updated_at,j.ended_at,j.created_at),j.message FROM init_jobs j
          UNION ALL SELECT i.id,'installation','install',CASE WHEN i.install_status='in_progress' THEN 'running' ELSE COALESCE(i.install_status,'pending') END,i.machine_id,$target,NULL,i.created_at,COALESCE(i.ended_at,i.started_at,i.created_at),NULL FROM installations i JOIN machines m ON m.id=i.machine_id WHERE i.action_id IS NULL
          UNION ALL SELECT w.id,'wipe','wipe',w.wipe_status,w.machine_id,$target,NULL,w.created_at,COALESCE(w.ended_at,w.started_at,w.created_at),NULL FROM wipes w JOIN machines m ON m.id=w.machine_id WHERE w.action_id IS NULL
          UNION ALL SELECT d.id,'disktest','disktest',d.job_status,d.machine_id,$target,NULL,d.created_at,COALESCE(d.ended_at,d.started_at,d.created_at),NULL FROM disk_tests d JOIN machines m ON m.id=d.machine_id WHERE d.action_id IS NULL";
    }
    public static function search(array $input): array
    {
        $queryText = is_scalar($input['q'] ?? '') ? trim((string)($input['q'] ?? '')) : '';
        preg_match('/\A.{0,120}/us', $queryText, $queryMatch);
        $q = $queryMatch[0] ?? '';
        $status=in_array($input['status']??'', ['pending','running','success','failed','cancelled','skipped','aborted'],true)?$input['status']:'';
        $type=in_array($input['type']??'', ['install','wipe','disktest','discovery','job'],true)?$input['type']:'';
        $machine=max(0,(int)($input['machine']??0)); $where=[]; $args=[];
        if($status!==''){$where[]='activity.status=?';$args[]=$status;}
        if($type==='job')$where[]="activity.source='job'";
        elseif($type==='install')$where[]="activity.type IN ('install','install_only','reinstall')";
        elseif($type==='wipe')$where[]="activity.type IN ('wipe','wipe_only')";
        elseif($type!==''){$where[]='activity.type=?';$args[]=$type;}
        if($machine){$where[]='activity.machine_id=?';$args[]=$machine;}
        if($q!==''){$where[]="(activity.target LIKE ? ESCAPE '!' OR activity.author LIKE ? ESCAPE '!' OR activity.message LIKE ? ESCAPE '!')";$like='%'.strtr($q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';array_push($args,$like,$like,$like);}
        $source=' FROM ('.self::source().') activity'.($where?' WHERE '.implode(' AND ',$where):'');
        $pdo=Db::get();$count=$pdo->prepare('SELECT COUNT(*)'.$source);$count->execute($args);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/30));$page=max(1,min($pages,(int)($input['page']??1)));$offset=($page-1)*30;
        $stmt=$pdo->prepare('SELECT *'.$source." ORDER BY updated_at DESC, source, id DESC LIMIT 30 OFFSET $offset");$stmt->execute($args);
        $items=$stmt->fetchAll();
        foreach($items as &$item)$item['url']=match($item['source']){'action'=>'/tasks/','job'=>'/jobs/','installation'=>'/installations/','wipe'=>'/wipes/',default=>'/disktests/'}.$item['id'];
        return compact('items','total','page','pages','q','status','type','machine');
    }
    public static function jobStatus(int $id): ?array
    {
        $stmt=Db::get()->prepare('SELECT id,job_type,status,progress_percent,message,started_at,ended_at,updated_at FROM init_jobs WHERE id=?');$stmt->execute([$id]);
        $row=$stmt->fetch();if(!$row)return null;$row['id']=(int)$row['id'];$row['progress_percent']=(int)$row['progress_percent'];return $row;
    }
}
