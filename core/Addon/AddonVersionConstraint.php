<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use InvalidArgumentException;
use Stringable;

final readonly class AddonVersionConstraint implements Stringable
{
    /** @var list<array{operator:string,version:AddonVersion}> */
    private array $clauses;

    /** @param list<array{operator:string,version:AddonVersion}> $clauses */
    private function __construct(
        private string $value,
        array $clauses,
    ) {
        $this->clauses = $clauses;
    }

    public static function parse(string $value): self
    {
        $value = trim($value);
        if ($value === '*') {
            return new self('*', []);
        }
        if ($value === '' || strlen($value) > 191) {
            throw new InvalidArgumentException('Add-on version constraint is invalid.');
        }

        $tokens = preg_split('/\s+/', $value);
        if (!is_array($tokens) || $tokens === [] || count($tokens) > 8) {
            throw new InvalidArgumentException('Add-on version constraint is too complex.');
        }

        $clauses = [];
        foreach ($tokens as $token) {
            $matches = [];
            if (preg_match('/^(\^|~|>=|<=|>|<|=)?(.+)$/D', $token, $matches) !== 1) {
                throw new InvalidArgumentException('Add-on version constraint token is invalid.');
            }
            $clauses[] = [
                'operator'=>$matches[1] !== '' ? $matches[1] : '=',
                'version'=>AddonVersion::parse($matches[2]),
            ];
        }

        return new self($value, $clauses);
    }

    public function matches(AddonVersion $candidate): bool
    {
        foreach ($this->clauses as $clause) {
            if (!self::matchesClause($candidate, $clause['operator'], $clause['version'])) {
                return false;
            }
        }

        return true;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function matchesClause(AddonVersion $candidate, string $operator, AddonVersion $version): bool
    {
        $compare = $candidate->compare($version);

        return match ($operator) {
            '=' => $compare === 0,
            '>' => $compare > 0,
            '>=' => $compare >= 0,
            '<' => $compare < 0,
            '<=' => $compare <= 0,
            '^' => $compare >= 0 && $candidate->compare(self::caretUpperBound($version)) < 0,
            '~' => $compare >= 0 && $candidate->compare(
                AddonVersion::parse($version->major . '.' . ($version->minor + 1) . '.0'),
            ) < 0,
            default => throw new InvalidArgumentException('Unsupported add-on version operator.'),
        };
    }

    private static function caretUpperBound(AddonVersion $version): AddonVersion
    {
        if ($version->major > 0) {
            return AddonVersion::parse(($version->major + 1) . '.0.0');
        }
        if ($version->minor > 0) {
            return AddonVersion::parse('0.' . ($version->minor + 1) . '.0');
        }

        return AddonVersion::parse('0.0.' . ($version->patch + 1));
    }
}
