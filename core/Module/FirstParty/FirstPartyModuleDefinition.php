<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use InvalidArgumentException;

final readonly class FirstPartyModuleDefinition
{
    /** @var list<string> */
    public array $dependencies;
    /** @var list<string> */
    public array $conflicts;
    /** @var list<string> */
    public array $routePrefixes;
    /** @var array<string,FirstPartyModuleSettingDefinition> */
    public array $settings;
    /** @var list<string> */
    public array $purgeTables;
    /** @var list<string> */
    public array $storagePathQueries;

    /**
     * @param list<string> $dependencies
     * @param list<string> $conflicts
     * @param list<string> $routePrefixes
     * @param list<FirstPartyModuleSettingDefinition> $settings
     * @param list<string> $purgeTables Child-first delete order.
     * @param list<string> $storagePathQueries Static internal SELECT queries returning storage_path.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        array $dependencies = [],
        array $conflicts = [],
        array $routePrefixes = [],
        array $settings = [],
        array $purgeTables = [],
        array $storagePathQueries = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('First-party module key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 100
            || $this->description === '' || strlen($this->description) > 500
        ) {
            throw new InvalidArgumentException('First-party module text is invalid.');
        }

        $this->dependencies = self::moduleKeys($dependencies, $this->key, 'dependency');
        $this->conflicts = self::moduleKeys($conflicts, $this->key, 'conflict');

        $routes = [];
        foreach ($routePrefixes as $prefix) {
            if (!is_string($prefix)
                || preg_match('/^[a-z][a-z0-9.-]{1,95}$/D', $prefix) !== 1
            ) {
                throw new InvalidArgumentException('First-party module route prefix is invalid.');
            }
            $routes[$prefix] = $prefix;
        }
        $this->routePrefixes = array_values($routes);

        $settingMap = [];
        foreach ($settings as $setting) {
            if (!$setting instanceof FirstPartyModuleSettingDefinition || isset($settingMap[$setting->key])) {
                throw new InvalidArgumentException('First-party module settings are invalid or duplicated.');
            }
            $settingMap[$setting->key] = $setting;
        }
        $this->settings = $settingMap;

        $tables = [];
        foreach ($purgeTables as $table) {
            if (!is_string($table) || preg_match('/^forwext_[a-z0-9_]{2,95}$/D', $table) !== 1) {
                throw new InvalidArgumentException('First-party module purge table is invalid.');
            }
            $tables[$table] = $table;
        }
        $this->purgeTables = array_values($tables);

        $queries = [];
        foreach ($storagePathQueries as $query) {
            if (!is_string($query)
                || preg_match('/^SELECT storage_path FROM forwext_[a-z0-9_]+(?: WHERE .+)?$/D', $query) !== 1
                || str_contains($query, ';')
            ) {
                throw new InvalidArgumentException('First-party module storage purge query is invalid.');
            }
            $queries[] = $query;
        }
        $this->storagePathQueries = $queries;
    }

    public function setting(string $key): ?FirstPartyModuleSettingDefinition
    {
        return $this->settings[$key] ?? null;
    }

    /** @param list<string> $values @return list<string> */
    private static function moduleKeys(array $values, string $ownKey, string $kind): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)
                || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1
                || $value === $ownKey
            ) {
                throw new InvalidArgumentException('First-party module ' . $kind . ' is invalid.');
            }
            $result[$value] = $value;
        }

        return array_values($result);
    }
}
