<?php

declare(strict_types=1);

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

$root = dirname(__DIR__, 2);
$directories = ['app', 'core', 'modules', 'addons', 'database', 'public', 'tests', 'tools'];
$violations = [];
$checked = 0;

foreach ($directories as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        ++$checked;
        $contents = file_get_contents($file->getPathname());

        if ($contents === false || preg_match(
            '/\\A<\\?php(?:\\s|\\/\\*.*?\\*\\/|\/\/[^\\r\\n]*(?:\\R|$)|#[^\\r\\n]*(?:\\R|$))*declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;/s',
            $contents
        ) !== 1) {
            $violations[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "strict_types policy failed:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, ' - ' . $violation . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, sprintf("strict_types policy passed (%d PHP files checked).%s", $checked, PHP_EOL));
