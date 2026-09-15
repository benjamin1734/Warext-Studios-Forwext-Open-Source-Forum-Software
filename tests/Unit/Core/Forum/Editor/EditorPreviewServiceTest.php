<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Editor;

use Forwext\Core\Forum\Editor\BbCodeRenderer;
use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorPreviewService;
use Forwext\Core\Forum\Editor\MentionResolver;
use Forwext\Core\Forum\Editor\MentionTarget;
use Forwext\Core\Forum\Editor\SafeEditorLinkPolicy;
use Forwext\Core\Forum\Editor\SafeLinkEmbedResolver;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EditorPreviewServiceTest extends TestCase
{
    public function testPreviewReturnsSharedRendererOutputAndAssessment(): void
    {
        $service = $this->service(new EditorLimits(minCharacters: 5, maxCharacters: 100, maxBytes: 1000));
        $preview = $service->preview('[b]hello[/b]');

        self::assertTrue($preview->assessment->isValid());
        self::assertStringContainsString('<strong>hello</strong>', $preview->html);
        self::assertSame($preview->assessment->metrics->characters, $preview->toArray()['metrics']['characters']);
    }

    public function testPreviewRejectsControlCharactersAndOversizedSources(): void
    {
        $service = $this->service(new EditorLimits(maxBytes: 10));

        foreach (["ok\x01bad", str_repeat('a', 11)] as $invalid) {
            try {
                $service->preview($invalid);
                self::fail('Invalid preview source must fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function service(EditorLimits $limits): EditorPreviewService
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
            $limits,
        );
    }
}
