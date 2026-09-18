<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Moderation\Abuse;

use DateTimeImmutable;
use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Moderation\Abuse\AbuseAction;
use Forwext\Core\Moderation\Abuse\AbuseContentContext;
use Forwext\Core\Moderation\Abuse\AbuseContext;
use Forwext\Core\Moderation\Abuse\AbuseDecision;
use Forwext\Core\Moderation\Abuse\AbuseEngine;
use Forwext\Core\Moderation\Abuse\AbuseEvent;
use Forwext\Core\Moderation\Abuse\AbuseEventType;
use Forwext\Core\Moderation\Abuse\AbuseRepository;
use Forwext\Core\Moderation\Abuse\AbuseRequestContextFactory;
use Forwext\Core\Moderation\Abuse\AbuseRule;
use Forwext\Core\Moderation\Abuse\AbuseSignal;
use Forwext\Core\Security\Secret\SecretStore;
use PHPUnit\Framework\TestCase;

final class AbuseSystemTest extends TestCase
{
    public function testRuleEvaluationUsesThresholdsAndMostSevereMatchedAction(): void
    {
        $repository = new AbuseMemoryRepository([
            new AbuseRule(
                'post.user.review',
                'User review',
                AbuseEventType::Post,
                AbuseSignal::User,
                1,
                60,
                AbuseAction::Review,
                true,
                10,
            ),
            new AbuseRule(
                'post.ip.reject',
                'IP reject',
                AbuseEventType::Post,
                AbuseSignal::Ip,
                2,
                60,
                AbuseAction::Reject,
                true,
                20,
            ),
        ]);
        $engine = new AbuseEngine($repository);
        $context = new AbuseContext(
            AbuseEventType::Post,
            EntityId::fromString(str_repeat('a', 32)),
            null,
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
        );
        $at = new DateTimeImmutable('2026-09-18T11:00:00+00:00');

        self::assertSame(AbuseAction::Allow, $engine->evaluate($context, $at)->action);

        $review = $engine->evaluate($context, $at);
        self::assertSame(AbuseAction::Review, $review->action);
        self::assertSame(['post.user.review'], $review->matchedKeys);

        $reject = $engine->evaluate($context, $at);
        self::assertSame(AbuseAction::Reject, $reject->action);
        self::assertSame(['post.ip.reject', 'post.user.review'], $reject->matchedKeys);
    }

    public function testContentContextPreservesPrivacySafeRequestSignalsAndOwnsContentFingerprint(): void
    {
        $actor = EntityId::fromString(str_repeat('a', 32));
        $request = new AbuseContext(
            AbuseEventType::Post,
            $actor,
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
            str_repeat('e', 64),
        );

        $context = AbuseContentContext::post($actor, "  SAME\n  BODY  ", $request);

        self::assertSame(str_repeat('b', 64), $context->identityFingerprint);
        self::assertSame(str_repeat('c', 64), $context->ipFingerprint);
        self::assertSame(str_repeat('d', 64), $context->deviceFingerprint);
        self::assertSame(hash('sha256', 'post:same body'), $context->contentFingerprint);
        self::assertNotSame(str_repeat('e', 64), $context->contentFingerprint);
    }

    public function testRequestContextFactoryHashesIpAndUserAgentWithoutPersistingRawValues(): void
    {
        $secret = str_repeat('s', 40);
        $factory = new AbuseRequestContextFactory(new AuthenticationFingerprint(
            new AbuseSecretStore(['authentication.fingerprint_key' => $secret]),
        ));
        $actor = EntityId::fromString(str_repeat('a', 32));

        $context = $factory->post($actor, '203.0.113.55', 'Forwext Browser/1.0');

        self::assertSame(
            hash_hmac('sha256', 'ip:' . bin2hex((string) inet_pton('203.0.113.55')), $secret),
            $context->ipFingerprint,
        );
        self::assertSame(
            hash_hmac('sha256', 'ua:Forwext Browser/1.0', $secret),
            $context->deviceFingerprint,
        );
        self::assertSame($actor->value(), $context->actorUserId?->value());
    }

    public function testOnlyReviewOrRejectDecisionsArePersistedAsEvents(): void
    {
        $repository = new AbuseMemoryRepository();
        $engine = new AbuseEngine($repository);
        $context = new AbuseContext(
            AbuseEventType::Registration,
            null,
            str_repeat('a', 64),
            str_repeat('b', 64),
        );
        $at = new DateTimeImmutable('2026-09-18T11:01:00+00:00');

        self::assertNull($engine->record($context, AbuseDecision::allow(), null, null, $at));
        self::assertSame([], $repository->events);

        $event = $engine->record(
            $context,
            new AbuseDecision(AbuseAction::Review, ['registration.ip.review']),
            'user.account',
            EntityId::fromString(str_repeat('c', 32)),
            $at,
        );

        self::assertNotNull($event);
        self::assertCount(1, $repository->events);
        self::assertSame('user.account', $repository->events[0]->targetType);
        self::assertSame(['registration.ip.review'], $repository->events[0]->matchedRuleKeys);
    }
}

final class AbuseMemoryRepository implements AbuseRepository
{
    /** @var list<AbuseRule> */
    private array $rules;
    /** @var array<string,int> */
    private array $hits = [];
    /** @var list<AbuseEvent> */
    public array $events = [];

    /** @param list<AbuseRule> $rules */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    public function rules(AbuseEventType $eventType): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (AbuseRule $rule): bool => $rule->eventType === $eventType && $rule->active,
        ));
    }

    public function allRules(): array
    {
        return $this->rules;
    }

    public function rule(string $key): ?AbuseRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->key === $key) return $rule;
        }
        return null;
    }

    public function saveRule(AbuseRule $rule, DateTimeImmutable $at): void
    {
        foreach ($this->rules as $index => $stored) {
            if ($stored->key === $rule->key) {
                $this->rules[$index] = $rule;
                return;
            }
        }
        $this->rules[] = $rule;
    }

    public function consume(AbuseRule $rule, string $fingerprint, DateTimeImmutable $at): int
    {
        $key = $rule->key . ':' . $fingerprint . ':' . intdiv($at->getTimestamp(), $rule->windowSeconds);
        return $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;
    }

    public function insertEvent(AbuseEvent $event): void
    {
        $this->events[] = $event;
    }

    public function event(EntityId $eventId): ?AbuseEvent
    {
        foreach ($this->events as $event) {
            if ($event->eventId->equals($eventId)) return $event;
        }
        return null;
    }

    public function unresolved(int $limit = 100): array
    {
        return array_slice(array_values(array_filter(
            $this->events,
            static fn (AbuseEvent $event): bool => !$event->isResolved(),
        )), 0, $limit);
    }

    public function unresolvedCount(): int
    {
        return count($this->unresolved(500));
    }

    public function resolve(EntityId $eventId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void
    {
        throw new \LogicException('Not used by abuse engine unit tests.');
    }
}


final class AbuseSecretStore implements SecretStore
{
    /** @param array<string,string> $values */
    public function __construct(private array $values)
    {
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, string $value): void
    {
        $this->values[$name] = $value;
    }

    public function delete(string $name): bool
    {
        if (!isset($this->values[$name])) {
            return false;
        }
        unset($this->values[$name]);
        return true;
    }

    public function all(): array
    {
        return $this->values;
    }
}
