#!/usr/bin/env php
<?php
/**
 * Tests for the make.php command line.
 *
 * Run with: php tests/run.php   (or 'make.phar test')
 *
 * parseArgs() is exercised directly; the rest is exercised by running
 * make.php as a subprocess so that exit codes and messages are covered too.
 */

define('MAKE_PHP_NO_MAIN', true);
require __DIR__ . '/../make.php';

$passed = 0;
$failures = [];

function check($condition, $description, $detail = '')
{
    global $passed, $failures;
    if ($condition) {
        $passed++;
        return;
    }
    $failures[] = $description . ($detail ? "\n    $detail" : '');
}

function equals($actual, $expected, $description)
{
    check(
        $actual === $expected,
        $description,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function contains($haystack, $needle, $description)
{
    check(
        strpos($haystack, $needle) !== false,
        $description,
        "expected to find '$needle' in:\n" . trim($haystack)
    );
}

function notContains($haystack, $needle, $description)
{
    check(
        strpos($haystack, $needle) === false,
        $description,
        "did not expect '$needle' in:\n" . trim($haystack)
    );
}

/**
 * Runs make.php in a scratch directory, so that the log folder and any
 * relative makefile.json do not touch the repository.
 *
 * @return Array [exitCode, output] with stdout and stderr combined
 */
function make(Array $args, $cwd = null)
{
    $cwd = $cwd ?: makeScratchDir();
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(realpath(__DIR__ . '/../make.php'));
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $handle = proc_open($command, $descriptors, $pipes, $cwd);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($handle), $output];
}

/**
 * Builds make.phar into $dir and returns its path, or null when phars
 * cannot be written here.
 *
 * @return string|null
 */
function buildPhar($dir)
{
    if (!class_exists('Phar')) {
        return null;
    }
    $phar = $dir . '/make.phar';
    $command = escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 '
        . escapeshellarg(realpath(__DIR__ . '/../build-phar.php')) . ' ' . escapeshellarg($phar)
        . ' 2>&1';
    exec($command, $lines, $code);
    if ($code !== 0 || !is_file($phar)) {
        echo "SKIP: could not build a phar to test against: " . implode("\n", $lines) . "\n";
        return null;
    }
    return $phar;
}

/**
 * Runs a built phar in its own directory.
 *
 * @return Array [exitCode, output] with stdout and stderr combined
 */
function runPhar($phar, Array $args)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $handle = proc_open($command, $descriptors, $pipes, dirname($phar));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($handle), $output];
}

function makeScratchDir()
{
    $dir = sys_get_temp_dir() . '/phpmake-test-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0777, true);
    register_shutdown_function('removeDir', $dir);
    return $dir;
}

function removeDir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? removeDir($path) : unlink($path);
    }
    rmdir($dir);
}

/** A scratch directory holding a copy of the fixture as makefile.json */
function scratchWithMakefile()
{
    $dir = makeScratchDir();
    copy(__DIR__ . '/fixtures/makefile.json', $dir . '/makefile.json');
    return $dir;
}

$fixture = __DIR__ . '/fixtures/makefile.json';

// --- parseArgs -------------------------------------------------------------

$args = parseArgs(['make.phar']);
equals($args['action'], 'error', 'no arguments is an error');
equals($args['message'], 'no target given', 'no arguments explains the target is missing');

foreach ([['-h'], ['--help'], ['help'], ['--help', 'build'], ['build', '-h']] as $argv) {
    $args = parseArgs(array_merge(['make.phar'], $argv));
    equals($args['action'], 'help', 'help requested by ' . implode(' ', $argv));
}

$args = parseArgs(['make.phar', 'build']);
equals($args['action'], 'run', 'a plain argument runs');
equals($args['target'], 'build', 'the first plain argument is the target');
equals($args['file'], 'makefile.json', 'the makefile defaults to makefile.json');

$args = parseArgs(['make.phar', '-f', 'other.json', 'build']);
equals($args['file'], 'other.json', '-f takes the next argument as the makefile');
equals($args['target'], 'build', '-f does not swallow the target');

$args = parseArgs(['make.phar', '--file=other.json', '--target=build']);
equals($args['file'], 'other.json', '--file=value sets the makefile');
equals($args['target'], 'build', '--target=value sets the target');

$args = parseArgs(['make.phar', '-t', 'build']);
equals($args['target'], 'build', '-t takes the next argument as the target');

$args = parseArgs(['make.phar', 'file=other.json', 'target=build']);
equals($args['file'], 'other.json', 'the dashless file= form still works');
equals($args['target'], 'build', 'the dashless target= form still works');

$args = parseArgs(['make.phar', 'build', 'who=world', 'greeting=hi there']);
equals($args['variables'], ['who' => 'world', 'greeting' => 'hi there'], 'key=value pairs become variables');
equals($args['target'], 'build', 'variables do not become the target');

$args = parseArgs(['make.phar', '-x']);
equals($args['action'], 'error', 'an unknown short option is an error');
contains($args['message'], "unknown option '-x'", 'the unknown short option is named');

$args = parseArgs(['make.phar', '--nope']);
contains($args['message'], "unknown option '--nope'", 'the unknown long option is named');

foreach (['-', '--'] as $arg) {
    $args = parseArgs(['make.phar', $arg]);
    equals($args['action'], 'error', "'$arg' alone is treated as an option, not a target");
}

$args = parseArgs(['make.phar', '-f']);
equals($args['action'], 'error', 'a dangling -f is an error');
contains($args['message'], "option '-f' needs a value", 'the dangling option is named');

