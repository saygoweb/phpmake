#!/usr/bin/env php
<?php

require_once(__DIR__ . '/Makefile.php');
require_once(__DIR__ . '/Runner.php');

$config = new Makefile();
$runner = new Runner($config);

function usage()
{
    echo <<<'EOD'
Usage: make target

EOD;
}
if ($argc != 2) {
    usage();
    exit;
}
$runner->run($argv[1]);