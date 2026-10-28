<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/src/bootstrap.php';
use Gyugyu\{Security,Database,Schedules,Participants,Groups,Summary,Export,Days,Problem};
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    Security::authorize(); Security::session();
    $service = new Schedules(Database::connect());
    $route = $_GET['route'] ?? '/instances';
    if (!is_string($route) || !preg_match('~^/instances(?:/([a-f0-9]{32})(/restore|/summary|/export|/days(?:/preview)?|/candidate-slots/(?:bulk|preview)|/participants(?:/([a-f0-9]{32})(/submission)?)?|/groups(?:/([a-f0-9]{32})(/restore)?)?)?)?$~D',$route,$m)) throw new Problem('見つかりません。',404);
    $method = $_SERVER['REQUEST_METHOD']; $id = $m[1] ?? null; $operation = $m[2] ?? ''; $restore = $operation === '/restore'; $bulk = $operation === '/candidate-slots/bulk';
    $participantId=$m[3] ?? null; $groupId=($m[5] ?? '') ?: null; $groupRestore=isset($m[6]);
    $data = [];
    if ($method !== 'GET') {
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) throw new Problem('JSONが必要です。',415);
        try {
            $json=file_get_contents('php://input'); $object=json_decode($json,false,64,JSON_THROW_ON_ERROR);
            if (!$object instanceof stdClass) throw new Problem('JSONオブジェクトが必要です。',400);
            foreach (['answers','group_ids','masks'] as $field) if (property_exists($object,$field) && !is_array($object->$field)) throw new Problem('回答・グループ・マスクはJSON配列で指定してください。',422);
            $data=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        } catch (JsonException $e) { throw new Problem('JSONが不正です。',400); }
        if (!is_array($data) || array_is_list($data) && $data !== []) throw new Problem('JSONオブジェクトが必要です。',400);
        Security::mutation($data);
    }
    if ($method === 'GET' && $operation === '') {
        $result = $id ? $service->get($id) : $service->listing(($_GET['deleted'] ?? '') === '1');
        header('X-CSRF-Token: '.$_SESSION['csrf']);
    } elseif ($method==='GET' && $id && $operation==='/days') $result=(new Days(Database::connect()))->listing($id);
    elseif ($id && in_array($operation,['/days','/days/preview'],true) && in_array($method,['POST','DELETE'],true)) {
        $action=$method==='DELETE'?'archive':($data['action'] ?? 'add');
        if (!is_string($action)||($operation==='/days/preview'&&$method!=='POST')||($operation==='/days'&&$method==='POST'&&$action==='archive')) throw new Problem('日付操作が不正です。',422);
        $result=(new Days(Database::connect()))->change($id,$action,$data,$operation==='/days/preview');
    } elseif ($method==='GET' && $id && $operation==='/export') {
        $file=(new Export(Database::connect()))->generate($id,$_GET);
        header('Content-Type: '.$file['type']);header('Content-Disposition: attachment; filename="'.$file['filename'].'"');
        header('Cache-Control: no-store');echo $file['content'];return;
    } elseif ($method==='GET' && $id && $operation==='/summary') {
        $mode=$_GET['mode'] ?? 'all';$selected=$_GET['group_ids'] ?? [];
        if (!is_string($mode)||!is_array($selected)) throw new Problem('集計条件が不正です。',422);
        $db=Database::connect();$db->beginTransaction();
        try {
            $row=(new Schedules($db))->get($id);
            $result=Summary::calculate($row,$mode,$selected)+['version'=>$row['version'],'schema_revision'=>$row['schema_revision'],'answers_revision'=>$row['answers_revision']];
            $db->commit();
        } catch (Throwable $e) { $db->rollBack();throw $e; }
    } elseif ($method==='GET' && $id && $operation==='/groups') {
        $row=$service->get($id); $result=['groups'=>(new Groups(Database::connect()))->listing($id,($_GET['archived'] ?? '')==='1'),'version'=>$row['version'],'schema_revision'=>$row['schema_revision'],'answers_revision'=>$row['answers_revision']];
    } elseif ($method==='POST' && $id && $operation==='/groups') { $result=(new Groups(Database::connect()))->change($id,null,'create',$data); http_response_code(201); }
    elseif ($groupId && (($method==='PATCH' && !$groupRestore) || ($method==='DELETE' && !$groupRestore) || ($method==='POST' && $groupRestore))) $result=(new Groups(Database::connect()))->change($id,$groupId,$groupRestore?'restore':($method==='DELETE'?'archive':'edit'),$data);
    elseif ($method === 'POST' && !$id) { $result = $service->create($data); http_response_code(201); }
    elseif ($method === 'POST' && $id && $operation === '/candidate-slots/preview') $result=$service->candidates($id,$data,true);
    elseif ($method === 'POST' && $id && $operation === '/participants') { $result=(new Participants(Database::connect()))->submit($id,null,$data); http_response_code(201); }
    elseif ($method === 'PATCH' && $id && $participantId && !isset($m[4])) $result=(new Participants(Database::connect()))->deactivate($id,$participantId,$data);
    elseif ($method === 'PUT' && $id && $participantId && isset($m[4])) $result=(new Participants(Database::connect()))->submit($id,$participantId,$data);
    elseif ($method === 'PATCH' && $id && $bulk) $result = $service->candidates($id,$data);
    elseif ($method === 'PATCH' && $id && $operation === '') $result = $service->change($id,'edit',$data);
    elseif ($method === 'DELETE' && $id && $operation === '') $result = $service->change($id,'delete',$data);
    elseif ($method === 'POST' && $id && $restore) $result = $service->change($id,'restore',$data);
    else throw new Problem('許可されていないメソッドです。',405);
    echo json_encode($result,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $status = $e instanceof Problem ? $e->getCode() : 500; http_response_code($status);
    if ($status === 401) header('WWW-Authenticate: Basic realm="gyugyu"');
    if ($status === 500) error_log('gyugyu API failed: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    echo json_encode(['error'=>$status === 500 ? '処理に失敗しました。' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
