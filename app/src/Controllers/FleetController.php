<?php
declare(strict_types=1);
namespace Admin\Controllers;
use Admin\Models\{FleetQuery,Machine,Preset,DiskTest};
use Admin\View;
final class FleetController
{
    private static function data(): array
    {
        $data=FleetQuery::search($_GET);
        $data['activeTests']=DiskTest::activeProgressByMachine();
        $data['sig']=hash('sha256',json_encode($data));
        return $data;
    }
    public function index(array $params): void
    {
        View::render('machines/index',self::data()+['statuses'=>Machine::STATUSES,'presets'=>Preset::all()],'Machines · PXE Tools');
    }
    public function fragment(array $params): void
    {
        $data=self::data();
        if (hash_equals($data['sig'], (string)($_SERVER['HTTP_X_LIST_SIG'] ?? $_GET['sig'] ?? ''))) { http_response_code(204); return; }
        View::partial('machines/_section',$data);
    }
}
