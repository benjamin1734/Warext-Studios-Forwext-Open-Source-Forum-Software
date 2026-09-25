<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

use FilesystemIterator;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonPackageInspector;
use Forwext\Core\Migration\SemanticVersion;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class AddonCompatibilityChecker
{
    private string $root;

    public function __construct(string $projectRoot)
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_file($root . '/VERSION')) {
            throw new RuntimeException('Forwext project root is invalid.');
        }
        $this->root = $root;
    }

    public function check(AddonId $id): AddonCompatibilityReport
    {
        $errors = [];
        $warnings = [];
        $addonsRoot = $this->root . '/addons';
        $packagePath = $addonsRoot . '/' . $id->vendor() . '/' . $id->name();

        try {
            $package = (new AddonPackageInspector($addonsRoot))->inspect($packagePath);
        } catch (\Throwable $exception) {
            return new AddonCompatibilityReport([$exception->getMessage()], []);
        }

        try {
            $current = SemanticVersion::parse(trim((string) file_get_contents($this->root . '/VERSION')));
            if ($package->manifest->minimumForwextVersion->isGreaterThan($current)) {
                $errors[] = 'Add-on requires a newer Forwext version: '
                    . $package->manifest->minimumForwextVersion->value();
            }
        } catch (\Throwable $exception) {
            $errors[] = 'Unable to evaluate Forwext compatibility: ' . $exception->getMessage();
        }

        $expectedNamespace = $id->vendor() . '\\' . $id->name();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packagePath, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink() || strtolower($entry->getExtension()) !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($packagePath) + 1));
            $source = file_get_contents($entry->getPathname());
            if (!is_string($source)) {
                $errors[] = $relative . ': unable to read PHP source.';
                continue;
            }
            if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $source) !== 1) {
                $errors[] = $relative . ': declare(strict_types=1) is required.';
            }
            try {
                $tokens = token_get_all($source, TOKEN_PARSE);
            } catch (ParseError $exception) {
                $errors[] = $relative . ': PHP parse error: ' . $exception->getMessage();
                continue;
            }

            if (str_starts_with($relative, 'src/') || str_starts_with($relative, 'migrations/')) {
                $quoted = preg_quote($expectedNamespace, '/');
                if (preg_match('/namespace\s+' . $quoted . '(?:\\\\[A-Za-z_][A-Za-z0-9_\\\\]*|)\s*;/', $source) !== 1) {
                    $errors[] = $relative . ': namespace must remain under ' . $expectedNamespace . '.';
                }
            }

            foreach ($this->forbiddenCalls($tokens) as $call) {
                $errors[] = $relative . ': forbidden high-risk function call ' . $call . '().';
            }
        }

        if (is_dir($packagePath . '/vendor')) {
            $warnings[] = 'Bundled vendor/ dependencies are present; review licenses and PHP 8.4 compatibility.';
        }

        sort($errors, SORT_STRING);
        sort($warnings, SORT_STRING);
        return new AddonCompatibilityReport(array_values(array_unique($errors)), array_values(array_unique($warnings)));
    }

    /** @param list<array|string> $tokens @return list<string> */
    private function forbiddenCalls(array $tokens): array
    {
        $forbidden = ['exec','shell_exec','system','passthru','proc_open','popen','pcntl_exec'];
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_EVAL) {
                $found[] = 'eval';
                continue;
            }
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $name = strtolower($token[1]);
            if (!in_array($name, $forbidden, true)) {
                continue;
            }
            for ($j = $i + 1; $j < $count; ++$j) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '(') {
                    $found[] = $name;
                }
                break;
            }
        }
        sort($found, SORT_STRING);
        return array_values(array_unique($found));
    }
}
