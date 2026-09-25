<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Backend\AddonBackendNamespace;
use Forwext\Core\Ui\Theme\ThemeTemplateCompiler;
use InvalidArgumentException;

final class AddonUiTemplateRegistry
{
    /** @var array<string,array{owner:AddonId,definition:AddonUiTemplateDefinition}> */
    private array $templates = [];

    public function __construct(private readonly ThemeTemplateCompiler $compiler = new ThemeTemplateCompiler())
    {
    }

    public function registerAddon(AddonId $owner, AddonUiTemplateDefinition $definition): void
    {
        AddonBackendNamespace::fromAddonId($owner)->assertOwned($definition->key, 'Add-on UI template');

        if (isset($this->templates[$definition->key])) {
            throw new InvalidArgumentException('Add-on UI template key is already registered: ' . $definition->key);
        }

        $this->templates[$definition->key] = [
            'owner'=>$owner,
            'definition'=>$definition,
        ];
    }

    /** @param array<string,scalar|null> $context */
    public function render(string $key, array $context): string
    {
        $entry = $this->templates[$key] ?? null;
        if ($entry === null) {
            throw new InvalidArgumentException('Unknown add-on UI template: ' . $key);
        }

        return $this->compiler->render($entry['definition']->source, $context);
    }

    /** @return list<AddonUiTemplateDefinition> */
    public function all(): array
    {
        $entries = $this->templates;
        ksort($entries, SORT_STRING);

        return array_map(
            static fn (array $entry): AddonUiTemplateDefinition => $entry['definition'],
            array_values($entries),
        );
    }
}
