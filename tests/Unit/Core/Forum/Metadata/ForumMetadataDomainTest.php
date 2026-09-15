<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Metadata;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Metadata\CustomFieldDefinition;
use Forwext\Core\Forum\Metadata\CustomFieldKey;
use Forwext\Core\Forum\Metadata\CustomFieldTarget;
use Forwext\Core\Forum\Metadata\CustomFieldType;
use Forwext\Core\Forum\Metadata\ForumContentConfiguration;
use Forwext\Core\Forum\Metadata\PrefixGroup;
use Forwext\Core\Forum\Metadata\TagName;
use Forwext\Core\Forum\Metadata\ThreadMetadata;
use Forwext\Core\Forum\Metadata\ThreadPrefix;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ForumMetadataDomainTest extends TestCase
{
    public function testPrefixGroupsPrefixesAndTagNamesUseBoundedSafeValues(): void
    {
        $group = new PrefixGroup($this->id('a'), 'Release', 10, true);
        $prefix = new ThreadPrefix($this->id('b'), $group->id(), 'Stable', 20, true);
        $tag = TagName::fromString('PHP 8.4');

        self::assertSame('Release', $group->name());
        self::assertSame('Stable', $prefix->name());
        self::assertSame('PHP 8.4', $tag->value());

        $this->expectException(InvalidArgumentException::class);
        TagName::fromString("bad\x00tag");
    }

    public function testTypedCustomFieldValidationRejectsWrongTypesBoundsAndUnknownChoices(): void
    {
        $text = new CustomFieldDefinition(
            CustomFieldKey::fromString('thread.version'),
            CustomFieldTarget::Thread,
            'Version',
            CustomFieldType::Text,
            true,
            2,
            20,
        );
        $integer = new CustomFieldDefinition(
            CustomFieldKey::fromString('thread.players'),
            CustomFieldTarget::Thread,
            'Players',
            CustomFieldType::Integer,
            false,
            1,
            100,
        );
        $choice = new CustomFieldDefinition(
            CustomFieldKey::fromString('thread.channel'),
            CustomFieldTarget::Thread,
            'Channel',
            CustomFieldType::Choice,
            choices: ['stable' => 'Stable', 'beta' => 'Beta'],
        );

        self::assertSame('1.0', $text->validate('1.0')->value);
        self::assertSame(50, $integer->validate(50)->value);
        self::assertSame('beta', $choice->validate('beta')->value);

        foreach (
            [
                static fn () => $text->validate('x'),
                static fn () => $integer->validate('50'),
                static fn () => $choice->validate('nightly'),
            ] as $invalid
        ) {
            try {
                $invalid();
                self::fail('Invalid custom field value must fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTextCustomFieldHasPlatformHardCapEvenWithoutConfiguredMaximum(): void
    {
        $definition = new CustomFieldDefinition(
            CustomFieldKey::fromString('thread.notes'),
            CustomFieldTarget::Thread,
            'Notes',
            CustomFieldType::Text,
        );

        $this->expectException(InvalidArgumentException::class);
        $definition->validate(str_repeat('a', 100001));
    }

    public function testDisabledTagConfigurationMustBeFailClosed(): void
    {
        $forumId = $this->id('f');
        $configuration = new ForumContentConfiguration($forumId, [], [], false, false, 0);
        self::assertFalse($configuration->tagsEnabled());
        self::assertSame(0, $configuration->maxTags());

        $this->expectException(InvalidArgumentException::class);
        new ForumContentConfiguration($forumId, [], [], false, true, 5);
    }

    public function testThreadMetadataDeduplicatesTagsAndSortsFieldKeys(): void
    {
        $metadata = new ThreadMetadata(
            null,
            [TagName::fromString('PHP'), TagName::fromString('php')],
            [
                'thread.z' => (new CustomFieldDefinition(
                    CustomFieldKey::fromString('thread.z'),
                    CustomFieldTarget::Thread,
                    'Z',
                    CustomFieldType::Boolean,
                ))->validate(true),
                'thread.a' => (new CustomFieldDefinition(
                    CustomFieldKey::fromString('thread.a'),
                    CustomFieldTarget::Thread,
                    'A',
                    CustomFieldType::Boolean,
                ))->validate(false),
            ],
        );

        self::assertCount(1, $metadata->tags());
        self::assertSame(['thread.a', 'thread.z'], array_keys($metadata->fieldValues()));
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}
