<?php

declare(strict_types=1);

namespace Forwext\Core\Config;

enum Environment: string
{
    case Production = 'production';
    case Development = 'development';
    case Testing = 'test';
    case Install = 'install';
    case Maintenance = 'maintenance';

    public static function parse(string $value): self
    {
        return match (strtolower(trim($value))) {
            'prod', 'production' => self::Production,
            'dev', 'development' => self::Development,
            'test', 'testing' => self::Testing,
            'install', 'installer' => self::Install,
            'maintenance' => self::Maintenance,
            default => throw new ConfigException(sprintf('Unknown application environment "%s".', $value)),
        };
    }
}
