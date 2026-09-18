<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Install;

use Forwext\Core\Install\ApacheHtaccessManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApacheHtaccessManagerTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-htaccess-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->path = $this->directory . '/.htaccess';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        foreach (glob($this->directory . '/.htaccess.*.tmp') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testExistingCpanelPhpHandlerIsPreservedWhenRoutingIsAdded(): void
    {
        $handler = <<<'HTACCESS'
# php -- BEGIN cPanel-generated handler, do not edit
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php84 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
HTACCESS;
        file_put_contents($this->path, $handler . PHP_EOL);

        (new ApacheHtaccessManager($this->path))->ensurePublicRouting();

        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString('application/x-httpd-ea-php84', $contents);
        self::assertStringContainsString('# BEGIN Forwext', $contents);
        self::assertStringContainsString('RewriteRule ^ index.php [L,QSA]', $contents);
    }

    public function testManagedBlockIsIdempotentAndDoesNotDuplicateCpanelHandler(): void
    {
        file_put_contents(
            $this->path,
            "# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler application/x-httpd-ea-php84 .php\n# php -- END cPanel-generated handler, do not edit\n",
        );

        $manager = new ApacheHtaccessManager($this->path);
        $manager->ensurePublicRouting();
        $manager->ensurePublicRouting();

        $contents = (string) file_get_contents($this->path);
        self::assertSame(1, substr_count($contents, '# BEGIN Forwext'));
        self::assertSame(1, substr_count($contents, 'application/x-httpd-ea-php84'));
    }

    public function testLegacyForwextRoutingIsLeftUntouchedSoExistingHandlerSurvives(): void
    {
        $legacy = <<<'HTACCESS'
Options -Indexes
DirectoryIndex index.php
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L,QSA]
</IfModule>
# php -- BEGIN cPanel-generated handler, do not edit
AddHandler application/x-httpd-ea-php84 .php
# php -- END cPanel-generated handler, do not edit
HTACCESS;
        file_put_contents($this->path, $legacy . PHP_EOL);

        (new ApacheHtaccessManager($this->path))->ensurePublicRouting();

        self::assertSame($legacy . PHP_EOL, file_get_contents($this->path));
    }

    public function testMalformedManagedMarkersFailClosed(): void
    {
        file_put_contents($this->path, "# BEGIN Forwext\nRewriteEngine On\n");

        $this->expectException(RuntimeException::class);
        (new ApacheHtaccessManager($this->path))->ensurePublicRouting();
    }
}
