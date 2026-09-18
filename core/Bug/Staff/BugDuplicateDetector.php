<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use Forwext\Core\Bug\Report\BugReport;

final readonly class BugDuplicateDetector
{
    public function __construct(
        private float $minimumScore = 0.55,
        private int $maxSuggestions = 5,
    ) {
    }

    /**
     * @param list<BugReport> $candidates
     * @return list<BugDuplicateSuggestion>
     */
    public function suggest(BugReport $source, array $candidates): array
    {
        $suggestions = [];
        foreach ($candidates as $candidate) {
            if ($candidate->reportId->equals($source->reportId)) {
                continue;
            }
            $score = $this->score($source, $candidate);
            if ($score < $this->minimumScore) {
                continue;
            }
            $suggestions[] = new BugDuplicateSuggestion($candidate, $score);
        }

        usort(
            $suggestions,
            static fn (BugDuplicateSuggestion $left, BugDuplicateSuggestion $right): int =>
                $right->score <=> $left->score
                ?: strcmp($left->report->reportId->value(), $right->report->reportId->value()),
        );

        return array_slice($suggestions, 0, max(1, $this->maxSuggestions));
    }

    public function score(BugReport $left, BugReport $right): float
    {
        $title = $this->tokenSimilarity($left->title, $right->title);
        $summary = $this->tokenSimilarity($left->summary, $right->summary);
        $categoryBoost = $left->categoryKey === $right->categoryKey ? 0.05 : 0.0;
        return min(1.0, ($title * 0.65) + ($summary * 0.30) + $categoryBoost);
    }

    private function tokenSimilarity(string $left, string $right): float
    {
        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $leftMap = array_fill_keys($leftTokens, true);
        $rightMap = array_fill_keys($rightTokens, true);
        $intersection = count(array_intersect_key($leftMap, $rightMap));
        $union = count($leftMap + $rightMap);

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) < 2) {
                continue;
            }
            $tokens[$part] = true;
            if (count($tokens) >= 128) {
                break;
            }
        }
        return array_keys($tokens);
    }
}
