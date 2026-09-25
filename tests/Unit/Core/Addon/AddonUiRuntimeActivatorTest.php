<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use DateTimeImmutable;
use Forwext\Core\Addon\AddonDataState;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonInstallation;
use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\AddonRepository;
use Forwext\Core\Addon\AddonState;
use Forwext\Core\Addon\Ui\AddonUiRegistration;
use Forwext\Core\Addon\Ui\AddonUiRegistry;
use Forwext\Core\Addon\Ui\AddonUiRuntimeActivator;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class AddonUiRuntimeActivatorTest extends TestCase
{
    public function testOnlyEnabledAddonUiRegistrationsRemainActive(): void
    {
        $discovered = new AddonUiRegistry();
        $discovered->register(new AddonUiRegistration(AddonId::fromString('Acme/Disabled')));
        $discovered->register(new AddonUiRegistration(AddonId::fromString('Acme/Unknown')));
        $discovered->register(new AddonUiRegistration(AddonId::fromString('Acme/Enabled')));

        $repository = new AddonUiActivationRepositoryFixture([
            'Acme/Enabled'=>$this->installation('Acme/Enabled', AddonState::Enabled),
            'Acme/Disabled'=>$this->installation('Acme/Disabled', AddonState::Disabled),
        ]);

        $active = (new AddonUiRuntimeActivator($repository))->enabledRegistry($discovered);

        self::assertSame(
            ['Acme/Enabled'],
            array_map(
                static fn (AddonUiRegistration $registration): string => $registration->addonId->value(),
                $active->all(),
            ),
        );
    }

    private function installation(string $id, AddonState $state): AddonInstallation
    {
        $manifest = AddonManifest::fromJson((string) json_encode([
            'id'=>$id,
            'version'=>'1.0.0',
            'title'=>$id,
            'description'=>'UI activation test fixture.',
            'requires'=>[
                'forwext'=>'0.0.1',
                'addons'=>[],
            ],
            'conflicts'=>[
                'addons'=>[],
            ],
            'data_retention'=>'retain_only',
        ], JSON_THROW_ON_ERROR));

        return new AddonInstallation(
            $manifest,
            $state,
            AddonDataState::Retained,
            str_repeat('b', 64),
        );
    }
}

final class AddonUiActivationRepositoryFixture implements AddonRepository
{
    /** @param array<string,AddonInstallation> $items */
    public function __construct(private array $items)
    {
    }

    public function find(AddonId $id): ?AddonInstallation
    {
        return $this->items[$id->value()] ?? null;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function save(AddonInstallation $installation, EntityId $actor, DateTimeImmutable $at): void
    {
        $this->items[$installation->manifest->id->value()] = $installation;
    }
}
