<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class BugReportDetails
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $reportId,
        public string $reproductionSteps,
        public string $expectedResult,
        public string $actualResult,
        public ?string $sourcePath,
        DateTimeImmutable $createdAt,
    ) {
        self::assertRequiredText($this->reproductionSteps, 10_000, 'Bug reproduction steps');
        self::assertRequiredText($this->expectedResult, 5_000, 'Bug expected result');
        self::assertRequiredText($this->actualResult, 5_000, 'Bug actual result');

        if ($this->sourcePath !== null) {
            if (
                $this->sourcePath === ''
                || strlen($this->sourcePath) > 2_048
                || !str_starts_with($this->sourcePath, '/')
                || str_starts_with($this->sourcePath, '//')
                || str_contains($this->sourcePath, '?')
                || str_contains($this->sourcePath, '#')
                || preg_match('/[\x00-\x1F\x7F]/', $this->sourcePath) === 1
            ) {
                throw new InvalidArgumentException('Bug source path is invalid.');
            }
        }

        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    private static function assertRequiredText(string $value, int $maxBytes, string $label): void
    {
        if (trim($value) === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException($label . ' is outside the supported length.');
        }
    }
}
