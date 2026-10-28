<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/src/bootstrap.php';
use Gyugyu\{Security,Database,Schedules,Problem};
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    Security::authorize(); Security::session();
    $service = new Schedules(Database::connect());
    $route = $_GET['route'] ?? '/instances';
    if (!is_string($route) || !preg_match('~^/instances(?:/([a-f0-9]{32})(/restore)?)?$~D',$route,$m)) throw new Problem('見つかりません。',404);
    $method = $_SERVER['REQUEST_METHOD']; $id = $m[1] ?? null; $restore = isset($m[2]);
    $data = [];
    if ($method !== 'GET') {
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) throw new Problem('JSONが必要です。',415);
        try { $data = json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new Problem('JSONが不正です。',400); }
        if (!is_array($data) || array_is_list($data) && $data !== []) throw new Problem('JSONオブジェクトが必要です。',400);
        Security::mutation($data);
    }
    if ($method === 'GET' && !$restore) {
        $result = $id ? $service->get($id) : $service->listing(($_GET['deleted'] ?? '') === '1');
        header('X-CSRF-Token: '.$_SESSION['csrf']);
    } elseif ($method === 'POST' && !$id) { $result = $service->create($data); http_response_code(201); }
    elseif ($method === 'PATCH' && $id && !$restore) $result = $service->change($id,'edit',$data);
    elseif ($method === 'DELETE' && $id && !$restore) $result = $service->change($id,'delete',$data);
    elseif ($method === 'POST' && $id && $restore) $result = $service->change($id,'restore',$data);
    else throw new Problem('許可されていないメソッドです。',405);
    echo json_encode($result,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $status = $e instanceof Problem ? $e->getCode() : 500; http_response_code($status);
    if ($status === 401) header('WWW-Authenticate: Basic realm="gyugyu"');
    if ($status === 500) error_log('gyugyu API failed: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    echo json_encode(['error'=>$status === 500 ? '処理に失敗しました。' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
