<?php

declare(strict_types=1);

namespace Forwext\Core\Config;

final readonly class ConfigLoader
{
    public function __construct(private EnvironmentConfigSource $environmentSource = new EnvironmentConfigSource())
    {
    }

    /** @param array<string, mixed>|null $environmentVariables */
    public function load(
        string $defaultsFile,
        ?string $generatedFile = null,
        ?array $environmentVariables = null,
    ): ConfigRepository {
        $config = $this->loadPhpArray($defaultsFile, required: true);

        if ($generatedFile !== null && is_file($generatedFile)) {
            $config = $this->merge($config, $this->loadPhpArray($generatedFile, required: false));
        }

        $config = $this->merge($config, $this->environmentSource->load($environmentVariables));

        return new ConfigRepository($config);
    }

    /** @return array<string, mixed> */
    private function loadPhpArray(string $path, bool $required): array
    {
        if (!is_file($path)) {
            if ($required) {
                throw new ConfigException(sprintf('Required configuration file "%s" does not exist.', $path));
            }

            return [];
        }

        $loader = static fn (string $file): mixed => require $file;
        $data = $loader($path);

        if (!is_array($data)) {
            throw new ConfigException(sprintf('Configuration file "%s" must return an array.', $path));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                isset($base[$key])
                && is_array($base[$key])
                && is_array($value)
                && !array_is_list($base[$key])
                && !array_is_list($value)
            ) {
                /** @var array<string, mixed> $existing */
                $existing = $base[$key];
                /** @var array<string, mixed> $nestedOverride */
                $nestedOverride = $value;
                $base[$key] = $this->merge($existing, $nestedOverride);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
