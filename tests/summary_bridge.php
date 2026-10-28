<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
echo json_encode(array_map(fn($case)=>Gyugyu\Summary::calculate($case['row'],$case['mode'],$case['selected']),$cases),JSON_THROW_ON_ERROR);
