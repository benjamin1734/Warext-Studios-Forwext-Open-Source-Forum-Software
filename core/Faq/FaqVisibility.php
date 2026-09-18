<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

enum FaqVisibility: string
{
    case Public = 'public';
    case Members = 'members';
    case Staff = 'staff';

    public function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Members => 1,
            self::Staff => 2,
        };
    }

    public static function restrictive(self $left, self $right): self
    {
        return $left->rank() >= $right->rank() ? $left : $right;
    }
}
