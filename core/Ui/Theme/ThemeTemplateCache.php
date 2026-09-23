<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use RuntimeException;

final readonly class ThemeTemplateCache
{
    public function __construct(
        private string $directory,
        private ThemeTemplateCompiler $compiler = new ThemeTemplateCompiler(),
    ) {
    }

    public function compile(string $themeKey, ThemeRevision $revision): void
    {
        $themeDir = $this->themeDirectory($themeKey, $revision->revisionId);
        $this->ensureDirectory($themeDir);

        $templates = [];
        foreach ($revision->payload->templates as $key => $source) {
            $filename = hash('sha256', $key) . '.php';
            $compiled = $this->compiler->compile($key, $source);
            $this->atomicWrite($themeDir . '/' . $filename, $compiled);
            $templates[$key] = [
                'file' => $filename,
                'checksum' => hash('sha256', $compiled),
            ];
        }
        ksort($templates, SORT_STRING);

        $assets = [
            'css' => $this->writeAsset($themeDir, 'custom.css', $revision->payload->customCss),
            'js' => $this->writeAsset($themeDir, 'custom.js', $revision->payload->customJs),
        ];

        $json = json_encode(
            [
                'version' => 1,
                'revision_id' => $revision->revisionId->value(),
                'templates' => $templates,
                'assets' => $assets,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $this->atomicWrite($themeDir . '/manifest.json', $json);
    }

    /** @param array<string,scalar|null> $context */
    public function render(
        string $themeKey,
        EntityId $revisionId,
        string $templateKey,
        array $context,
    ): string {
        $themeDir = $this->themeDirectory($themeKey, $revisionId);
        $manifest = $this->manifest($themeDir, $revisionId);
        $entry = is_array($manifest['templates'] ?? null)
            ? ($manifest['templates'][$templateKey] ?? null)
            : null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || !is_string($entry['checksum'] ?? null)) {
            throw new RuntimeException('Compiled theme template is unavailable.');
        }

        $file = $themeDir . '/' . $entry['file'];
        $compiled = $this->verifiedFile($file, $entry['checksum']);

        $renderer = include $file;
        if (!$renderer instanceof \Closure) {
            throw new RuntimeException('Compiled theme template renderer is invalid.');
        }

        $result = $renderer($context);
        if (!is_string($result)) {
            throw new RuntimeException('Compiled theme template returned invalid output.');
        }

        return $result;
    }

    public function asset(string $themeKey, EntityId $revisionId, string $kind): string
    {
        if (!in_array($kind, ['css', 'js'], true)) {
            throw new InvalidArgumentException('Theme asset kind is invalid.');
        }

        $themeDir = $this->themeDirectory($themeKey, $revisionId);
        $manifest = $this->manifest($themeDir, $revisionId);
        $entry = is_array($manifest['assets'] ?? null)
            ? ($manifest['assets'][$kind] ?? null)
            : null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || !is_string($entry['checksum'] ?? null)) {
            throw new RuntimeException('Compiled theme asset is unavailable.');
        }

        return $this->verifiedFile($themeDir . '/' . $entry['file'], $entry['checksum']);
    }

    /** @return array{file:string,checksum:string} */
    private function writeAsset(string $themeDir, string $filename, string $content): array
    {
        $this->atomicWrite($themeDir . '/' . $filename, $content);

        return [
            'file' => $filename,
            'checksum' => hash('sha256', $content),
        ];
    }

    /** @return array<string,mixed> */
    private function manifest(string $themeDir, EntityId $revisionId): array
    {
        $manifestPath = $themeDir . '/manifest.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Compiled theme manifest is unavailable.');
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new RuntimeException('Compiled theme manifest cannot be read.');
        }
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($manifest)
            || ($manifest['version'] ?? null) !== 1
            || ($manifest['revision_id'] ?? null) !== $revisionId->value()
        ) {
            throw new RuntimeException('Compiled theme manifest is invalid.');
        }

        return $manifest;
    }

    private function verifiedFile(string $file, string $checksum): string
    {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
            || !is_file($file)
            || is_link($file)
        ) {
            throw new RuntimeException('Compiled theme artifact is unavailable.');
        }

        $content = file_get_contents($file);
        if ($content === false || !hash_equals($checksum, hash('sha256', $content))) {
            throw new RuntimeException('Compiled theme artifact checksum mismatch.');
        }

        return $content;
    }

    private function themeDirectory(string $themeKey, EntityId $revisionId): string
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $themeKey) !== 1) {
            throw new RuntimeException('Theme cache key is invalid.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $revisionId->value()) !== 1) {
            throw new RuntimeException('Theme cache revision id is invalid.');
        }

        return rtrim($this->directory, '/\\')
            . '/' . hash('sha256', $themeKey)
            . '/' . $revisionId->value();
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create theme template cache directory.');
        }
    }

    private function atomicWrite(string $path, string $content): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Theme cache file may not be a symbolic link.');
        }

        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Unable to write theme cache file.');
            }
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to restrict theme cache file permissions.');
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically publish theme cache file.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
