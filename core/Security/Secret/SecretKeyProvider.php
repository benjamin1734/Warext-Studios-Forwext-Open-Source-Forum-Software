<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

interface SecretKeyProvider
{
    public function load(): SecretKey;
}
