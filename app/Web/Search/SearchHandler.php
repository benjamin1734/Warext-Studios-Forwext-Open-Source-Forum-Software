<?php

declare(strict_types=1);

namespace Forwext\App\Web\Search;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Search\AdvancedSearchFilters;
use Forwext\Core\Search\DiscoveryUx\GlobalDiscoveryRegistry;
use Forwext\Core\Search\PermissionAwareSearchService;
use Forwext\Core\Search\SearchException;
use InvalidArgumentException;

final readonly class SearchHandler implements RequestHandlerInterface
{
    public function __construct(
        private PermissionAwareSearchService $search,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {}

    public function handle(Request $request): Response
    {
        $registry = GlobalDiscoveryRegistry::withCoreDefaults();
        $query = $request->query();
        $text = '';
        $tab = GlobalDiscoveryRegistry::ALL;

        try {
            $text = self::scalar($query, 'q') ?? '';
            $tab = self::scalar($query, 'tab') ?? GlobalDiscoveryRegistry::ALL;
            $registry->resolveDocumentTypes($tab);

            if (trim($text) === '') {
                return Response::html(SearchHtml::page(
                    $this->basePath,
                    '',
                    [],
                    $query,
                    null,
                    $this->search->savedQueryKeys(),
                    discovery: $registry,
                    selectedTab: $tab,
                ));
            }

            $actor = $this->viewers->resolve($request);
            if ($actor === null) {
                return Response::html(SearchHtml::page(
                    $this->basePath,
                    trim($text),
                    [],
                    $query,
                    'Arama yapmak için oturum açmalısınız.',
                    $this->search->savedQueryKeys(),
                    discovery: $registry,
                    selectedTab: $tab,
                ), 401)->withHeader('Cache-Control', 'private, no-store');
            }

            $requestedTypes = self::list($query, 'type');
            $filters = new AdvancedSearchFilters(
                forumIds: self::list($query, 'forum'),
                userIds: self::list($query, 'user'),
                prefixIds: self::list($query, 'prefix'),
                tagIds: self::list($query, 'tag'),
                states: self::list($query, 'state'),
                threadTypes: self::list($query, 'thread_type'),
                updatedAfter: self::date(self::scalar($query, 'after'), false),
                updatedBefore: self::date(self::scalar($query, 'before'), true),
            );
            $page = self::page($query);
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $saved = self::scalar($query, 'saved');

            if ($saved !== null && $saved !== '') {
                if ($tab !== GlobalDiscoveryRegistry::ALL || $requestedTypes !== []) {
                    throw new SearchException(
                        'Saved searches cannot be combined with a discovery tab or explicit type filter.',
                    );
                }
                $hits = $this->search->searchSaved($actor, $saved, $text, $limit, $offset);
            } else {
                $documentTypes = $registry->resolveDocumentTypes($tab, $requestedTypes);
                $hits = $this->search->search(
                    $actor,
                    $text,
                    $documentTypes,
                    null,
                    $limit,
                    $offset,
                    $filters,
                );
            }

            return Response::html(SearchHtml::page(
                $this->basePath,
                trim($text),
                $hits,
                $query,
                null,
                $this->search->savedQueryKeys(),
                $page,
                $registry,
                $tab,
                true,
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::html(SearchHtml::page(
                $this->basePath,
                trim($text),
                [],
                $query,
                'Bu hesap için arama izni bulunmuyor.',
                $this->search->savedQueryKeys(),
                discovery: $registry,
                selectedTab: self::safeTab($registry, $tab),
            ), 403)->withHeader('Cache-Control', 'private, no-store');
        } catch (SearchException|InvalidArgumentException) {
            return Response::html(SearchHtml::page(
                $this->basePath,
                trim($text),
                [],
                $query,
                'Arama sekmesi veya filtrelerinden biri geçersiz.',
                $this->search->savedQueryKeys(),
                discovery: $registry,
                selectedTab: self::safeTab($registry, $tab),
            ), 400)->withHeader('Cache-Control', 'private, no-store');
        }
    }

    /** @param array<string,mixed> $query */
    private static function scalar(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null) return null;
        if (!is_string($value) || strlen($value) > 500) {
            throw new InvalidArgumentException('Search query parameter is invalid.');
        }
        return trim($value);
    }

    /** @param array<string,mixed> $query @return list<string> */
    private static function list(array $query, string $key): array
    {
        $value = self::scalar($query, $key);
        if ($value === null || $value === '') return [];
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
        if (count($parts) > 32) {
            throw new InvalidArgumentException('Search filter has too many values.');
        }
        return $parts;
    }

    private static function date(?string $value, bool $endOfDay): ?DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Search date is invalid.');
        }
        return $endOfDay ? $date->setTime(23, 59, 59, 999999) : $date;
    }

    /** @param array<string,mixed> $query */
    private static function page(array $query): int
    {
        $value = self::scalar($query, 'page');
        if ($value === null || $value === '') return 1;
        if (preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Search page is invalid.');
        }
        $page = (int) $value;
        if ($page > 51) {
            throw new InvalidArgumentException('Search page is outside the supported range.');
        }
        return $page;
    }

    private static function safeTab(GlobalDiscoveryRegistry $registry, string $tab): string
    {
        return $tab === GlobalDiscoveryRegistry::ALL || $registry->find($tab) !== null
            ? $tab
            : GlobalDiscoveryRegistry::ALL;
    }
}
