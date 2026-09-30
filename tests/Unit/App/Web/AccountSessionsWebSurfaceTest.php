<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Account\AccountSessionsHtml;
use Forwext\Core\Auth\Session\AuthSessionIndexRecord;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class AccountSessionsWebSurfaceTest extends TestCase
{
    public function testFactoryRegistersCsrfProtectedHashedSessionManagement(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Account/AccountSessionsHandler.php');
        $manager = (string) file_get_contents($root . '/core/Auth/Session/AuthSessionManager.php');

        self::assertStringContainsString('new DatabaseAuthSessionIndex($database)', $factory);
        self::assertStringContainsString('index: $authSessionIndex', $factory);
        self::assertStringContainsString("'account.sessions'", $factory);
        self::assertStringContainsString("new PathTemplate('/account/sessions')", $factory);
        self::assertStringContainsString('$accountSessionCsrf', $factory);
        self::assertStringContainsString("'forwext.csrf.account-session.v1'", $factory);

        self::assertStringContainsString("hash('sha256', $sessionId)", $handler);
        self::assertStringContainsString('revokeOthers($actor, $currentHash, $now)', $handler);
        self::assertStringContainsString('revokeForUser($actor, $hash, $now)', $handler);
        self::assertStringContainsString('hash_equals($currentHash, $hash)', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);

        self::assertStringContainsString('$indexed->activeAt($this->clock->now())', $manager);
        self::assertStringContainsString("hash('sha256', $sessionId)", $manager);
        self::assertStringNotContainsString("'session_id' => $sessionId", $manager);
    }

    public function testSessionPageLabelsCurrentSessionWithoutExposingRawToken(): void
    {
        $now = new DateTimeImmutable('2026-09-30 12:30:00', new DateTimeZone('UTC'));
        $currentHash = str_repeat('a', 64);
        $sessions = [
            new AuthSessionIndexRecord(
                $currentHash,
                UserId::fromStored(str_repeat('b', 32)),
                str_repeat('c', 32),
                1,
                $now,
                $now->modify('+2 hours'),
                $now,
            ),
            new AuthSessionIndexRecord(
                str_repeat('d', 64),
                UserId::fromStored(str_repeat('b', 32)),
                str_repeat('e', 32),
                1,
                $now,
                $now->modify('+2 hours'),
                $now,
            ),
        ];

        $html = AccountSessionsHtml::page(
            $sessions,
            $currentHash,
            'csrf-token',
            new BasePath('/community'),
            new DateTimeZone('Europe/Istanbul'),
        );

        self::assertStringContainsString('/community/account/sessions', $html);
        self::assertStringContainsString('Bu oturum', $html);
        self::assertStringContainsString('Diğer 1 oturumu kapat', $html);
        self::assertStringContainsString('value="' . str_repeat('d', 64) . '"', $html);
        self::assertStringNotContainsString('value="' . $currentHash . '"', $html);
        self::assertStringContainsString('/community/logout', $html);
    }
}
