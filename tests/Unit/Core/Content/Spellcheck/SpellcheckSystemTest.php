<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Spellcheck;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Spellcheck\SpellcheckAccessDeniedException;
use Forwext\Core\Content\Spellcheck\SpellcheckDictionaryRepository;
use Forwext\Core\Content\Spellcheck\SpellcheckIssue;
use Forwext\Core\Content\Spellcheck\SpellcheckPermissionResolver;
use Forwext\Core\Content\Spellcheck\SpellcheckPipelineProcessor;
use Forwext\Core\Content\Spellcheck\SpellcheckProvider;
use Forwext\Core\Content\Spellcheck\SpellcheckProviderRegistry;
use Forwext\Core\Content\Spellcheck\SpellcheckRequest;
use Forwext\Core\Content\Spellcheck\SpellcheckResult;
use Forwext\Core\Content\Spellcheck\SpellcheckService;
use Forwext\Core\Content\Spellcheck\TurkishSpellcheckProvider;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class SpellcheckSystemTest extends TestCase
{
    public function testTurkishProviderFindsConservativeMisspellingsWithUnicodeOffsets(): void
    {
        $provider = new TurkishSpellcheckProvider();
        $result = $provider->check(new SpellcheckRequest('Şimdi herkez yanlız değil.', 'tr-TR'));

        self::assertSame('turkish.core', $result->providerKey);
        self::assertSame('tr-tr', $result->language);
        self::assertCount(2, $result->issues);
        self::assertSame('herkez', $result->issues[0]->word);
        self::assertSame(['herkes'], $result->issues[0]->suggestions);
        self::assertSame('yanlız', $result->issues[1]->word);
        self::assertSame(['yalnız'], $result->issues[1]->suggestions);

        $characters = preg_split('//u', 'Şimdi herkez yanlız değil.', -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($characters);
        self::assertSame('herkez', implode('', array_slice($characters, $result->issues[0]->start, $result->issues[0]->length)));
        self::assertSame('yanlız', implode('', array_slice($characters, $result->issues[1]->start, $result->issues[1]->length)));
    }

    public function testUserAndSiteDictionaryEntriesSuppressSuggestions(): void
    {
        $actor = $this->id('1');
        $dictionary = new MemorySpellcheckDictionary();
        $dictionary->addSite('tr-tr', 'herkez');
        $dictionary->addUser($actor, 'tr-tr', 'yanlız');
        $service = new SpellcheckService(
            new SpellcheckProviderRegistry([new TurkishSpellcheckProvider()]),
            $dictionary,
            new FixedSpellcheckPermissions(true, true, false),
        );

        $result = $service->check($actor, 'herkez yanlız malesef', 'tr-tr');

        self::assertCount(1, $result->issues);
        self::assertSame('malesef', $result->issues[0]->word);
        self::assertSame(['maalesef'], $result->issues[0]->suggestions);
    }

    public function testDictionaryPermissionsSeparateOwnAndSiteManagement(): void
    {
        $actor = $this->id('1');
        $dictionary = new MemorySpellcheckDictionary();
        $service = new SpellcheckService(
            new SpellcheckProviderRegistry([new TurkishSpellcheckProvider()]),
            $dictionary,
            new FixedSpellcheckPermissions(true, true, false),
            $audit = new SpellcheckRecordingAudit(),
        );

        $service->addUserWord(
            $actor,
            'tr-tr',
            'Forwext',
            AuditRequestId::fromString('req-spellcheck'),
        );
        self::assertSame(['Forwext'], $dictionary->userWords($actor, 'tr-tr'));
        self::assertCount(1, $audit->events);
        self::assertSame('content.spellcheck.dictionary.user.add', $audit->events[0]->action->value());
        self::assertSame('req-spellcheck', $audit->events[0]->requestId->value());
        self::assertNotSame('Forwext', $audit->events[0]->targetId);

        $this->expectException(SpellcheckAccessDeniedException::class);
        $service->addSiteWord($actor, 'tr-tr', 'Vianore');
    }

    public function testProviderRegistryAllowsAdditionalLanguages(): void
    {
        $english = new class implements SpellcheckProvider {
            public function key(): string { return 'test.english'; }
            public function supports(string $language): bool { return $language === 'en-us'; }
            public function check(SpellcheckRequest $request, array $ignoredWords = []): SpellcheckResult
            {
                return new SpellcheckResult(
                    $this->key(),
                    $request->language,
                    [new SpellcheckIssue('teh', 0, 3, ['the'])],
                );
            }
        };
        $registry = new SpellcheckProviderRegistry([new TurkishSpellcheckProvider(), $english]);

        self::assertSame('test.english', $registry->resolve('en-US')->key());
        self::assertSame('turkish.core', $registry->resolve('tr-TR')->key());
    }

    public function testPipelineRecordsAdvisoryIssueCountWithoutChangingContent(): void
    {
        $actor = $this->id('1');
        $service = new SpellcheckService(
            new SpellcheckProviderRegistry([new TurkishSpellcheckProvider()]),
            new MemorySpellcheckDictionary(),
            new FixedSpellcheckPermissions(true, true, false),
        );
        $processor = new SpellcheckPipelineProcessor($service);
        $context = new ContentPipelineContext($actor, 'forum.post', 'herkez burada', 100000);

        $processed = $processor->process(
            $context,
            new DateTimeImmutable('2026-09-19 08:20:00', new DateTimeZone('UTC')),
        );

        self::assertSame('herkez burada', $processed->text);
        self::assertSame(true, $processed->attributes['spellcheck.enabled'] ?? null);
        self::assertSame('turkish.core', $processed->attributes['spellcheck.provider'] ?? null);
        self::assertSame(1, $processed->attributes['spellcheck.issue_count'] ?? null);
    }

    public function testPipelineGracefullySkipsWhenUserCannotUseSpellcheck(): void
    {
        $actor = $this->id('1');
        $service = new SpellcheckService(
            new SpellcheckProviderRegistry([new TurkishSpellcheckProvider()]),
            new MemorySpellcheckDictionary(),
            new FixedSpellcheckPermissions(false, false, false),
        );
        $processed = (new SpellcheckPipelineProcessor($service))->process(
            new ContentPipelineContext($actor, 'forum.post', 'herkez burada', 100000),
            new DateTimeImmutable('2026-09-19 08:20:00', new DateTimeZone('UTC')),
        );

        self::assertSame(false, $processed->attributes['spellcheck.enabled'] ?? null);
        self::assertSame(0, $processed->attributes['spellcheck.issue_count'] ?? null);
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final readonly class FixedSpellcheckPermissions implements SpellcheckPermissionResolver
{
    public function __construct(
        private bool $use,
        private bool $own,
        private bool $site,
    ) {
    }

    public function canUse(EntityId $userId): bool { return $this->use; }
    public function canManageOwnDictionary(EntityId $userId): bool { return $this->own; }
    public function canManageSiteDictionary(EntityId $userId): bool { return $this->site; }
}

final class MemorySpellcheckDictionary implements SpellcheckDictionaryRepository
{
    /** @var array<string,string> */
    private array $site = [];
    /** @var array<string,array<string,string>> */
    private array $users = [];

    public function siteWords(string $language): array
    {
        return array_values($this->site);
    }

    public function userWords(EntityId $userId, string $language): array
    {
        return array_values($this->users[$userId->value()] ?? []);
    }

    public function addSite(string $language, string $word, ?EntityId $actorUserId = null): void
    {
        $this->site[strtolower($word)] = $word;
    }

    public function removeSite(string $language, string $word): bool
    {
        $key = strtolower($word);
        if (!isset($this->site[$key])) return false;
        unset($this->site[$key]);
        return true;
    }

    public function addUser(EntityId $userId, string $language, string $word): void
    {
        $this->users[$userId->value()][strtolower($word)] = $word;
    }

    public function removeUser(EntityId $userId, string $language, string $word): bool
    {
        $key = strtolower($word);
        if (!isset($this->users[$userId->value()][$key])) return false;
        unset($this->users[$userId->value()][$key]);
        return true;
    }
}


final class SpellcheckRecordingAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->events[] = $event;
        return $result;
    }
}
