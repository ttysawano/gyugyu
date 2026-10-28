<?php
// Local development only: real Basic verification, never an authentication bypass.
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'],true)) { http_response_code(403); exit; }
$hash = getenv('GYUGYU_DEV_PASSWORD_HASH') ?: '';
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'developer' || !$hash || !password_verify($_SERVER['PHP_AUTH_PW'] ?? '',$hash)) {
    header('WWW-Authenticate: Basic realm="gyugyu local"'); http_response_code(401); exit;
}
$_SERVER['REMOTE_USER'] = 'developer';
$base = Gyugyu\Security::base();
$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (!is_string($path) || !str_starts_with($path,$base.'/')) { http_response_code(404); exit; }
$relative = substr($path,strlen($base)+1);
if ($relative === '') $relative = 'index.php';
$allowed = ['index.php','create.php','event.php','delete.php','groups.php','api/index.php'];
if (in_array($relative,$allowed,true)) {
    $_SERVER['SCRIPT_NAME'] = $base.'/'.$relative;
    require dirname(__DIR__).'/public/'.$relative;
} elseif (in_array($relative,['assets/gyugyu.css','assets/gyugyu-cow.svg','assets/candidates.mjs','assets/answers.mjs','assets/groups.mjs','assets/summary.mjs','assets/exports.mjs'],true)) {
    header('Content-Type: '.(str_ends_with($relative,'.css')?'text/css':(str_ends_with($relative,'.mjs')?'text/javascript':'image/svg+xml')));
    readfile(dirname(__DIR__).'/public/'.$relative);
} else http_response_code(404);
