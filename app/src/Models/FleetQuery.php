<?php
declare(strict_types=1);
namespace Admin\Models;
use Admin\Db;

/** Read model for the fleet. Filtering, totals and paging share the same predicate. */
final class FleetQuery
{
    private const ACTIVE = "('inventorying','installing','wiping','testing')";
    private const ATTENTION = "('unknown','discovered','configured','failed')";

    public static function search(array $input): array
    {
        $queryText = is_scalar($input['q'] ?? '') ? trim((string)($input['q'] ?? '')) : '';
        preg_match('/\A.{0,120}/us', $queryText, $queryMatch);
        $q = $queryMatch[0] ?? '';
        $status = in_array($input['status'] ?? '', Machine::STATUSES, true) ? $input['status'] : 'all';
        $group = in_array($input['group'] ?? '', ['attention','running','failed'], true) ? $input['group'] : 'all';
        $sort = in_array($input['sort'] ?? '', ['name','recent','status'], true) ? $input['sort'] : 'name';
        $perPage = in_array((int)($input['per_page'] ?? 25), [25,50,100], true) ? (int)($input['per_page'] ?? 25) : 25;
        $where=[]; $args=[];
        if ($status !== 'all') { $where[]='m.status = ?'; $args[]=$status; }
        if ($group==='attention') $where[]='m.status IN '.self::ATTENTION;
        if ($group==='running') $where[]="(m.status IN ".self::ACTIVE." OR EXISTS (SELECT 1 FROM actions a WHERE a.machine_id=m.id AND a.status IN ('pending','running')))";
        if ($group==='failed') $where[]="m.status='failed'";
        if ($q!=='') {
            // Escape LIKE operators; bound values also prevent SQL injection.
            $like='%'.strtr($q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where[]="(m.hostname LIKE ? ESCAPE '!' OR m.mac LIKE ? ESCAPE '!' OR m.inventory_json LIKE ? ESCAPE '!')";
            array_push($args,$like,$like,$like);
        }
        $predicate=$where?' WHERE '.implode(' AND ',$where):'';
        $pdo=Db::get();
        $counts=$pdo->query("SELECT COUNT(*) AS total,
          SUM(CASE WHEN m.status IN ".self::ATTENTION." THEN 1 ELSE 0 END) AS attention,
          SUM(CASE WHEN m.status IN ".self::ACTIVE." OR EXISTS(SELECT 1 FROM actions a WHERE a.machine_id=m.id AND a.status IN ('pending','running')) THEN 1 ELSE 0 END) AS running,
          SUM(CASE WHEN m.status='failed' THEN 1 ELSE 0 END) AS failed FROM machines m")->fetch();
        $stats=array_map('intval',$counts);
        $count=$pdo->prepare('SELECT COUNT(*) FROM machines m'.$predicate); $count->execute($args);
        $filtered=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($filtered/$perPage));
        $page=max(1,min($pages,(int)($input['page']??1))); $offset=($page-1)*$perPage;
        $order=match($sort){'recent'=>'m.updated_at DESC, m.id DESC','status'=>'m.status, m.id',default=>"LOWER(COALESCE(NULLIF(m.hostname,''),m.mac)), m.id"};
        $query=$pdo->prepare('SELECT m.*, a.id AS current_action_id, a.type AS current_action_type, a.status AS current_action_status FROM machines m LEFT JOIN actions a ON a.id=(SELECT MAX(a2.id) FROM actions a2 WHERE a2.machine_id=m.id)'.$predicate." ORDER BY $order LIMIT $perPage OFFSET $offset");
        $query->execute($args);
        $machines=$query->fetchAll();
        foreach($machines as &$machine) $machine['offline_after_discovery']=Machine::isOfflineAfterDiscovery($machine);
        unset($machine);
        return ['machines'=>$machines,'stats'=>$stats,'total'=>$stats['total'],'filtered'=>$filtered,'page'=>$page,'pages'=>$pages,'perPage'=>$perPage,'q'=>$q,'statusFilter'=>$status,'group'=>$group,'sort'=>$sort];
    }
}
