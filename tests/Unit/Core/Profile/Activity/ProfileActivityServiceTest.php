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
use Forwext\Core\Profile\Activity\ProfileActivityBody;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityModerationState;
use Forwext\Core\Profile\Activity\ProfileActivityRepository;
use Forwext\Core\Profile\Activity\ProfileActivityScope;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use Forwext\Core\Profile\Activity\ProfileComment;
use Forwext\Core\Profile\Activity\ProfilePost;
use Forwext\Core\Profile\Activity\ProfilePostId;
use Forwext\Core\Social\Interaction\ReactionSummary;
use Forwext\Core\Social\Interaction\ReactionType;
use Forwext\Core\Social\Interaction\SocialInteractionRepository;
use PHPUnit\Framework\TestCase;

final class ProfileActivityServiceTest extends TestCase
{
    public function testFollowerOnlyProfileAllowsFollowerAndRejectsStranger(): void
    {
        $owner = $this->id('1'); $follower = $this->id('2'); $stranger = $this->id('3');
        $profiles = new ProfileActivityMemoryRepository();
        $profiles->settingsByUser[$owner->value()] = new ProfileActivitySettings(ProfileActivityScope::Followers, ProfileActivityScope::Followers);
        $social = new ProfileSocialMemoryRepository();
        $social->following[$follower->value()][$owner->value()] = true;
        $service = $this->service($profiles, $social, [$this->user($owner, 'Owner'), $this->user($follower, 'Follower'), $this->user($stranger, 'Stranger')]);

        self::assertTrue($service->canViewProfile($follower, $owner));
        self::assertFalse($service->canViewProfile($stranger, $owner));
        self::assertSame($follower->value(), $service->createPost($follower, $owner, ProfileActivityBody::fromString('Hello'), $this->now())->authorUserId?->value());

        $this->expectException(ProfileActivityException::class);
        $service->createPost($stranger, $owner, ProfileActivityBody::fromString('No'), $this->now());
    }

    public function testOwnerIgnoreBlocksVisitorWrite(): void
    {
        $owner = $this->id('1'); $visitor = $this->id('2');
        $profiles = new ProfileActivityMemoryRepository();
        $social = new ProfileSocialMemoryRepository();
        $social->ignored[$owner->value()][$visitor->value()] = true;
        $service = $this->service($profiles, $social, [$this->user($owner, 'Owner'), $this->user($visitor, 'Visitor')]);

        $this->expectException(ProfileActivityException::class);
        $service->createPost($visitor, $owner, ProfileActivityBody::fromString('Blocked'), $this->now());
    }

    public function testProfileOwnerCanDeleteVisitorPost(): void
    {
        $owner = $this->id('1'); $visitor = $this->id('2');
        $profiles = new ProfileActivityMemoryRepository();
        $service = $this->service($profiles, new ProfileSocialMemoryRepository(), [$this->user($owner, 'Owner'), $this->user($visitor, 'Visitor')]);
        $post = $service->createPost($visitor, $owner, ProfileActivityBody::fromString('Visitor post'), $this->now());

        $service->deletePost($owner, $post->id, $this->now());
        self::assertTrue($profiles->postsById[$post->id->value()]->isDeleted());
    }

    public function testOwnProfilePostReactionIsRejected(): void
    {
        $actor = $this->id('1');
        $profiles = new ProfileActivityMemoryRepository();
        $service = $this->service($profiles, new ProfileSocialMemoryRepository(), [$this->user($actor, 'Actor')]);
        $post = $service->createPost($actor, $actor, ProfileActivityBody::fromString('Own post'), $this->now());

        $this->expectException(ProfileActivityException::class);
        $service->react($actor, $post->id, 'like');
    }

    /** @param list<User> $users */
    private function service(ProfileActivityMemoryRepository $profiles, ProfileSocialMemoryRepository $social, array $users): ProfileActivityService
    {
        return new ProfileActivityService($profiles, $social, new ProfileUserMemoryRepository($users), $this->authorizer());
    }

    private function authorizer(): PermissionAuthorizer
    {
        return new PermissionAuthorizer(new PermissionEngine(new ProfileNormalUserPermissionRepository()), new ProfileAssignmentProvider($this->id('a')));
    }

    private function user(EntityId $id, string $username): User
    {
        return User::create($id, Username::fromString($username), EmailAddress::fromString(strtolower($username) . '@example.com'), UserStatus::Active, UserLocale::fromString('tr-TR'), UserTimezone::fromString('UTC'), $this->now());
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('2026-09-16 21:10:00', new DateTimeZone('UTC')); }
    private function id(string $seed): EntityId { return EntityId::fromString(str_repeat($seed, 32)); }
}

