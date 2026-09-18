<?php
declare(strict_types=1);
namespace Forwext\Core\Faq\SupportBridge;
use Forwext\Core\Faq\FaqArticleView;
final readonly class FaqSupportRecommendation {
    /** @param list<string> $reasons */
    public function __construct(public FaqArticleView $view, public int $score, public array $reasons) {}
}
