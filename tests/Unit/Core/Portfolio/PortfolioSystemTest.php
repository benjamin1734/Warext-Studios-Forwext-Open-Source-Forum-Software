<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Portfolio;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Portfolio\PortfolioMedia;
use Forwext\Core\Portfolio\PortfolioProject;
use Forwext\Core\Portfolio\PortfolioState;
use Forwext\Core\Profile\ProfileService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PortfolioSystemTest extends TestCase
{
    public function testProjectNormalizesTagsAndKeepsTypedMedia(): void
    {
        $now = new DateTimeImmutable('2026-09-19 10:00:00', new DateTimeZone('UTC'));
        $project = new PortfolioProject(
            PortfolioProject::generateId(),
            UserId::generate(),
            'general',
            'forwext-demo',
            'Forwext Demo',
            'Production forum platform',
            'A full forum project.',
            ['PHP', 'forum', 'php'],
            [new PortfolioMedia('/uploads/portfolio/shot.webp', 'Screenshot', 10)],
            PortfolioState::Draft,
            false,
            $now,
            $now,
        );

        self::assertSame(['php', 'forum'], $project->tags);
        self::assertCount(1, $project->media);
        self::assertSame('/uploads/portfolio/shot.webp', $project->media[0]->path);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $project->projectId->value());
    }

    public function testMediaRejectsExternalAndTraversalPaths(): void
    {
        foreach ([
            'https://example.com/image.webp',
            '//example.com/image.webp',
            '/uploads/../private/image.webp',
            '/uploads/file.svg',
        ] as $unsafe) {
            try {
                new PortfolioMedia($unsafe, '', 0);
                self::fail('Unsafe portfolio media path was accepted: ' . $unsafe);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDefaultProfileTabsIncludePortfolio(): void
    {
        $keys = array_map(static fn ($tab): string => $tab->key, ProfileService::defaultTabs());

        self::assertSame(['overview', 'activity', 'portfolio', 'about'], $keys);
    }

    public function testProjectRejectsMoreThanTwelveMediaItems(): void
    {
        $media = [];
        for ($i = 0; $i < 13; ++$i) {
            $media[] = new PortfolioMedia('/uploads/portfolio/' . $i . '.webp', '', $i);
        }

        $this->expectException(InvalidArgumentException::class);
        new PortfolioProject(
            PortfolioProject::generateId(),
            UserId::generate(),
            'general',
            'too-many-media',
            'Too many',
            '',
            'Body',
            [],
            $media,
            PortfolioState::Draft,
            false,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
