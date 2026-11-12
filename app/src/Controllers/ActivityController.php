<?php
declare(strict_types=1);
namespace Admin\Controllers;
use Admin\Models\ActivityQuery;
use Admin\View;
final class ActivityController
{
    private static function data(): array { $data=ActivityQuery::search($_GET);$data['sig']=hash('sha256',json_encode($data));return $data; }
    public function index(array $params): void { View::render('activity/index',self::data(),'Activité · PXE Tools'); }
    public function fragment(array $params): void {
        $data=self::data();
        if(hash_equals($data['sig'],(string)($_GET['sig']??''))){http_response_code(204);return;}
        View::partial('activity/_list',$data);
    }
}
