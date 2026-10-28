<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/src/bootstrap.php';
use Gyugyu\{Security,Database,Schedules,Participants,Problem};
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    Security::authorize(); Security::session();
    $service = new Schedules(Database::connect());
    $route = $_GET['route'] ?? '/instances';
    if (!is_string($route) || !preg_match('~^/instances(?:/([a-f0-9]{32})(/restore|/candidate-slots/(?:bulk|preview)|/participants(?:/([a-f0-9]{32})(/submission)?)?)?)?$~D',$route,$m)) throw new Problem('見つかりません。',404);
    $method = $_SERVER['REQUEST_METHOD']; $id = $m[1] ?? null; $operation = $m[2] ?? ''; $restore = $operation === '/restore'; $bulk = $operation === '/candidate-slots/bulk';
    $participantId=$m[3] ?? null;
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
    } elseif ($method === 'POST' && !$id) { $result = $service->create($data); http_response_code(201); }
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
