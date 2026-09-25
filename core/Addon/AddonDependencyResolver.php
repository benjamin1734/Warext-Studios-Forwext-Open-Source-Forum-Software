<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use Forwext\Core\Migration\SemanticVersion;
use InvalidArgumentException;

final class AddonDependencyResolver
{
    /** @param array<string,AddonInstallation> $installations */
    public function assertPackageCompatible(
        AddonManifest $candidate,
        array $installations,
        SemanticVersion $forwextVersion,
    ): void {
        if (!$forwextVersion->equals($candidate->minimumForwextVersion)
            && !$forwextVersion->isGreaterThan($candidate->minimumForwextVersion)
        ) {
            throw new InvalidArgumentException('Add-on requires a newer Forwext version.');
        }

        $active = [];
        foreach ($installations as $id=>$installation) {
            if (!$installation instanceof AddonInstallation) {
                throw new InvalidArgumentException('Installed add-on graph is malformed.');
            }
            if ($installation->state !== AddonState::Uninstalled && $id !== $candidate->id->value()) {
                $active[$id] = $installation;
            }
        }
        $active[$candidate->id->value()] = new AddonInstallation(
            $candidate,
            AddonState::Disabled,
            AddonDataState::Retained,
            str_repeat('0', 64),
        );

        $this->assertGraphCompatible($active);
    }

    /** @param array<string,AddonInstallation> $installations */
    public function assertCanEnable(AddonId $id, array $installations): void
    {
        $candidate = $installations[$id->value()] ?? null;
        if (!$candidate instanceof AddonInstallation || $candidate->state !== AddonState::Disabled) {
            throw new InvalidArgumentException('Only a disabled installed add-on can be enabled.');
        }
        if ($candidate->dataState !== AddonDataState::Retained) {
            throw new InvalidArgumentException('Purged add-on data must be restored before enabling.');
        }

        foreach ($candidate->manifest->requires as $requiredId=>$constraint) {
            $required = $installations[$requiredId] ?? null;
            if (!$required instanceof AddonInstallation
                || $required->state !== AddonState::Enabled
                || !$constraint->matches($required->manifest->version)
            ) {
                throw new InvalidArgumentException('Required add-on must be enabled with a compatible version: ' . $requiredId);
            }
        }

        foreach ($installations as $other) {
            if (!$other instanceof AddonInstallation || $other->state !== AddonState::Enabled) {
                continue;
            }
            if ($this->manifestsConflict($candidate->manifest, $other->manifest)) {
                throw new InvalidArgumentException('Conflicting add-on is enabled: ' . $other->manifest->id->value());
            }
        }
    }

    /** @param array<string,AddonInstallation> $installations */
    public function assertCanDisable(AddonId $id, array $installations): void
    {
        foreach ($installations as $other) {
            if (!$other instanceof AddonInstallation
                || $other->state !== AddonState::Enabled
                || $other->manifest->id->equals($id)
            ) {
                continue;
            }
            if (isset($other->manifest->requires[$id->value()])) {
                throw new InvalidArgumentException('Enabled dependent add-on must be disabled first: ' . $other->manifest->id->value());
            }
        }
    }

    /** @param array<string,AddonInstallation> $installations */
    public function assertCanUninstall(AddonId $id, array $installations): void
    {
        foreach ($installations as $other) {
            if (!$other instanceof AddonInstallation
                || $other->state === AddonState::Uninstalled
                || $other->manifest->id->equals($id)
            ) {
                continue;
            }
            if (isset($other->manifest->requires[$id->value()])) {
                throw new InvalidArgumentException('Dependent add-on must be uninstalled first: ' . $other->manifest->id->value());
            }
        }
    }

