<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

interface PermissionTemplateRepository
{
    public function find(PermissionTemplateKey $key): ?PermissionTemplate;

    /** @return list<PermissionTemplate> */
    public function all(): array;
}
