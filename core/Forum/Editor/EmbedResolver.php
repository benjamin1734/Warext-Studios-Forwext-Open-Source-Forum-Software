<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

interface EmbedResolver
{
    public function resolve(string $url): ?EmbedTarget;
}
