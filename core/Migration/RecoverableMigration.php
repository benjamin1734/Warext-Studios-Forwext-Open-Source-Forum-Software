<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use Throwable;

interface RecoverableMigration extends Migration
{
    public function recover(MigrationContext $context, Throwable $failure): void;
}
