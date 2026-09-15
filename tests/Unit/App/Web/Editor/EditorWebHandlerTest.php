<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Editor;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Editor\EditorMentionLookupHandler;
use Forwext\App\Web\Editor\EditorPreviewHandler;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserHistoryEntry;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Forum\Editor\BbCodeRenderer;
use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorPreviewService;
use Forwext\Core\Forum\Editor\MentionResolver;
use Forwext\Core\Forum\Editor\MentionTarget;
use Forwext\Core\Forum\Editor\SafeEditorLinkPolicy;
use Forwext\Core\Forum\Editor\SafeLinkEmbedResolver;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class EditorWebHandlerTest extends TestCase
{
    public function testPreviewRequiresAuthenticatedViewer(): void
    {
        $handler = new EditorPreviewHandler($this->preview(), new EditorTestViewerResolver(null));
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/preview',
            parsedBody: ['source' => '[b]hello[/b]'],
        ));

        self::assertSame(401, $response->status());
        self::assertSame('no-store', $response->headers()->first('cache-control'));
    }

    public function testPreviewReturnsSanitizedHtmlMetricsAndViolations(): void
    {
        $actor = $this->id('9');
        $handler = new EditorPreviewHandler($this->preview(), new EditorTestViewerResolver($actor));
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/preview',
            parsedBody: ['source' => '<script>x</script> [b]hello[/b]'],
        ));

        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['valid']);
        self::assertStringNotContainsString('<script>', (string) $payload['html']);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', (string) $payload['html']);
        self::assertStringContainsString('<strong>hello</strong>', (string) $payload['html']);
        self::assertGreaterThan(0, $payload['metrics']['characters']);
    }

    public function testPreviewRejectsOversizedInputBeforeRendering(): void
    {
        $actor = $this->id('9');
        $handler = new EditorPreviewHandler($this->preview(maxBytes: 10), new EditorTestViewerResolver($actor));
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/preview',
            parsedBody: ['source' => str_repeat('a', 11)],
        ));

        self::assertSame(422, $response->status());
        self::assertSame(['error' => 'invalid_source'], json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMentionLookupReturnsStableIdAndBasePathAwarePublicUrlOnly(): void
    {
        $actor = $this->id('9');
        $mentioned = $this->user($this->id('1'), 'Benjamin17');
        $users = new EditorTestUserRepository([$mentioned]);
        $handler = new EditorMentionLookupHandler(
            $users,
            new EditorTestViewerResolver($actor),
            new BasePath('/forum'),
        );
        $response = $handler->handle(new Request(
            HttpMethod::Get,
            '/editor/mention?username=Benjamin17',
            query: ['username' => 'Benjamin17'],
        ));

        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($mentioned->id()->value(), $payload['id']);
        self::assertSame('@Benjamin17', $payload['label']);
        self::assertSame('/forum/members/Benjamin17', $payload['url']);
        self::assertArrayNotHasKey('email', $payload);
    }

    private function preview(int $maxBytes = 100000): EditorPreviewService
    {
        $links = new SafeEditorLinkPolicy();
        return new EditorPreviewService(
            new BbCodeRenderer(
                $links,
                new class implements MentionResolver {
                    public function resolve(EntityId $userId): ?MentionTarget
                    {
                        return null;
                    }
                },
                new SafeLinkEmbedResolver($links),
            ),
            new EditorLimits(maxBytes: $maxBytes),
        );
    }

    private function user(EntityId $id, string $username): User
    {
        return User::create(
            $id,
            Username::fromString($username),
            EmailAddress::fromString(strtolower($username) . '@example.com'),
            UserStatus::Active,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('UTC'),
            new DateTimeImmutable('2026-09-15 20:00:00', new DateTimeZone('UTC')),
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final readonly class EditorTestViewerResolver implements ProfileViewerResolver
{
    public function __construct(private ?EntityId $viewerId)
    {
    }

    public function resolve(Request $request): ?EntityId
    {
        return $this->viewerId;
    }
}

final class EditorTestUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    /** @param list<User> $users */
    public function __construct(array $users)
    {
        foreach ($users as $user) {
            $this->users[$user->id()->value()] = $user;
        }
    }

    public function find(EntityId $id): ?User
    {
        return $this->users[$id->value()] ?? null;
    }

    public function findByUsername(Username $username): ?User
    {
        foreach ($this->users as $user) {
            if (hash_equals($user->username()->key(), $username->key())) {
                return $user;
            }
        }
        return null;
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        foreach ($this->users as $user) {
            if (hash_equals($user->email()->key(), $email->key())) {
                return $user;
            }
        }
        return null;
    }

    public function save(User $user): void
    {
        $this->users[$user->id()->value()] = $user;
    }

    /** @return list<UserHistoryEntry> */
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array
    {
        return [];
    }
}
