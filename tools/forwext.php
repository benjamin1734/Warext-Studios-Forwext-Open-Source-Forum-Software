<?php

declare(strict_types=1);

use Forwext\Tools\Addon\AddonCodeGenerator;
use Forwext\Tools\Addon\AddonScaffolder;
use Forwext\Tools\Cli\CliApplication;
use Forwext\Tools\Cli\Command\AddonCreateCommand;
use Forwext\Tools\Cli\Command\MakeClassCommand;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run Composer in the development checkout.\n");
    exit(1);
}
require $autoload;

$scaffolder = new AddonScaffolder($root);
$generator = new AddonCodeGenerator($root);
$app = new CliApplication([
    new AddonCreateCommand($scaffolder),
    new MakeClassCommand($generator, 'class'),
    new MakeClassCommand($generator, 'service'),
    new MakeClassCommand($generator, 'entity'),
]);

$result = $app->run($argv);
if ($result->stdout !== '') {
    fwrite(STDOUT, $result->stdout);
}
if ($result->stderr !== '') {
    fwrite(STDERR, $result->stderr);
}
exit($result->exitCode);
