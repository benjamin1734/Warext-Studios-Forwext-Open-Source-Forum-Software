<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class BugReportIntake
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $reportId,
        public string $reproductionSteps,
        public string $expectedResult,
        public string $actualResult,
        public ?string $reportedSourcePath,
        DateTimeImmutable $createdAt,
    ) {
        foreach ([
            'reproduction steps'=>$this->reproductionSteps,
            'expected result'=>$this->expectedResult,
            'actual result'=>$this->actualResult,
        ] as $label=>$value) {
            if (trim($value) === '' || strlen($value) > 10000) {
                throw new InvalidArgumentException('Bug report ' . $label . ' must contain 1-10000 UTF-8 bytes.');
            }
        }

        if ($this->reportedSourcePath !== null) {
            if ($this->reportedSourcePath === ''
                || !str_starts_with($this->reportedSourcePath, '/')
                || strlen($this->reportedSourcePath) > 2048
                || str_contains($this->reportedSourcePath, '?')
                || str_contains($this->reportedSourcePath, '#')
                || preg_match('/[\x00-\x1F\x7F]/', $this->reportedSourcePath) === 1
            ) {
                throw new InvalidArgumentException('Bug report source path is invalid.');
            }
        }

        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }
}
