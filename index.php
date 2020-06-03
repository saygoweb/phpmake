<?php

exec('nohup ' . __DIR__ . '/make.php install > /dev/null 2>&1 &');

$result = 'ok';
$length = strlen($result);

header("Content-Length: $length");

echo $result;