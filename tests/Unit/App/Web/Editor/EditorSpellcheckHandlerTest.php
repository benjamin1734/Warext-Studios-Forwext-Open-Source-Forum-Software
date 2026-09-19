<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Editor;

use Forwext\App\Web\Editor\EditorSpellcheckHandler;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Content\Spellcheck\SpellcheckDictionaryRepository;
use Forwext\Core\Content\Spellcheck\SpellcheckPermissionResolver;
use Forwext\Core\Content\Spellcheck\SpellcheckProviderRegistry;
use Forwext\Core\Content\Spellcheck\SpellcheckService;
use Forwext\Core\Content\Spellcheck\TurkishSpellcheckProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class EditorSpellcheckHandlerTest extends TestCase
{
    public function testSpellcheckRequiresAuthentication(): void
    {
        $handler = new EditorSpellcheckHandler(
            $this->service(true),
            new SpellcheckHandlerViewer(null),
        );
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/spellcheck',
            parsedBody: ['source'=>'herkez','language'=>'tr-tr'],
        ));

        self::assertSame(401, $response->status());
        self::assertSame('no-store', $response->headers()->first('cache-control'));
    }

    public function testSpellcheckReturnsTurkishSuggestionsWithoutChangingSource(): void
    {
        $actor = EntityId::fromString(str_repeat('1', 32));
        $handler = new EditorSpellcheckHandler(
            $this->service(true),
            new SpellcheckHandlerViewer($actor),
        );
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/spellcheck',
            parsedBody: ['source'=>'Herkez burda yanlız.','language'=>'tr-TR'],
        ));

        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('turkish.core', $payload['provider']);
        self::assertSame('tr-tr', $payload['language']);
        self::assertSame(2, $payload['issue_count']);
        self::assertSame('Herkez', $payload['issues'][0]['word']);
        self::assertSame(['Herkes'], $payload['issues'][0]['suggestions']);
        self::assertSame('yanlız', $payload['issues'][1]['word']);
    }

    public function testSpellcheckPermissionIsEnforcedServerSide(): void
    {
        $actor = EntityId::fromString(str_repeat('1', 32));
        $handler = new EditorSpellcheckHandler(
            $this->service(false),
            new SpellcheckHandlerViewer($actor),
        );
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/spellcheck',
            parsedBody: ['source'=>'herkez','language'=>'tr-tr'],
        ));

        self::assertSame(403, $response->status());
        self::assertSame(['error'=>'permission_denied'], json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function service(bool $allowed): SpellcheckService
    {
        return new SpellcheckService(
            new SpellcheckProviderRegistry([new TurkishSpellcheckProvider()]),
            new EmptySpellcheckDictionary(),
            new HandlerSpellcheckPermissions($allowed),
        );
    }
}

final readonly class SpellcheckHandlerViewer implements ProfileViewerResolver
{
    public function __construct(private ?EntityId $viewerId)
    {
    }

    public function resolve(Request $request): ?EntityId
    {
        return $this->viewerId;
    }
}

final readonly class HandlerSpellcheckPermissions implements SpellcheckPermissionResolver
{
    public function __construct(private bool $allowed)
    {
    }

    public function canUse(EntityId $userId): bool { return $this->allowed; }
    public function canManageOwnDictionary(EntityId $userId): bool { return $this->allowed; }
    public function canManageSiteDictionary(EntityId $userId): bool { return false; }
}

final readonly class EmptySpellcheckDictionary implements SpellcheckDictionaryRepository
{
    public function siteWords(string $language): array { return []; }
    public function userWords(EntityId $userId, string $language): array { return []; }
    public function addSite(string $language, string $word, ?EntityId $actorUserId = null): void {}
    public function removeSite(string $language, string $word): bool { return false; }
    public function addUser(EntityId $userId, string $language, string $word): void {}
    public function removeUser(EntityId $userId, string $language, string $word): bool { return false; }
}
