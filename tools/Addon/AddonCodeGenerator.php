<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

use Forwext\Core\Addon\AddonId;
use InvalidArgumentException;
use RuntimeException;

final readonly class AddonCodeGenerator
{
    public function __construct(private string $projectRoot)
    {
    }

    public function generate(AddonId $id, string $kind, string $className): string
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]{0,79}$/D', $className) !== 1) {
            throw new InvalidArgumentException('Generated class name must be PascalCase ASCII.');
        }

        $root = realpath($this->projectRoot);
        if (!is_string($root) || !is_file($root . '/VERSION')) {
            throw new InvalidArgumentException('Forwext project root is invalid.');
        }
        $addonRoot = $root . '/addons/' . $id->vendor() . '/' . $id->name();
        if (!is_dir($addonRoot) || is_link($addonRoot) || !is_file($addonRoot . '/addon.json')) {
            throw new InvalidArgumentException('Add-on must be created before generating code.');
        }

        [$relative, $source] = match ($kind) {
            'class' => $this->plainClass($id, $className),
            'service' => $this->service($id, $className),
            'entity' => $this->entity($id, $className),
            default => throw new InvalidArgumentException('Unsupported generator kind: ' . $kind),
        };

        $path = $addonRoot . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create generator directory.');
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Refusing to overwrite generated class.');
        }
        if (file_put_contents($path, $source, LOCK_EX) !== strlen($source)) {
            throw new RuntimeException('Unable to write generated class.');
        }

        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }

    /** @return array{string,string} */
    private function plainClass(AddonId $id, string $className): array
    {
        $namespace = $id->vendor() . '\\' . $id->name();

        return [
            'src/' . $className . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
                . "final class {$className}\n{\n}\n",
        ];
    }

    /** @return array{string,string} */
    private function service(AddonId $id, string $className): array
    {
        $namespace = $id->vendor() . '\\' . $id->name() . '\\Service';

        return [
            'src/Service/' . $className . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
                . "final readonly class {$className}\n{\n}\n",
        ];
    }

    /** @return array{string,string} */
    private function entity(AddonId $id, string $className): array
    {
        $namespace = $id->vendor() . '\\' . $id->name() . '\\Entity';

        return [
            'src/Entity/' . $className . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
                . "use Forwext\\Core\\Domain\\Entity\\Entity;\n"
                . "use Forwext\\Core\\Domain\\Entity\\EntityId;\n\n"
                . "final readonly class {$className} implements Entity\n{\n"
                . "    public function __construct(private EntityId \$entityId)\n    {\n    }\n\n"
                . "    public function id(): EntityId\n    {\n        return \$this->entityId;\n    }\n"
                . "}\n",
        ];
    }
}
