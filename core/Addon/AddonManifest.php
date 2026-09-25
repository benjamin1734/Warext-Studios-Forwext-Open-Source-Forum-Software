<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use Forwext\Core\Migration\SemanticVersion;
use InvalidArgumentException;
use JsonException;

final readonly class AddonManifest
{
    /** @var array<string,AddonVersionConstraint> */
    public array $requires;
    /** @var array<string,AddonVersionConstraint> */
    public array $conflicts;

    /**
     * @param array<string,AddonVersionConstraint> $requires
     * @param array<string,AddonVersionConstraint> $conflicts
     */
    private function __construct(
        public AddonId $id,
        public AddonVersion $version,
        public string $title,
        public string $description,
        public SemanticVersion $minimumForwextVersion,
        array $requires,
        array $conflicts,
        public AddonDataRetentionPolicy $dataRetention,
    ) {
        $this->requires = $requires;
        $this->conflicts = $conflicts;
    }

    public static function fromJson(string $json): self
    {
        if ($json === '' || strlen($json) > 131072) {
            throw new InvalidArgumentException('Add-on manifest size is invalid.');
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Add-on manifest JSON is invalid.', previous:$exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Add-on manifest must be a JSON object.');
        }

        self::assertOnlyKeys($data, ['id','version','title','description','requires','conflicts','data_retention'], 'manifest');
        $id = AddonId::fromString(self::requiredString($data, 'id', 129));
        $version = AddonVersion::parse(self::requiredString($data, 'version', 64));
        $title = self::requiredString($data, 'title', 100);
        $description = self::optionalString($data, 'description', 1000) ?? '';

        $requiresSection = $data['requires'] ?? null;
        if (!is_array($requiresSection) || array_is_list($requiresSection)) {
            throw new InvalidArgumentException('Add-on manifest requires section must be an object.');
        }
        self::assertOnlyKeys($requiresSection, ['forwext','addons'], 'requires');
        $minimumForwextVersion = SemanticVersion::parse(self::requiredString($requiresSection, 'forwext', 64));
        $requires = self::dependencyMap($requiresSection['addons'] ?? [], $id, 'requires');

        $conflictsSection = $data['conflicts'] ?? [];
        if (!is_array($conflictsSection) || ($conflictsSection !== [] && array_is_list($conflictsSection))) {
            throw new InvalidArgumentException('Add-on manifest conflicts section must be an object.');
        }
        self::assertOnlyKeys($conflictsSection, ['addons'], 'conflicts');
        $conflicts = self::dependencyMap($conflictsSection['addons'] ?? [], $id, 'conflicts');

        foreach (array_keys($requires) as $requiredId) {
            if (isset($conflicts[$requiredId])) {
                throw new InvalidArgumentException('The same add-on cannot be both required and conflicting.');
            }
        }

        $retentionRaw = self::requiredString($data, 'data_retention', 32);
        $retention = AddonDataRetentionPolicy::tryFrom($retentionRaw)
            ?? throw new InvalidArgumentException('Add-on data retention policy is invalid.');

        return new self(
            $id,
            $version,
            $title,
            $description,
            $minimumForwextVersion,
            $requires,
            $conflicts,
            $retention,
        );
    }

    public function normalizedJson(): string
    {
        $requires = self::constraintsToStrings($this->requires);
        $conflicts = self::constraintsToStrings($this->conflicts);

        try {
            return json_encode([
                'id'=>$this->id->value(),
                'version'=>$this->version->value(),
                'title'=>$this->title,
                'description'=>$this->description,
                'requires'=>[
                    'forwext'=>$this->minimumForwextVersion->value(),
                    'addons'=>$requires,
                ],
                'conflicts'=>['addons'=>$conflicts],
                'data_retention'=>$this->dataRetention->value,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to normalize add-on manifest.', previous:$exception);
        }
    }

    /** @param array<string,mixed> $section @param list<string> $allowed */
    private static function assertOnlyKeys(array $section, array $allowed, string $name): void
    {
        foreach (array_keys($section) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unknown add-on ' . $name . ' key.');
            }
        }
    }

    /** @param array<string,mixed> $data */
    private static function requiredString(array $data, string $key, int $max): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Required add-on manifest string is missing: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Add-on manifest string is invalid: ' . $key);
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function optionalString(array $data, string $key, int $max): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Add-on manifest optional string is invalid: ' . $key);
        }

        return trim($value);
    }

    /**
     * @param mixed $data
     * @return array<string,AddonVersionConstraint>
     */
    private static function dependencyMap(mixed $data, AddonId $owner, string $kind): array
    {
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || count($data) > 128) {
            throw new InvalidArgumentException('Add-on ' . $kind . ' map is invalid.');
        }

        $result = [];
        foreach ($data as $idRaw=>$constraintRaw) {
            if (!is_string($idRaw) || !is_string($constraintRaw)) {
                throw new InvalidArgumentException('Add-on dependency entry is invalid.');
            }
            $id = AddonId::fromString($idRaw);
            if ($id->equals($owner)) {
                throw new InvalidArgumentException('Add-on cannot depend on or conflict with itself.');
            }
            $result[$id->value()] = AddonVersionConstraint::parse($constraintRaw);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @param array<string,AddonVersionConstraint> $constraints @return array<string,string> */
    private static function constraintsToStrings(array $constraints): array
    {
        $result = [];
        foreach ($constraints as $id=>$constraint) {
            $result[$id] = $constraint->value();
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
