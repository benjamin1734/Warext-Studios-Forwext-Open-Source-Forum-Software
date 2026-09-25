<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor\Extension;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Backend\AddonBackendNamespace;
use Forwext\Core\Forum\Editor\EditorSurface;
use InvalidArgumentException;

final class EditorExtensionRegistry
{
    /** @var array<string,RegisteredEditorExtension> */
    private array $extensions = [];

    public function registerAddon(AddonId $owner, EditorToolbarExtension $extension): void
    {
        AddonBackendNamespace::fromAddonId($owner)->assertOwned($extension->key, 'Add-on editor extension');

        if (isset($this->extensions[$extension->key])) {
            throw new InvalidArgumentException('Editor extension key is already registered: ' . $extension->key);
        }

        $this->extensions[$extension->key] = new RegisteredEditorExtension($owner, $extension);
    }

    /** @return list<RegisteredEditorExtension> */
    public function forSurface(EditorSurface $surface): array
    {
        $items = array_values(array_filter(
            $this->extensions,
            static fn (RegisteredEditorExtension $registered): bool =>
                $registered->extension->supports($surface),
        ));
        usort(
            $items,
            static fn (RegisteredEditorExtension $left, RegisteredEditorExtension $right): int =>
                [$left->extension->order, $left->extension->label, $left->extension->key]
                <=> [$right->extension->order, $right->extension->label, $right->extension->key],
        );

        return $items;
    }

    /** @return list<RegisteredEditorExtension> */
    public function all(): array
    {
        $items = array_values($this->extensions);
        usort(
            $items,
            static fn (RegisteredEditorExtension $left, RegisteredEditorExtension $right): int =>
                $left->extension->key <=> $right->extension->key,
        );

        return $items;
    }
}
