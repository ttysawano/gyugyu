<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if ($argc !== 2) { fwrite(STDERR,"Usage: php bin/backup.php /private/existing-directory/new-backup.sqlite\n"); exit(1); }
$target = $argv[1];
$dir = realpath(dirname($target)); $public = realpath(dirname(__DIR__).'/public');
if (!$dir || $dir === $public || str_starts_with($dir,$public.'/') || file_exists($target)) throw new RuntimeException('Use a new path outside public.');
$db = Gyugyu\Database::connect();
$db->exec('VACUUM INTO '.$db->quote($dir.'/'.basename($target)));
echo "Backup complete.\n";
