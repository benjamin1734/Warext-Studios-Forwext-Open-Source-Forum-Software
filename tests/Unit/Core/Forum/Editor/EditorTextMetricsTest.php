<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Editor;

use Forwext\Core\Forum\Editor\EditorAssessment;
use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorTextMetrics;
use PHPUnit\Framework\TestCase;

final class EditorTextMetricsTest extends TestCase
{
    public function testUnicodeCharactersWordsAndBytesAreMeasuredWithoutMbstring(): void
    {
        $metrics = EditorTextMetrics::measure('Merhaba dünya 👋');

        self::assertSame(15, $metrics->characters);
        self::assertSame(2, $metrics->words);
        self::assertGreaterThan($metrics->characters, $metrics->bytes);
    }

    public function testDefaultLimitsRemainCompatibleWithPostBodySemantics(): void
    {
        $limits = new EditorLimits();
        $assessment = EditorAssessment::assess('👋', $limits);

        self::assertTrue($assessment->isValid());
        self::assertSame(1, $assessment->metrics->characters);
        self::assertSame(0, $assessment->metrics->words);
        self::assertSame([], $assessment->violations);
    }

    public function testMinAndMaxViolationsAreDeterministic(): void
    {
        $limits = new EditorLimits(
            minCharacters: 3,
            maxCharacters: 8,
            maxBytes: 12,
            minWords: 2,
            maxWords: 3,
        );

        $tooShort = EditorAssessment::assess('Hi', $limits);
        self::assertSame(['characters.minimum', 'words.minimum'], $tooShort->violations);

        $tooLong = EditorAssessment::assess('one two three four', $limits);
        self::assertContains('characters.maximum', $tooLong->violations);
        self::assertContains('bytes.maximum', $tooLong->violations);
        self::assertContains('words.maximum', $tooLong->violations);
    }
}
