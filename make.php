#!/usr/bin/env php
<?php

class Runner
{
    /** @var Array<string> */
    private $errors = [];

    /** @var bool True if any error was reported since construction */
    private $failed = false;

    /** @var Array */
    private $options;

    private $logFile = null;

    /** @var Array */
    private $makeConfig = null;

    /** @var Array */
    private $variables = null;

    public $doLog = true;

    public $doScreen = true;

    /** @var DateTime */
    private $startTime = null;

    public function __construct(Array $options, Array $variables)
    {
        $this->options = $options;
        $file = $this->options['file'];
        $path = absolutePath($file);
        if (!is_file($path)) {
            throw new RuntimeException("Makefile '$file' not found");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Makefile '$file' could not be read");
        }
        $this->makeConfig = json_decode($json, true);
        if (!is_array($this->makeConfig)) {
            throw new RuntimeException("Makefile '$file' is not valid JSON: " . json_last_error_msg());
        }
        if (!isset($this->makeConfig['variables'])) {
            $this->makeConfig['variables'] = [];
        }
        $this->variables = array_merge($this->makeConfig['variables'], $variables);
        $this->doScreen = (php_sapi_name() == 'cli') ? true : false;
    }

    public function __destruct()
    {
        $seconds = 0;
        if ($this->startTime) {
            $now = new DateTime();
            $elapsed = $this->startTime->diff($now);
            $seconds = $elapsed->h * 3600 + $elapsed->i * 60 + $elapsed->s;
        }
        $this->notice("Done in $seconds seconds");
        if ($this->logFile) {
            fclose($this->logFile);
        }
    }

    /**
     * The names of the targets in the loaded makefile.
     *
     * @return Array<string>
     */
    public function targets()
    {
        $targets = array_keys($this->makeConfig);
        return array_values(array_filter($targets, function ($name) {
            return $name !== 'variables';
        }));
    }

    /** @return bool True if any target failed or could not be found */
    public function hasErrors()
    {
        return $this->failed;
    }

    private function error(string $error)
    {
        $this->errors[] = $error;
        $this->failed = true;
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
                $result = file_exists(absolutePath($arg));
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
            $logDir = absolutePath('log');
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $this->logFile = @fopen($logDir . '/' . $dateString . ".log", 'w') ?: null;
        }
        $commands = $this->makeConfig;
        if ($command === 'variables' || !array_key_exists($command, $commands)) {
            $this->error("target '$command' not found in '{$this->options['file']}'");
            $targets = $this->targets();
            if ($targets) {
                $this->notice('Available targets: ' . implode(', ', $targets));
            }
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
                // Run from the invocation directory, not the script/phar location,
                // so commands and the makefile.json resolve against the same path.
                $cwd = getcwd();
                // Inherit the caller's environment (HOME, SSH_AUTH_SOCK, etc. are
                // needed by git/ssh) but pin PATH to a known-good value. $_ENV is
                // often empty depending on variables_order, so read the live env.
                $env = getenv();
                $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
                $descriptors = [
                    1 => ["pipe", "w"]
                ];
                $handle = proc_open($doer, $descriptors, $pipes, $cwd, $env);
                if ($handle && is_resource($handle)) {
                    while (($buffer = fgets($pipes[1])) !== false) {
                        $this->output($buffer);
                    }
                    fclose($pipes[1]);
                    // if (!feof($handle)) {
                    //     echo "Error: unexpected fgets() fail\n";
                    // }
                    $returnCode = proc_close($handle);
                    if ($returnCode != 0) {
                        $this->error("Return '$returnCode' from '$doer'");
                    }
                }
                return;
        }
    }
}

/**
 * Makes a path absolute against the working directory.
 *
 * Inside a phar the stat functions (is_file, is_dir, file_exists) resolve a
 * relative path against the archive instead of the working directory, so they
 * always report false, while reads and writes go to the real filesystem.
 * Resolving up front keeps the two agreeing whether make runs from make.php
 * or from make.phar.
 *
 * @param string $path
 * @return string
 */
