<?php
declare(strict_types=1);
namespace Gyugyu;
use PDO;
final class Database
{
    public static function connect(?string $path = null): PDO
    {
        $path ??= getenv('GYUGYU_DB') ?: dirname(__DIR__) . '/var/gyugyu.sqlite';
        if ($path !== ':memory:') {
            $parent = realpath(dirname($path));
            $public = realpath(dirname(__DIR__) . '/public');
            $resolvedFile = realpath($path);
            if ($resolvedFile && str_starts_with($resolvedFile, $public . '/')) throw new \RuntimeException('DB must be outside public.');
            if (!$parent || $parent === $public || str_starts_with($parent, $public . '/')) {
                throw new \RuntimeException('DB must be outside public in an existing directory.');
            }
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
        return $pdo;
    }
    public static function migrate(PDO $db): void
    {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY)');
            foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
                $q = $db->prepare('SELECT 1 FROM migrations WHERE name=?');
                $q->execute([basename($file)]);
                if ($q->fetchColumn()) continue;
                $db->exec(file_get_contents($file));
                $db->prepare('INSERT INTO migrations VALUES (?)')->execute([basename($file)]);
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }
}
