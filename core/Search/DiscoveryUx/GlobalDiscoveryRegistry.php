<?php

declare(strict_types=1);

namespace Forwext\Core\Search\DiscoveryUx;

use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;
use InvalidArgumentException;

final class GlobalDiscoveryRegistry
{
    public const ALL = 'all';

    /** @var array<string, GlobalDiscoveryCategory> */
    private array $categories = [];

    /** @var array<string, string> document type => category key */
    private array $typeOwners = [];

    /** @param iterable<GlobalDiscoveryContributor> $contributors */
    public static function withCoreDefaults(iterable $contributors = []): self
    {
        $registry = new self();
        $registry->register(new GlobalDiscoveryCategory('forum', 'Forum', ['forum', 'thread', 'post'], 100));
        $registry->register(new GlobalDiscoveryCategory('support', 'Destek', ['support.ticket', 'support.message'], 200));
        $registry->register(new GlobalDiscoveryCategory('faq', 'SSS', ['faq.article'], 300));
        $registry->register(new GlobalDiscoveryCategory('portfolio', 'Portfolyo', ['portfolio.item'], 400));
        $registry->register(new GlobalDiscoveryCategory('giveaway', 'Çekilişler', ['giveaway.item'], 450));
        $registry->register(new GlobalDiscoveryCategory('marketplace', 'Marketplace', ['marketplace.listing'], 500));
        $registry->register(new GlobalDiscoveryCategory('members', 'Üyeler', ['user'], 600));

        foreach ($contributors as $contributor) {
            $contributor->registerDiscovery($registry);
        }

        return $registry;
    }

    public function register(GlobalDiscoveryCategory $category): void
    {
        if ($category->key === self::ALL || isset($this->categories[$category->key])) {
            throw new InvalidArgumentException('Global discovery category key is reserved or already registered.');
        }
        foreach ($category->documentTypes as $type) {
            if (isset($this->typeOwners[$type])) {
                throw new InvalidArgumentException('Global discovery document type already belongs to a category: ' . $type);
            }
        }

        $this->categories[$category->key] = $category;
        foreach ($category->documentTypes as $type) {
            $this->typeOwners[$type] = $category->key;
        }
    }

    /** @return list<GlobalDiscoveryCategory> */
    public function categories(): array
    {
        $categories = array_values($this->categories);
        usort(
            $categories,
            static fn (GlobalDiscoveryCategory $left, GlobalDiscoveryCategory $right): int =>
                [$left->order, $left->label, $left->key] <=> [$right->order, $right->label, $right->key],
        );
        return $categories;
    }

    public function find(string $key): ?GlobalDiscoveryCategory
    {
        return $this->categories[$key] ?? null;
    }

    public function categoryForType(string $documentType): ?GlobalDiscoveryCategory
    {
        $key = $this->typeOwners[$documentType] ?? null;
        return $key === null ? null : $this->categories[$key];
    }

    /** @return list<string> */
    public function allDocumentTypes(): array
    {
        $types = [];
        foreach ($this->categories() as $category) {
            foreach ($category->documentTypes as $type) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * Resolves the exact backend document-type allowlist for a selected UX tab.
     * Explicit advanced type filters are constrained to the selected category and
     * cannot be used to escape that tab or address an unregistered hidden type.
     *
     * @param list<string> $requestedTypes
     * @return non-empty-list<string>
     */
    public function resolveDocumentTypes(string $categoryKey, array $requestedTypes = []): array
    {
        $allowed = $categoryKey === self::ALL
            ? $this->allDocumentTypes()
            : ($this->find($categoryKey)?->documentTypes
                ?? throw new SearchException('Global discovery category is not registered.'));

        if ($allowed === []) {
            throw new SearchException('Global discovery category has no document types.');
        }
        if ($requestedTypes === []) {
            /** @var non-empty-list<string> $allowed */
            return $allowed;
        }

        $allowedMap = array_fill_keys($allowed, true);
        $resolved = [];
        foreach ($requestedTypes as $type) {
            if (!is_string($type)) {
                throw new SearchException('Global discovery document type filter is invalid.');
            }
            SearchDocument::validateIdentifier($type, 'document type');
            if (!isset($this->typeOwners[$type])) {
                throw new SearchException('Global discovery document type is not registered.');
            }
            if (!isset($allowedMap[$type])) {
                throw new SearchException('Document type is outside the selected discovery category.');
            }
            if (isset($resolved[$type])) {
                throw new SearchException('Global discovery document type filter contains duplicates.');
            }
            $resolved[$type] = true;
        }

        /** @var non-empty-list<string> $types */
        $types = array_keys($resolved);
        return $types;
    }
}