function absolutePath($path)
{
    if ($path === '') {
        return $path;
    }
    $isAbsolute = $path[0] === '/'
        || $path[0] === '\\'
        || preg_match('~\A[A-Za-z]:[\\\\/]~', $path)
        || strpos($path, '://') !== false;
    if ($isAbsolute) {
        return $path;
    }
    return rtrim(getcwd(), '/\\') . '/' . $path;
}

/**
 * Turns the command line into the settings the Runner needs.
 *
 * Returns an array with:
 *  action    'run', 'help' or 'error'
 *  file      the makefile to load
 *  target    the target to build
 *  variables key => value pairs to make available to the makefile
 *  message   the reason, when action is 'error'
 *
 * @param Array<string> $argv
 * @return Array
 */
function parseArgs(Array $argv)
{
    $result = [
        'action' => 'run',
        'file' => 'makefile.json',
        'target' => '',
        'variables' => [],
        'message' => '',
    ];
    $count = count($argv);
    // $argv[0] is the script itself, so start at 1.
    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];
        if ($arg === '') {
            continue;
        }
        if ($arg[0] === '-') {
            // '-x', '--xx', '--xx=value' and '--xx value' are all options.
            $option = ltrim($arg, '-');
            $value = null;
            if (strpos($option, '=') !== false) {
                list($option, $value) = explode('=', $option, 2);
            }
            switch ($option) {
                case 'h':
                case 'help':
                    $result['action'] = 'help';
                    return $result;

                case 'f':
                case 'file':
                case 't':
                case 'target':
                    $key = ($option === 'f' || $option === 'file') ? 'file' : 'target';
                    if ($value === null) {
                        $i++;
                        if ($i >= $count) {
                            $result['action'] = 'error';
                            $result['message'] = "option '$arg' needs a value";
                            return $result;
                        }
                        $value = $argv[$i];
                    }
                    $result[$key] = $value;
                break;

                default:
                    $result['action'] = 'error';
                    $result['message'] = "unknown option '$arg'";
                    return $result;
            }
            continue;
        }
        // 'help', 'file=...', 'target=...' and 'key=value' without a leading
        // dash are the original syntax, still accepted.
        $tokens = explode('=', $arg, 2);
        if (count($tokens) == 2) {
            if ($tokens[0] === 'file' || $tokens[0] === 'target') {
                $result[$tokens[0]] = $tokens[1];
            } else {
                $result['variables'][$tokens[0]] = $tokens[1];
            }
            continue;
        }
        if ($arg === 'help') {
            $result['action'] = 'help';
            return $result;
        }
        if ($result['target'] === '') {
            $result['target'] = $arg;
            continue;
        }
        $result['action'] = 'error';
        $result['message'] = "unexpected argument '$arg', only one target can be built at a time";
        return $result;
    }
    if ($result['target'] === '') {
        $result['action'] = 'error';
        $result['message'] = 'no target given';
    }
    return $result;
}

function usage($name = 'make.php')
{
    echo <<<EOD
Usage: $name [options] [target]
 Options:
  -h, --help          displays this help
  -f, --file FILE     load rules from the given makefile (default makefile.json)
  -t, --target NAME   build the given target
  key=value           sets the variable 'key' to the given value

The target can be either the first plain argument or given by --target.
Options may also be written without dashes, e.g. 'file=build.json'.

EOD;
}

/**
 * @param Array<string> $argv
 * @return int The process exit code
 */
function main(Array $argv)
{
    $name = basename($argv[0] ?: 'make.php');
    $args = parseArgs($argv);
    if ($args['action'] === 'help') {
        usage($name);
        return 0;
    }
    if ($args['action'] === 'error') {
        fwrite(STDERR, "Error: {$args['message']}\n\n");
        usage($name);
        return 1;
    }
    try {
        $runner = new Runner($args, $args['variables']);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }
    $runner->run($args['target']);
    return $runner->hasErrors() ? 1 : 0;
}

if (php_sapi_name() == 'cli' && !defined('MAKE_PHP_NO_MAIN')) {
    exit(main($argv));
}
