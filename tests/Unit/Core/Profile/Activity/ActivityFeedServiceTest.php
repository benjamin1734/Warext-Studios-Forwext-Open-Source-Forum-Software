<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile\Activity;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Profile\Activity\ActivityFeedEntry;
use Forwext\Core\Profile\Activity\ActivityFeedRepository;
use Forwext\Core\Profile\Activity\ActivityFeedService;
use Forwext\Core\Profile\Activity\ActivityFeedType;
use Forwext\Core\Profile\Activity\ProfileActivityBody;
use Forwext\Core\Profile\Activity\ProfileActivityModerationState;
use Forwext\Core\Profile\Activity\ProfileActivityRepository;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use Forwext\Core\Profile\Activity\ProfileComment;
use Forwext\Core\Profile\Activity\ProfilePost;
use Forwext\Core\Social\Interaction\ReactionSummary;
use Forwext\Core\Social\Interaction\ReactionType;
use Forwext\Core\Social\Interaction\SocialInteractionRepository;
use PHPUnit\Framework\TestCase;

final class ActivityFeedServiceTest extends TestCase
{
    public function testFeedFiltersIgnoredActorsAndUnauthorizedForumEntriesWhileKeepingVisibleProfileActivity(): void
    {
        $viewer = $this->id('1'); $ignored = $this->id('2'); $owner = $this->id('3');
        $allowedForum = $this->id('4'); $deniedForum = $this->id('5');
        $profilePostId = $this->id('6'); $now = $this->now();
        $profileRepo = new FeedProfileRepository(new ProfilePost($profilePostId, $owner, $owner, ProfileActivityBody::fromString('Profile update'), ProfileActivityModerationState::Visible, null, $now, $now));
        $social = new FeedSocialRepository([$ignored]);
        $authorizer = new PermissionAuthorizer(new PermissionEngine(new FeedPermissionRepository($deniedForum)), new FeedAssignmentProvider($this->id('a')));
        $profileService = new ProfileActivityService($profileRepo, $social, new FeedUserRepository([$this->user($viewer, 'Viewer'), $this->user($owner, 'Owner')]), $authorizer);
        $source = new FeedMemoryRepository([
            new ActivityFeedEntry(ActivityFeedType::ThreadCreated, $ignored, $this->id('7'), $allowedForum, null, null, $now, 'ignored'),
            new ActivityFeedEntry(ActivityFeedType::ThreadCreated, $owner, $this->id('8'), $deniedForum, null, null, $now, 'denied'),
            new ActivityFeedEntry(ActivityFeedType::ProfilePostCreated, $owner, $profilePostId, null, $owner, $profilePostId, $now, 'visible profile'),
        ]);
        $service = new ActivityFeedService($source, $profileRepo, $profileService, $social, $authorizer);

        $result = $service->feed($viewer, 10);
        self::assertCount(1, $result);
        self::assertSame(ActivityFeedType::ProfilePostCreated, $result[0]->type);
        self::assertSame('visible profile', $result[0]->summary);
    }

    private function user(EntityId $id, string $name): User
    {
        return User::create($id, Username::fromString($name), EmailAddress::fromString(strtolower($name) . '@example.com'), UserStatus::Active, UserLocale::fromString('tr-TR'), UserTimezone::fromString('UTC'), $this->now());
    }
    private function now(): DateTimeImmutable { return new DateTimeImmutable('2026-09-16 21:30:00', new DateTimeZone('UTC')); }
    private function id(string $seed): EntityId { return EntityId::fromString(str_repeat($seed, 32)); }
}

final readonly class FeedMemoryRepository implements ActivityFeedRepository
{
    /** @param list<ActivityFeedEntry> $entries */ public function __construct(private array $entries) {}
    public function candidates(int $limit = 100, int $offset = 0): array { return array_slice($this->entries, $offset, $limit); }
}

