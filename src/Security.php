<?php
declare(strict_types=1);
namespace Gyugyu;
final class Security
{
    public static function base(): string
    {
        $base = getenv('GYUGYU_BASE_PATH') ?: '';
        if ($base !== '' && !preg_match('~^/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+$~D', $base)) throw new \RuntimeException('Invalid base path');
        return $base;
    }
    public static function authorize(): void
    {
        if (!is_string($_SERVER['REMOTE_USER'] ?? null) || trim($_SERVER['REMOTE_USER']) === '') throw new Problem('共通Basic認証が必要です。',401);
        if (PHP_SAPI !== 'cli-server' && ($_SERVER['HTTPS'] ?? '') !== 'on') throw new Problem('HTTPSが必要です。',403);
    }
    public static function session(): void
    {
        session_name('gyugyu_session');
        session_set_cookie_params(['path'=>self::base() . '/', 'secure'=>($_SERVER['HTTPS'] ?? '') === 'on', 'httponly'=>true, 'samesite'=>'Strict']);
        ini_set('session.use_strict_mode','1');
        if (!@session_start()) throw new \RuntimeException("Session storage unavailable");
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function mutation(array $data): void
    {
        self::authorize();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $data['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) throw new Problem('CSRFトークンが不正です。',403);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $expected = (getenv('GYUGYU_ORIGIN') ?: '');
        if ($expected === '' || !is_string($origin) || $origin !== $expected) throw new Problem('Originが一致しません。',403);
    }
}