    /**
     * Resolve a closed package set into deterministic dependency-first install order.
     *
     * @param iterable<AddonManifest> $manifests
     * @return list<AddonManifest>
     */
    public function resolveInstallOrder(iterable $manifests): array
    {
        $map = [];
        foreach ($manifests as $manifest) {
            if (!$manifest instanceof AddonManifest) {
                throw new InvalidArgumentException('Add-on dependency plan contains an invalid manifest.');
            }
            $id = $manifest->id->value();
            if (isset($map[$id])) {
                throw new InvalidArgumentException('Add-on dependency plan contains a duplicate package: ' . $id);
            }
            $map[$id] = $manifest;
        }
        ksort($map, SORT_STRING);

        foreach ($map as $manifest) {
            foreach ($manifest->requires as $requiredId=>$constraint) {
                $required = $map[$requiredId] ?? null;
                if (!$required instanceof AddonManifest) {
                    throw new InvalidArgumentException('Add-on dependency plan is missing required package: ' . $requiredId);
                }
                if (!$constraint->matches($required->version)) {
                    throw new InvalidArgumentException('Add-on dependency plan contains incompatible version: ' . $requiredId);
                }
            }
        }

        $values = array_values($map);
        for ($i = 0, $count = count($values); $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($this->manifestsConflict($values[$i], $values[$j])) {
                    throw new InvalidArgumentException(
                        'Add-on dependency plan contains conflicting packages: '
                        . $values[$i]->id->value() . ' / ' . $values[$j]->id->value(),
                    );
                }
            }
        }

        $visiting = [];
        $visited = [];
        $ordered = [];
        foreach (array_keys($map) as $id) {
            $this->visitManifest($id, $map, $visiting, $visited, $ordered);
        }

        return $ordered;
    }

    /**
     * @param array<string,AddonManifest> $map
     * @param array<string,bool> $visiting
     * @param array<string,bool> $visited
     * @param list<AddonManifest> $ordered
     */
    private function visitManifest(
        string $id,
        array $map,
        array &$visiting,
        array &$visited,
        array &$ordered,
    ): void {
        if (isset($visited[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            throw new InvalidArgumentException('Add-on dependency cycle detected at: ' . $id);
        }

        $visiting[$id] = true;
        $dependencies = array_keys($map[$id]->requires);
        sort($dependencies, SORT_STRING);
        foreach ($dependencies as $dependency) {
            $this->visitManifest($dependency, $map, $visiting, $visited, $ordered);
        }
        unset($visiting[$id]);
        $visited[$id] = true;
        $ordered[] = $map[$id];
    }

    /** @param array<string,AddonInstallation> $active */
    private function assertGraphCompatible(array $active): void
    {
        foreach ($active as $installation) {
            $manifest = $installation->manifest;
            foreach ($manifest->requires as $requiredId=>$constraint) {
                $required = $active[$requiredId] ?? null;
                if (!$required instanceof AddonInstallation) {
                    throw new InvalidArgumentException('Required add-on is not installed: ' . $requiredId);
                }
                if (!$constraint->matches($required->manifest->version)) {
                    throw new InvalidArgumentException('Installed add-on version does not satisfy requirement: ' . $requiredId);
                }
            }
        }

        $values = array_values($active);
        for ($i = 0, $count = count($values); $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($this->manifestsConflict($values[$i]->manifest, $values[$j]->manifest)) {
                    throw new InvalidArgumentException(
                        'Installed add-ons conflict: ' . $values[$i]->manifest->id->value()
                        . ' / ' . $values[$j]->manifest->id->value(),
                    );
                }
            }
        }

        $visiting = [];
        $visited = [];
        foreach (array_keys($active) as $id) {
            $this->visit($id, $active, $visiting, $visited);
        }
    }

    private function manifestsConflict(AddonManifest $left, AddonManifest $right): bool
    {
        $leftConstraint = $left->conflicts[$right->id->value()] ?? null;
        if ($leftConstraint instanceof AddonVersionConstraint && $leftConstraint->matches($right->version)) {
            return true;
        }
        $rightConstraint = $right->conflicts[$left->id->value()] ?? null;

        return $rightConstraint instanceof AddonVersionConstraint && $rightConstraint->matches($left->version);
    }

    /**
     * @param array<string,AddonInstallation> $active
     * @param array<string,bool> $visiting
     * @param array<string,bool> $visited
     */
    private function visit(string $id, array $active, array &$visiting, array &$visited): void
    {
        if (isset($visited[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            throw new InvalidArgumentException('Add-on dependency cycle detected at: ' . $id);
        }

        $visiting[$id] = true;
        foreach (array_keys($active[$id]->manifest->requires) as $dependency) {
            if (isset($active[$dependency])) {
                $this->visit($dependency, $active, $visiting, $visited);
            }
        }
        unset($visiting[$id]);
        $visited[$id] = true;
    }
}