$args = parseArgs(['make.phar', 'build', 'install']);
equals($args['action'], 'error', 'two targets is an error');
contains($args['message'], "unexpected argument 'install'", 'the extra target is named');

// --- the command line end to end -------------------------------------------

list($code, $output) = make([]);
equals($code, 1, 'no arguments exits non-zero');
contains($output, 'Usage:', 'no arguments shows the usage');
contains($output, 'no target given', 'no arguments says what was missing');
notContains($output, 'not found', 'no arguments does not report a missing file as the target');

foreach (['-h', '--help', 'help'] as $flag) {
    list($code, $output) = make([$flag]);
    equals($code, 0, "'$flag' exits zero");
    contains($output, 'Usage:', "'$flag' shows the usage");
    contains($output, '-f, --file', "'$flag' documents the file option");
    notContains($output, 'Done in', "'$flag' does not run a build");
}

list($code, $output) = make(['-f', $fixture, 'nosuch']);
equals($code, 1, 'an unknown target exits non-zero');
contains($output, "target 'nosuch' not found", 'an unknown target is named in the error');
contains($output, 'Available targets: hello, greet, chained, boom', 'an unknown target lists the targets');

list($code, $output) = make(['-f', $fixture, 'hello']);
equals($code, 0, 'a known target exits zero');
contains($output, "Running 'hello'", 'a known target runs');
contains($output, ' | hello', 'a known target produces its output');

list($code, $output) = make(['-f', $fixture, 'greet', 'who=everyone']);
equals($code, 0, 'a target with variables exits zero');
contains($output, 'greeting everyone', 'a variable from the command line is substituted');

list($code, $output) = make(['-f', $fixture, 'chained']);
contains($output, "Running 'hello'", 'a dependency is run');
contains($output, "Running 'chained'", 'the dependent target is run');

list($code, $output) = make(['-f', $fixture, 'boom']);
equals($code, 1, 'a failing command exits non-zero');
contains($output, "Return '3'", 'a failing command reports its return code');

list($code, $output) = make(['-f', 'missing.json', 'hello']);
equals($code, 1, 'a missing makefile exits non-zero');
contains($output, "Makefile 'missing.json' not found", 'a missing makefile is reported by name');
notContains($output, 'Done in', 'a missing makefile does not pretend to have built anything');

list($code, $output) = make(['-f', __DIR__ . '/fixtures/broken.json', 'hello']);
equals($code, 1, 'an invalid makefile exits non-zero');
contains($output, 'is not valid JSON', 'an invalid makefile says so');

// The default makefile.json in the working directory, the common case.
$dir = scratchWithMakefile();
list($code, $output) = make(['hello'], $dir);
equals($code, 0, 'the default makefile.json is picked up from the working directory');
contains($output, "Running 'hello'", 'the default makefile.json runs the target');

list($code, $output) = make(['variables'], $dir);
equals($code, 1, "'variables' is not a target");

// --- absolutePath ----------------------------------------------------------

equals(absolutePath('/etc/hosts'), '/etc/hosts', 'an absolute path is left alone');
equals(absolutePath('phar:///a/b.phar/x'), 'phar:///a/b.phar/x', 'a stream path is left alone');
equals(absolutePath('makefile.json'), getcwd() . '/makefile.json', 'a relative path is resolved against the working directory');
equals(absolutePath('a/b.json'), getcwd() . '/a/b.json', 'a nested relative path is resolved');
equals(absolutePath(''), '', 'an empty path is left alone');

// --- the same command line, packaged as make.phar --------------------------
// Inside a phar the stat functions resolve relative paths against the archive,
// so the makefile and the 'provides' checks are only correct if make resolves
// them against the working directory first.

$dir = scratchWithMakefile();
$phar = buildPhar($dir);
if ($phar) {
    list($code, $output) = runPhar($phar, []);
    equals($code, 1, 'the phar with no arguments exits non-zero');
    contains($output, 'Usage:', 'the phar with no arguments shows the usage');
    notContains($output, 'make.phar not found', 'the phar does not report itself as a missing target');

    list($code, $output) = runPhar($phar, ['--help']);
    equals($code, 0, 'the phar shows help');
    contains($output, 'Usage: make.phar', 'the phar names itself in the usage');

    list($code, $output) = runPhar($phar, ['hello']);
    equals($code, 0, 'the phar finds makefile.json in the working directory');
    contains($output, ' | hello', 'the phar runs the target');

    list($code, $output) = runPhar($phar, ['nosuch']);
    equals($code, 1, 'the phar reports an unknown target');
    contains($output, "target 'nosuch' not found", 'the phar names the unknown target');

    // 'provides' must see files in the working directory, not in the archive.
    file_put_contents($dir . '/makefile.json', json_encode([
        'done' => [
            'provides' => ['#file:marker.txt'],
            'by' => ['echo building'],
        ],
    ]));
    list($code, $output) = runPhar($phar, ['done']);
    contains($output, ' | building', 'the phar builds a target that is not yet provided');

    file_put_contents($dir . '/marker.txt', "done\n");
    list($code, $output) = runPhar($phar, ['done']);
    contains($output, "Skipping 'done'", 'the phar skips a target already provided in the working directory');
    equals($code, 0, 'a skipped target exits zero');
}

// --- report ----------------------------------------------------------------

$total = $passed + count($failures);
foreach ($failures as $failure) {
    echo "FAIL: $failure\n";
}
echo ($failures ? count($failures) . " of $total checks failed\n" : "All $total checks passed\n");
exit($failures ? 1 : 0);
