<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException(
        'Development dependencies are not installed. Run Composer in a development/build environment before executing tests.'
    );
}

require_once $autoload;
