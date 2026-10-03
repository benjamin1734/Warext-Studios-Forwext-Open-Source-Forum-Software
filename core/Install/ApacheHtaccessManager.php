<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use RuntimeException;

final readonly class ApacheHtaccessManager
{
    private const BEGIN_MARKER = '# BEGIN Forwext';
    private const END_MARKER = '# END Forwext';

    public function __construct(private string $path)
    {
    }

    public function ensurePublicRouting(): void
    {
        $this->ensureRouting(self::publicRoutingBlock(), false);
    }

    public function ensureRootRouting(): void
    {
        $this->ensureRouting(self::rootRoutingBlock(), true);
    }

    private function ensureRouting(string $block, bool $rootLayout): void
    {
        if (is_link($this->path)) {
            throw new RuntimeException('Managed .htaccess may not be a symbolic link.');
        }

        $existing = '';
        if (is_file($this->path)) {
            $contents = file_get_contents($this->path);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read the existing managed .htaccess file.');
            }
            $existing = $contents;
        }

        if (str_contains($existing, self::BEGIN_MARKER) || str_contains($existing, self::END_MARKER)) {
            $updated = $this->replaceManagedBlock($existing, $block);
        } elseif ($this->alreadyHasLegacyForwextRouting($existing, $rootLayout)) {
            return;
        } else {
            $prefix = rtrim($existing);
            $updated = ($prefix === '' ? '' : $prefix . PHP_EOL . PHP_EOL) . $block . PHP_EOL;
        }

        if ($updated === $existing) {
            return;
        }

        $this->atomicWrite($updated);
    }

    private function replaceManagedBlock(string $existing, string $block): string
    {
        $begin = strpos($existing, self::BEGIN_MARKER);
        $end = strpos($existing, self::END_MARKER);

        if ($begin === false || $end === false || $end < $begin) {
            throw new RuntimeException('Existing Forwext .htaccess markers are malformed.');
        }

        $end += strlen(self::END_MARKER);
        $before = rtrim(substr($existing, 0, $begin));
        $after = ltrim(substr($existing, $end));

        $parts = [];
        if ($before !== '') {
            $parts[] = $before;
        }
        $parts[] = $block;
        if ($after !== '') {
            $parts[] = $after;
        }

        return implode(PHP_EOL . PHP_EOL, $parts) . PHP_EOL;
    }

    private function alreadyHasLegacyForwextRouting(string $existing, bool $rootLayout): bool
    {
        $common = str_contains($existing, 'RewriteRule ^ index.php [L,QSA]')
            && str_contains($existing, 'RewriteCond %{REQUEST_FILENAME} !-f')
            && str_contains($existing, 'RewriteCond %{REQUEST_FILENAME} !-d');

        if (!$common) {
            return false;
        }

        return !$rootLayout
            || (
                str_contains($existing, 'RewriteRule ^assets/(.*)$ public/assets/$1 [L,NC]')
                && str_contains($existing, 'RewriteRule ^storage/(.*)$ public/storage/$1 [L,NC]')
            );
    }

    private function atomicWrite(string $contents): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            throw new RuntimeException('Managed .htaccess parent directory does not exist.');
        }

        $mode = 0644;
        if (is_file($this->path)) {
            $permissions = fileperms($this->path);
            if (is_int($permissions)) {
                $mode = $permissions & 0777;
            }
        }

        $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Unable to stage the managed .htaccess file.');
            }
            if (!chmod($temporary, $mode)) {
                throw new RuntimeException('Unable to preserve managed .htaccess permissions.');
            }
            if (!rename($temporary, $this->path)) {
                throw new RuntimeException('Unable to activate the managed .htaccess file.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function publicRoutingBlock(): string
    {
        return <<<'HTACCESS'
# BEGIN Forwext
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L,QSA]
</IfModule>
# END Forwext
HTACCESS;
    }

    private static function rootRoutingBlock(): string
    {
        return <<<'HTACCESS'
# BEGIN Forwext
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^assets/(.*)$ public/assets/$1 [L,NC]
    RewriteRule ^storage/(.*)$ public/storage/$1 [L,NC]
    RewriteRule ^(?:app|core|database|docs|frontend|modules|packages|resources|storage|tests|themes|tools|vendor)(?:/|$) - [F,L,NC]
    RewriteRule ^config(?:/|$) - [F,L,NC]
    RewriteRule ^(?:\.git|\.github)(?:/|$) - [F,L,NC]
    RewriteRule ^(?:composer\.(?:json|lock)|package(?:-lock)?\.json|phpunit\.xml\.dist|phpstan\.neon\.dist|psalm\.xml|phpcs\.xml\.dist|VERSION|PROJECT_STATUS\.md|CHANGELOG\.md|LICENSE|NOTICE|THIRD_PARTY_NOTICES\.md)$ - [F,L,NC]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L,QSA]
</IfModule>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>
# END Forwext
HTACCESS;
    }
}
