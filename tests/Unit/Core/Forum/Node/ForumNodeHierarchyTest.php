<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Node;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumDefaultThreadSort;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeLinkTarget;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Node\ForumSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ForumNodeHierarchyTest extends TestCase
{
    public function testHierarchySortsChildrenAndBuildsBreadcrumbs(): void
    {
        $root = ForumNode::category($this->id('1'), null, 'Community', $this->slug('community'));
        $general = ForumNode::forum(
            $this->id('2'),
            $root->id(),
            'General',
            $this->slug('general'),
            new ForumSettings(defaultThreadSort: ForumDefaultThreadSort::LastPost),
            sortOrder: 20,
        );
        $news = ForumNode::forum(
            $this->id('3'),
            $root->id(),
            'News',
            $this->slug('news'),
            new ForumSettings(),
            sortOrder: 10,
        );
        $rules = ForumNode::page(
            $this->id('4'),
            $general->id(),
            'Rules',
            $this->slug('rules'),
            'Community rules.',
        );

        $hierarchy = new ForumNodeHierarchy([$rules, $general, $root, $news]);

        self::assertSame(['News', 'General'], array_map(
            static fn (ForumNode $node): string => $node->title(),
            $hierarchy->children($root->id()),
        ));
        self::assertSame(['Community', 'General', 'Rules'], array_map(
            static fn (ForumNode $node): string => $node->title(),
            $hierarchy->breadcrumb($rules->id()),
        ));
        self::assertSame($general->id()->value(), $hierarchy->findBySlug($this->slug('general'))?->id()->value());
    }

    public function testVisibilitySeparatesDiscoveryFromResolution(): void
    {
        $root = ForumNode::category($this->id('1'), null, 'Root', $this->slug('root'));
        $unlisted = ForumNode::forum(
            $this->id('2'),
            $root->id(),
            'Secret Link',
            $this->slug('secret-link'),
            new ForumSettings(),
            visibility: ForumNodeVisibility::Unlisted,
        );
        $child = ForumNode::forum(
            $this->id('3'),
            $unlisted->id(),
            'Child',
            $this->slug('child'),
            new ForumSettings(),
        );

        $hierarchy = new ForumNodeHierarchy([$root, $unlisted, $child]);

        self::assertTrue($hierarchy->isResolvable($unlisted->id()));
        self::assertFalse($hierarchy->isDiscoverable($unlisted->id()));
        self::assertTrue($hierarchy->isResolvable($child->id()));
        self::assertFalse($hierarchy->isDiscoverable($child->id()));
        self::assertSame([], $hierarchy->navigationChildren($root->id()));
    }

    public function testDisabledAncestorMakesDescendantsUnresolvable(): void
    {
        $root = ForumNode::category(
            $this->id('1'),
            null,
            'Disabled root',
            $this->slug('disabled-root'),
            visibility: ForumNodeVisibility::Disabled,
        );
        $forum = ForumNode::forum(
            $this->id('2'),
            $root->id(),
            'Forum',
            $this->slug('forum'),
            new ForumSettings(),
        );

        $hierarchy = new ForumNodeHierarchy([$root, $forum]);

        self::assertFalse($hierarchy->isResolvable($forum->id()));
        self::assertFalse($hierarchy->isDiscoverable($forum->id()));
    }

    public function testPageAndLinkNodesCannotBecomeParents(): void
    {
        $page = ForumNode::page($this->id('1'), null, 'Page', $this->slug('page'), 'Body');
        $child = ForumNode::forum(
            $this->id('2'),
            $page->id(),
            'Child',
            $this->slug('child'),
            new ForumSettings(),
        );

        $this->expectException(InvalidArgumentException::class);
        new ForumNodeHierarchy([$page, $child]);
    }

    public function testHierarchyRejectsCyclesAndDuplicateSlugs(): void
    {
        try {
            new ForumNodeHierarchy([
                ForumNode::category($this->id('1'), $this->id('2'), 'One', $this->slug('one')),
                ForumNode::category($this->id('2'), $this->id('1'), 'Two', $this->slug('two')),
            ]);
            self::fail('Parent cycles must fail.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new ForumNodeHierarchy([
            ForumNode::category($this->id('3'), null, 'One', $this->slug('duplicate')),
            ForumNode::category($this->id('4'), null, 'Two', $this->slug('duplicate')),
        ]);
    }

    public function testForumSettingsAndLinkTargetsUseSafeBounds(): void
    {
        self::assertSame(20, (new ForumSettings())->threadsPerPage());
        self::assertTrue(ForumNodeLinkTarget::fromString('https://example.com/docs')->isExternal());
        self::assertFalse(ForumNodeLinkTarget::fromString('/help/rules')->isExternal());

        foreach (['javascript:alert(1)', '//evil.example/path', 'https://user:pass@example.com/'] as $invalid) {
            try {
                ForumNodeLinkTarget::fromString($invalid);
                self::fail('Unsafe link target must fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new ForumSettings(threadsPerPage: 101);
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function slug(string $value): ForumNodeSlug
    {
        return ForumNodeSlug::fromString($value);
    }
}
