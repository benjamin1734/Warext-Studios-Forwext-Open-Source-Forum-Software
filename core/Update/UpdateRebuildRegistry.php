<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use Closure;

final class UpdateRebuildRegistry
{
    /** @var array<string,Closure():void> */
    private array $actions = [];

    /** @param array<string,Closure():void> $actions */
    public function __construct(array $actions = [])
    {
        foreach ($actions as $name => $action) {
            $this->register($name, $action);
        }
    }

    /** @param Closure():void $action */
    public function register(string $name, Closure $action): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $name) !== 1) {
            throw new UpdateException('Update rebuild action name is invalid.');
        }
        if (isset($this->actions[$name])) {
            throw new UpdateException('Update rebuild action is already registered: ' . $name);
        }
        $this->actions[$name] = $action;
    }

    /** @param list<string> $names */
    public function validate(array $names): void
    {
        foreach ($names as $name) {
            if (!isset($this->actions[$name])) {
                throw new UpdateException('Update requires an unsupported rebuild action: ' . $name);
            }
        }
    }

    /** @param list<string> $names */
    public function run(array $names): void
    {
        $this->validate($names);
        foreach ($names as $name) {
            ($this->actions[$name])();
        }
    }
}
