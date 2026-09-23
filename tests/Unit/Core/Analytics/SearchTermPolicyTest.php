<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use Forwext\Core\Analytics\Engagement\SearchTermPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchTermPolicyTest extends TestCase
{
    public function testSafeTermsAreNormalizedAndBucketed():void
    {
        $policy=new SearchTermPolicy();
        self::assertSame('minecraft sunucu',$policy->safeDisplay('  MINECRAFT   sunucu  '));
        self::assertSame('güvenlik',$policy->safeDisplay('GÜVENLİK'));
        self::assertSame('safe',$policy->queryClass('forum yazılımı'));
        self::assertSame('zero',$policy->resultBucket(0));
        self::assertSame('one_five',$policy->resultBucket(5));
        self::assertSame('six_twenty',$policy->resultBucket(20));
        self::assertSame('twenty_plus',$policy->resultBucket(21));
    }

    #[DataProvider('sensitiveQueries')]
    public function testSensitiveOrIdentifierLikeQueriesAreRedacted(string $query):void
    {
        $policy=new SearchTermPolicy();
        self::assertNull($policy->safeDisplay($query));
        self::assertSame('redacted',$policy->queryClass($query));
    }

    /** @return iterable<string,array{0:string}> */
    public static function sensitiveQueries():iterable
    {
        yield 'email'=>['user@example.com'];
        yield 'url'=>['https://example.com/private'];
        yield 'www'=>['www.example.com'];
        yield 'ip'=>['192.168.1.44'];
        yield 'phone'=>['+90 555 123 45 67'];
        yield 'long digits'=>['siparis 123456789'];
    }
}
