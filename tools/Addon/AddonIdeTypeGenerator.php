<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

use Forwext\Core\Addon\AddonId;
use RuntimeException;

final readonly class AddonIdeTypeGenerator
{
    private string $root;

    public function __construct(string $projectRoot)
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_file($root . '/VERSION')) {
            throw new RuntimeException('Forwext project root is invalid.');
        }
        $this->root = $root;
    }

    public function generate(AddonId $id): string
    {
        $addon = $this->root . '/addons/' . $id->vendor() . '/' . $id->name();
        if (!is_dir($addon) || !is_file($addon . '/addon.json')) {
            throw new RuntimeException('Add-on must exist before IDE types can be generated.');
        }
        $directory = $addon . '/.forwext';
        if (is_link($directory)) {
            throw new RuntimeException('IDE metadata directory may not be a symlink.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create IDE metadata directory.');
        }
        $namespace = $id->vendor() . '\\' . $id->name() . '\\Ide';
        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
            . "use Forwext\\Core\\Addon\\Backend\\AddonBackendRegistration;\n"
            . "use Forwext\\Core\\Addon\\Ui\\AddonUiRegistration;\n\n"
            . "/** @internal IDE-only type bridge; never loaded by the runtime. */\n"
            . "final class ForwextAddonTypes\n{\n"
            . "    public const ADDON_ID = '" . $id->value() . "';\n"
            . "    public const NAMESPACE_PREFIX = 'addon." . strtolower($id->vendor()) . "." . strtolower($id->name()) . "';\n\n"
            . "    public static function backend(AddonBackendRegistration \$registration): AddonBackendRegistration\n"
            . "    {\n        return \$registration;\n    }\n\n"
            . "    public static function ui(AddonUiRegistration \$registration): AddonUiRegistration\n"
            . "    {\n        return \$registration;\n    }\n"
            . "}\n";
        $path = $directory . '/ForwextAddonTypes.php';
        if (is_link($path) || file_put_contents($path, $source, LOCK_EX) !== strlen($source)) {
            throw new RuntimeException('Unable to write IDE type bridge.');
        }
        return str_replace('\\', '/', substr($path, strlen($this->root) + 1));
    }
}
