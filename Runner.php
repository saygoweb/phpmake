<?php

class Runner
{
    /** @var Makefile */
    private $config;

    /** @var Array<string> */
    private $errors = [];

    private $logFile = null;

    public function __construct(Makefile $config)
    {
        $this->config = $config;
    }

    public function __destruct()
    {
        if ($this->logFile) {
            fclose($this->logFile);
        }
    }

    private function error(string $error)
    {
        $this->errors[] = $error;
    }

    private function testProvides($test, $arg)
    {
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
        if ($this->config->doLog) {
            $now = new DateTime();
            $dateString = $now->format('Y-m-d_His');
            $this->logFile = fopen($dateString . ".log", 'w');
        }
        $commands = $this->config->commands;
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
                $this->do($value);
            }
        }
    }

    private function notice($message)
    {
        if ($this->config->doLog && $this->logFile) {
            fputs($this->logFile, $message);
            fputs($this->logFile, "\n");
        }
        if ($this->config->doScreen) {
            echo $message;
            echo "\n";
        }
    }

    private function output($line)
    {
        if ($this->config->doLog && $this->logFile) {
            fputs($this->logFile, $line);
        }
        if ($this->config->doScreen) {
            echo $line;
        }
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
                    pclose($handle);
                }
                return;
        }
    }
}
