<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\User;

use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\UserCustomFieldType;
use Forwext\Core\Domain\User\UserCustomFieldValue;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserTimezone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserValueObjectTest extends TestCase
{
    public function testAsciiUsernameHasStableCaseInsensitiveKey(): void
    {
        $username = Username::fromString('Benjamin_17');

        self::assertSame('Benjamin_17', $username->display());
        self::assertSame('benjamin_17', $username->key());
    }

    public function testUnicodeUsernameFailsClosedWithoutNormalizationCapabilities(): void
    {
        if (class_exists('Normalizer') && function_exists('mb_convert_case') && defined('MB_CASE_FOLD')) {
            $username = Username::fromString('Batın17');
            self::assertNotSame('', $username->key());
            return;
        }

        $this->expectException(InvalidArgumentException::class);
        Username::fromString('Batın17');
    }

    public function testEmailIsCanonicalCaseInsensitiveIdentity(): void
    {
        $email = EmailAddress::fromString('  User.Name@EXAMPLE.COM  ');

        self::assertSame('user.name@example.com', $email->value());
        self::assertSame($email->value(), $email->key());
    }

    public function testLocaleAndTimezoneAreCanonicalAndNamed(): void
    {
        self::assertSame('tr-TR', UserLocale::fromString('TR_tr')->value());
        self::assertSame('Europe/Istanbul', UserTimezone::fromString('Europe/Istanbul')->value());

        $this->expectException(InvalidArgumentException::class);
        UserTimezone::fromString('+03:00');
    }

    public function testCustomFieldValuesPreserveTheirDeclaredTypes(): void
    {
        $string = UserCustomFieldValue::string('hello');
        $integer = UserCustomFieldValue::integer(42);
        $boolean = UserCustomFieldValue::boolean(true);
        $json = UserCustomFieldValue::json(['a' => 1]);

        self::assertSame(UserCustomFieldType::String, $string->type);
        self::assertSame('hello', $string->decoded());
        self::assertSame(42, $integer->decoded());
        self::assertTrue($boolean->decoded());
        self::assertSame(['a' => 1], $json->decoded());
    }
}
