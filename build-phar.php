#!/usr/bin/env php
<?php
/**
 * Packages make.php into a self-contained, executable make.phar.
 *
 * Phar writing is disabled by default (phar.readonly), so invoke as:
 *   php -d phar.readonly=0 build-phar.php [output-path]
 *
 * output-path defaults to make.phar beside this script.
 *
 * The bundled makefile.json's "phar" target already does this for you.
 */

$pharFile = $argv[1] ?? __DIR__ . '/make.phar';
$pharName = basename($pharFile);

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Refusing to build: phar.readonly is On.\n");
    fwrite(STDERR, "Run: php -d phar.readonly=0 build-phar.php\n");
    exit(1);
}

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile, 0, $pharName);
$phar->startBuffering();

// make.php begins with a "#!/usr/bin/env php" line for direct execution.
// The phar carries its own stub, so strip that line from the packaged copy.
$source = file_get_contents(__DIR__ . '/make.php');
$source = preg_replace('~\A#![^\n]*\n~', '', $source, 1);
$phar->addFromString('make.php', $source);

$phar->setStub("#!/usr/bin/env php\n" . $phar->createDefaultStub('make.php'));

$phar->stopBuffering();
chmod($pharFile, 0755);

echo "Built {$pharFile}\n";
