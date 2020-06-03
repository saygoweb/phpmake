#!/usr/bin/env php
<?php

class Runner
{
    /** @var Array<string> */
    private $errors = [];

    private $logFile = null;

    /** @var Array */
    private $makeConfig = null;

    /** @var Array */
    private $variables = null;

    public $doLog = true;

    public $doScreen = true;

    /** @var DateTime */
    private $startTime = null;

    public function __construct(string $makeFile, Array $variables)
    {
        $json = file_get_contents($makeFile);
        $this->makeConfig = json_decode($json, true);
        if (!isset($this->makeConfig['variables'])) {
            $this->makeConfig['variables'] = [];
        }
        $this->variables = array_merge($this->makeConfig['variables'], $variables);
        $this->doScreen = (php_sapi_name() == 'cli') ? true : false;
    }

    public function __destruct()
    {
        $now = new DateTime();
        $elapsed = $this->startTime->diff($now);
        $seconds = $elapsed->h * 3600 + $elapsed->i * 60 + $elapsed->s;
        $this->notice("Done in $seconds seconds");
        if ($this->logFile) {
            fclose($this->logFile);
        }
    }

    private function error(string $error)
    {
        $this->errors[] = $error;
        $this->notice('Error: '. $error);
    }

    private function notice($message)
    {
        if ($this->doLog && $this->logFile) {
            fputs($this->logFile, $message);
            fputs($this->logFile, "\n");
        }
        if ($this->doScreen) {
            echo $message;
            echo "\n";
        }
    }

    private function output($line)
    {
        $line = ' | ' . $line;
        if ($this->doLog && $this->logFile) {
            fputs($this->logFile, $line);
        }
        if ($this->doScreen) {
            echo $line;
        }
    }

    private function testProvides($test, $arg)
    {
        $arg = $this->replaceVariables($arg);
        switch ($test) {
            case '#file':
            case '#folder':
                $result = file_exists($arg);
                return $result == true;
            break;
        }
        return false;
    }

    private function hasProvides($thisCommand)
    {
        if (!array_key_exists('provides', $thisCommand)) {
            return false;
        }
        $result = true;
        foreach ($thisCommand['provides'] as $value) {
            $tokens = explode(':', $value, 2);
            $result &= $this->testProvides($tokens[0], $tokens[1]);
        }
        return $result;
    }

    public function run($command)
    {
        $this->errors = [];
        if (!$this->startTime) {
            $this->startTime = new DateTime();
        }
        if ($this->doLog && !$this->logFile) {
            $now = new DateTime();
            $dateString = $now->format('Y-m-d_His');
            $this->logFile = fopen('log/' . $dateString . ".log", 'w');
        }
        $commands = $this->makeConfig;
        if (!array_key_exists($command, $commands)) {
            $this->error("$command not found");
            return;
        }
        $thisCommand = $commands[$command];
        if ($this->hasProvides($thisCommand)) {
            // Provided already so skip
            $this->notice("Skipping '$command' as it is already provided");
            return;
        }
        if (array_key_exists('depends', $thisCommand)) {
            foreach ($thisCommand['depends'] as $value) {
                $this->run($value);
            }
        }
        $this->notice("Running '$command'");
        if (array_key_exists('by', $thisCommand)) {
            foreach ($thisCommand['by'] as $value) {
                $execute = $this->replaceVariables($value);
                $this->do($execute);
            }
        }
    }

    private function replaceVariables($line)
    {
        $variables = $this->variables;
        $replaced = preg_replace_callback('/{{[^}]+}}/', function($match) use ($variables) {
            $key = str_replace(['{{', '}}'], '', $match[0]);
            return $variables[$key];
        }, $line);
        return $replaced;
    }

    public function do($doer)
    {
        // Check the type, assume execute
        $type = 'execute';

        switch ($type) {
            case 'execute':
                $this->notice(" Execute '$doer'");
                $handle = popen($doer, 'r');
                if ($handle) {
                    while (($buffer = fgets($handle)) !== false) {
                        $this->output($buffer);
                    }
                    // if (!feof($handle)) {
                    //     echo "Error: unexpected fgets() fail\n";
                    // }
                    $returnCode = pclose($handle);
                    if ($returnCode != 0) {
                        $this->error("Return '$returnCode' from '$doer'");
                    }
                }
                return;
        }
    }
}

function usage()
{
    echo <<<'EOD'
Usage: make target

EOD;
}

if (php_sapi_name() == 'cli') {
    $runner = new Runner('makefile.json', []);

    if ($argc != 2) {
        usage();
        exit;
    }
    $runner->run($argv[1]);
}
