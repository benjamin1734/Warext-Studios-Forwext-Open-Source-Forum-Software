<?php

declare(strict_types=1);

use Forwext\Tools\Addon\AddonCodeGenerator;
use Forwext\Tools\Addon\AddonCompatibilityChecker;
use Forwext\Tools\Addon\AddonIdeTypeGenerator;
use Forwext\Tools\Addon\AddonPackageBuilder;
use Forwext\Tools\Addon\AddonArtifactSigner;
use Forwext\Tools\Addon\AddonScaffolder;
use Forwext\Tools\Addon\DeveloperAddonPackageManager;
use Forwext\Tools\Cli\CliApplication;
use Forwext\Tools\Cli\Command\AddonBuildCommand;
use Forwext\Tools\Cli\Command\AddonCheckCommand;
use Forwext\Tools\Cli\Command\AddonSignCommand;
use Forwext\Tools\Cli\Command\AddonVerifyCommand;
use Forwext\Tools\Cli\Command\AddonCreateCommand;
use Forwext\Tools\Cli\Command\AddonWorkspaceCommand;
use Forwext\Tools\Cli\Command\DevModeCommand;
use Forwext\Tools\Cli\Command\IdeTypesCommand;
use Forwext\Tools\Cli\Command\MakeClassCommand;
use Forwext\Tools\Dev\DeveloperMode;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run Composer in the development checkout.\n");
    exit(1);
}
require $autoload;

$scaffolder = new AddonScaffolder($root);
$generator = new AddonCodeGenerator($root);
$developerMode = new DeveloperMode($root);
$compatibility = new AddonCompatibilityChecker($root);
$workspacePackages = new DeveloperAddonPackageManager($root);
$app = new CliApplication([
    new AddonCreateCommand($scaffolder),
    new MakeClassCommand($generator, 'class'),
    new MakeClassCommand($generator, 'service'),
    new MakeClassCommand($generator, 'entity'),
    new DevModeCommand($developerMode, 'enable'),
    new DevModeCommand($developerMode, 'disable'),
    new DevModeCommand($developerMode, 'status'),
    new AddonWorkspaceCommand($developerMode, $workspacePackages, 'install'),
    new AddonWorkspaceCommand($developerMode, $workspacePackages, 'upgrade'),
    new AddonCheckCommand($compatibility),
    new AddonBuildCommand(new AddonPackageBuilder($root, $compatibility)),
    new AddonSignCommand(new AddonArtifactSigner()),
    new AddonVerifyCommand(),
    new IdeTypesCommand(new AddonIdeTypeGenerator($root)),
]);

$result = $app->run($argv);
if ($result->stdout !== '') {
    fwrite(STDOUT, $result->stdout);
}
if ($result->stderr !== '') {
    fwrite(STDERR, $result->stderr);
}
exit($result->exitCode);
