<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use DirectoryIterator;
use Forwext\Core\Search\Lifecycle\SearchContentSourceRegistry;
use Forwext\Core\Search\Lifecycle\SearchIndexLifecycleService;
use RuntimeException;

final readonly class UpdateRuntimeRebuildService
{
    private const SEARCH_BATCH = 250;
    private const SEARCH_MAX_DOCUMENTS = 2_000_000;

    public function __construct(
        private string $cacheDirectory,
        private SearchIndexLifecycleService $search,
        private SearchContentSourceRegistry $searchSources,
    ) {
        if ($this->cacheDirectory === '' || str_contains($this->cacheDirectory, "\0")) {
            throw new UpdateException('Update cache rebuild path is invalid.');
        }
    }

    public function registry(): UpdateRebuildRegistry
    {
        return new UpdateRebuildRegistry([
            'cache.clear' => fn (): mixed => $this->clearCache(),
            'search.index.rebuild' => fn (): mixed => $this->rebuildSearch(),
        ]);
    }

    private function clearCache(): void
    {
        if (!is_dir($this->cacheDirectory)) {
            return;
        }
        if (is_link($this->cacheDirectory)) {
            throw new UpdateException('Update cache root may not be a symbolic link.');
        }

        $this->clearDirectory($this->cacheDirectory);
    }

    private function clearDirectory(string $directory): void
    {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if ($entry->isLink() || $entry->isFile()) {
                if (!@unlink($path)) {
                    throw new UpdateException('Update cache rebuild could not remove a cache entry.');
                }
                continue;
            }
            if ($entry->isDir()) {
                $this->clearDirectory($path);
                if (!@rmdir($path)) {
                    throw new UpdateException('Update cache rebuild could not remove a cache directory.');
                }
                continue;
            }
            throw new RuntimeException('Update cache rebuild encountered an unsupported filesystem entry.');
        }
    }

    private function rebuildSearch(): void
    {
        $processed = 0;
        foreach ($this->searchSources->types() as $documentType) {
            $cursor = null;
            do {
                $result = $this->search->rebuild($documentType, $cursor, self::SEARCH_BATCH);
                $processed += $result->processed;
                if ($processed > self::SEARCH_MAX_DOCUMENTS) {
                    throw new UpdateException('Update search rebuild exceeded its safety document limit.');
                }

                if ($result->nextCursor !== null && $result->processed === 0) {
                    throw new UpdateException('Update search rebuild returned a non-progressing continuation cursor.');
                }
                if ($result->nextCursor !== null && $result->nextCursor === $cursor) {
                    throw new UpdateException('Update search rebuild continuation cursor did not advance.');
                }
                $cursor = $result->nextCursor;
            } while ($cursor !== null);
        }
    }
}
