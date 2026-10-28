<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
Gyugyu\Database::migrate(Gyugyu\Database::connect());
echo "Migrations complete.\n";
