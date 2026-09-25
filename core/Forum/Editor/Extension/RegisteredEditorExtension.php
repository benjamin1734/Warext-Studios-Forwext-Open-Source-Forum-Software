<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor\Extension;

use Forwext\Core\Addon\AddonId;

final readonly class RegisteredEditorExtension
{
    public function __construct(
        public AddonId $owner,
        public EditorToolbarExtension $extension,
    ) {
    }
}
