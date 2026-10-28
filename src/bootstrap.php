<?php
declare(strict_types=1);
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Schedules.php';
require_once __DIR__ . '/Participants.php';
require_once __DIR__ . '/Groups.php';
require_once __DIR__ . '/Summary.php';
require_once __DIR__ . '/Export.php';
require_once __DIR__ . '/Security.php';
umask(0077);
ini_set('display_errors', '0');