final class FeedProfileRepository implements ProfileActivityRepository
{
    public function __construct(private ProfilePost $post) {}
    public function settings(EntityId $id): ProfileActivitySettings { return new ProfileActivitySettings(); }
    public function saveSettings(EntityId $id, ProfileActivitySettings $settings): void {}
    public function createPost(EntityId $o, EntityId $a, ProfileActivityBody $b, DateTimeImmutable $n): ProfilePost { throw new \LogicException('Not used.'); }
    public function findPost(EntityId $id): ?ProfilePost { return $id->value() === $this->post->id->value() ? $this->post : null; }
    public function posts(EntityId $o, int $l = 50, int $x = 0): array { return []; }
    public function deletePost(EntityId $id, DateTimeImmutable $n): void {}
    public function createComment(EntityId $p, EntityId $a, ProfileActivityBody $b, DateTimeImmutable $n): ProfileComment { throw new \LogicException('Not used.'); }
    public function findComment(EntityId $id): ?ProfileComment { return null; }
    public function comments(EntityId $p, int $l = 100, int $o = 0): array { return []; }
    public function deleteComment(EntityId $id, DateTimeImmutable $n): void {}
    public function setReaction(EntityId $a, EntityId $p, string $k): void {}
    public function removeReaction(EntityId $a, EntityId $p): void {}
    public function reactionSummary(EntityId $p): ReactionSummary { return new ReactionSummary(0, 0, []); }
}

final readonly class FeedSocialRepository implements SocialInteractionRepository
{
    /** @param list<EntityId> $ignored */ public function __construct(private array $ignored) {}
    public function reactionType(string $key): ?ReactionType { return null; }
    public function setReaction(EntityId $a, EntityId $p, string $k): void {}
    public function removeReaction(EntityId $a, EntityId $p): void {}
    public function reactionSummary(EntityId $p): ReactionSummary { return new ReactionSummary(0, 0, []); }
    public function saveBookmark(EntityId $a, EntityId $p, ?string $n): void {}
    public function removeBookmark(EntityId $a, EntityId $p): void {}
    public function bookmarks(EntityId $a, int $l = 50, int $o = 0): array { return []; }
    public function follow(EntityId $a, EntityId $t): void {}
    public function unfollow(EntityId $a, EntityId $t): void {}
    public function ignore(EntityId $a, EntityId $t): void {}
    public function unignore(EntityId $a, EntityId $t): void {}
    public function isFollowing(EntityId $a, EntityId $t): bool { return false; }
    public function isIgnoring(EntityId $a, EntityId $t): bool { return false; }
    public function ignoredUserIds(EntityId $a): array { return $this->ignored; }
}

final class FeedUserRepository implements UserRepository
{
    /** @var array<string,User> */ private array $users = [];
    /** @param list<User> $users */ public function __construct(array $users) { foreach ($users as $u) $this->users[$u->id()->value()] = $u; }
    public function find(EntityId $id): ?User { return $this->users[$id->value()] ?? null; }
    public function findByUsername(Username $u): ?User { return null; }
    public function findByEmail(EmailAddress $e): ?User { return null; }
    public function save(User $u): void { $this->users[$u->id()->value()] = $u; }
    public function history(EntityId $id, int $l = 100, int $o = 0): array { return []; }
}

final readonly class FeedAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $group) {}
    public function find(EntityId $userId): ?UserAccessAssignment { return new UserAccessAssignment($userId, $this->group); }
}

final readonly class FeedPermissionRepository implements PermissionRuleRepository
{
    public function __construct(private EntityId $deniedForum) {}
    public function definition(PermissionKey $key): ?PermissionDefinition { return new PermissionDefinition($key, PermissionValueType::Flag); }
    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($key->value() === 'profile.post.moderate') return [];
        if ($key->value() === 'forum.view' && $nodeId?->value() === $this->deniedForum->value()) return [];
        return [new PermissionRule(PermissionSubjectType::User, $assignment->userId(), PermissionEffect::Allow, $nodeId)];
    }
}