final class ProfileActivityMemoryRepository implements ProfileActivityRepository
{
    /** @var array<string,ProfileActivitySettings> */ public array $settingsByUser = [];
    /** @var array<string,ProfilePost> */ public array $postsById = [];
    /** @var array<string,ProfileComment> */ public array $commentsById = [];
    public function settings(EntityId $id): ProfileActivitySettings { return $this->settingsByUser[$id->value()] ?? new ProfileActivitySettings(); }
    public function saveSettings(EntityId $id, ProfileActivitySettings $settings): void { $this->settingsByUser[$id->value()] = $settings; }
    public function createPost(EntityId $owner, EntityId $author, ProfileActivityBody $body, DateTimeImmutable $now): ProfilePost { $id = ProfilePostId::generate(); return $this->postsById[$id->value()] = new ProfilePost($id, $owner, $author, $body, ProfileActivityModerationState::Visible, null, $now, $now); }
    public function findPost(EntityId $id): ?ProfilePost { return $this->postsById[$id->value()] ?? null; }
    public function posts(EntityId $owner, int $limit = 50, int $offset = 0): array { return array_values(array_filter($this->postsById, static fn (ProfilePost $p): bool => $p->profileOwnerUserId->value() === $owner->value() && !$p->isDeleted())); }
    public function deletePost(EntityId $id, DateTimeImmutable $now): void { $p = $this->postsById[$id->value()]; $this->postsById[$id->value()] = new ProfilePost($p->id, $p->profileOwnerUserId, $p->authorUserId, $p->body, $p->moderationState, $now, $p->createdAt, $now); }
    public function createComment(EntityId $postId, EntityId $author, ProfileActivityBody $body, DateTimeImmutable $now): ProfileComment { throw new \LogicException('Not used.'); }
    public function findComment(EntityId $id): ?ProfileComment { return $this->commentsById[$id->value()] ?? null; }
    public function comments(EntityId $postId, int $limit = 100, int $offset = 0): array { return []; }
    public function deleteComment(EntityId $id, DateTimeImmutable $now): void {}
    public function setReaction(EntityId $actor, EntityId $post, string $key): void {}
    public function removeReaction(EntityId $actor, EntityId $post): void {}
    public function reactionSummary(EntityId $post): ReactionSummary { return new ReactionSummary(0, 0, []); }
}

final class ProfileSocialMemoryRepository implements SocialInteractionRepository
{
    /** @var array<string,array<string,bool>> */ public array $following = [];
    /** @var array<string,array<string,bool>> */ public array $ignored = [];
    public function reactionType(string $key): ?ReactionType { return $key === 'like' ? new ReactionType('like', 'Like', 1) : null; }
    public function setReaction(EntityId $a, EntityId $p, string $k): void {}
    public function removeReaction(EntityId $a, EntityId $p): void {}
    public function reactionSummary(EntityId $p): ReactionSummary { return new ReactionSummary(0, 0, []); }
    public function saveBookmark(EntityId $a, EntityId $p, ?string $n): void {}
    public function removeBookmark(EntityId $a, EntityId $p): void {}
    public function bookmarks(EntityId $a, int $l = 50, int $o = 0): array { return []; }
    public function follow(EntityId $a, EntityId $t): void { $this->following[$a->value()][$t->value()] = true; }
    public function unfollow(EntityId $a, EntityId $t): void { unset($this->following[$a->value()][$t->value()]); }
    public function ignore(EntityId $a, EntityId $t): void { $this->ignored[$a->value()][$t->value()] = true; }
    public function unignore(EntityId $a, EntityId $t): void { unset($this->ignored[$a->value()][$t->value()]); }
    public function isFollowing(EntityId $a, EntityId $t): bool { return $this->following[$a->value()][$t->value()] ?? false; }
    public function isIgnoring(EntityId $a, EntityId $t): bool { return $this->ignored[$a->value()][$t->value()] ?? false; }
    public function ignoredUserIds(EntityId $a): array { return []; }
}

final class ProfileUserMemoryRepository implements UserRepository
{
    /** @var array<string,User> */ private array $users = [];
    /** @param list<User> $users */ public function __construct(array $users) { foreach ($users as $u) $this->users[$u->id()->value()] = $u; }
    public function find(EntityId $id): ?User { return $this->users[$id->value()] ?? null; }
    public function findByUsername(Username $username): ?User { return null; }
    public function findByEmail(EmailAddress $email): ?User { return null; }
    public function save(User $user): void { $this->users[$user->id()->value()] = $user; }
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array { return []; }
}

final readonly class ProfileAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $group) {}
    public function find(EntityId $userId): ?UserAccessAssignment { return new UserAccessAssignment($userId, $this->group); }
}

final class ProfileNormalUserPermissionRepository implements PermissionRuleRepository
{
    public function definition(PermissionKey $key): ?PermissionDefinition { return new PermissionDefinition($key, PermissionValueType::Flag); }
    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($key->value() === 'profile.post.moderate') return [];
        return [new PermissionRule(PermissionSubjectType::User, $assignment->userId(), PermissionEffect::Allow, $nodeId)];
    }
}
