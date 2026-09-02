#!/usr/bin/env php
<?php
/**
 * Installs (or removes) make.phar in a bin directory on the user's PATH.
 *
 * Usage:
 *   php install.php [target-dir]
 *   php install.php --uninstall [target-dir]
 *
 * target-dir defaults to $MAKE_BIN, then ~/.local/bin.
 * make.php's command runner does not export HOME to child processes, so the
 * destination is resolved here (where HOME is available) rather than in the
 * makefile.json.
 */

$args = array_slice($argv, 1);
$uninstall = false;
foreach ($args as $i => $arg) {
    if ($arg === '--uninstall') {
        $uninstall = true;
        unset($args[$i]);
    }
}
$args = array_values($args);

$phar = __DIR__ . '/make.phar';
if (!$uninstall && !file_exists($phar)) {
    fwrite(STDERR, "make.phar not found; run 'php make.php build' first.\n");
    exit(1);
}

$dir = $args[0] ?? getenv('MAKE_BIN');
if (!$dir) {
    $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: null);
    if (!$home) {
        fwrite(STDERR, "Cannot determine home directory; pass a target dir: php install.php /path/to/bin\n");
        exit(1);
    }
    $dir = $home . '/.local/bin';
}

$dest = rtrim($dir, '/') . '/make.phar';

if ($uninstall) {
    if (file_exists($dest)) {
        unlink($dest);
        echo "Removed $dest\n";
    } else {
        echo "Nothing to remove at $dest\n";
    }
    exit(0);
}

if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}

if (!copy($phar, $dest)) {
    fwrite(STDERR, "Could not copy make.phar to $dest\n");
    exit(1);
}
chmod($dest, 0755);

echo "Installed $dest\n";
echo "Ensure $dir is on your PATH, then run: make.phar <target>\n";
