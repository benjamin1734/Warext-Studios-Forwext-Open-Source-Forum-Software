<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

interface Migration
{
    public function id(): MigrationId;

    public function owner(): MigrationOwner;

    public function isIdempotent(): bool;

    public function isTransactional(): bool;

    public function up(MigrationContext $context): void;

    public function verify(MigrationContext $context): MigrationVerification;
}
