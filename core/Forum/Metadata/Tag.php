<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class Tag
{
    public function __construct(
        private EntityId $id,
        private TagName $name,
    ) {
        MetadataId::assert($this->id);
    }

    public function id(): EntityId
    {
        return $this->id;
    }

    public function name(): TagName
    {
        return $this->name;
    }
}
