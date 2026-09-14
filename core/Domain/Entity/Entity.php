<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Entity;

interface Entity
{
    public function id(): EntityId;
}
