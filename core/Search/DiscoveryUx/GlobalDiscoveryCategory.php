<?php

declare(strict_types=1);

namespace Forwext\Core\Search\DiscoveryUx;

use Forwext\Core\Search\SearchDocument;
use InvalidArgumentException;

final readonly class GlobalDiscoveryCategory
{
    /** @var non-empty-list<string> */
    public array $documentTypes;

    /** @param non-empty-list<string> $documentTypes */
    public function __construct(
        public string $key,
        public string $label,
        array $documentTypes,
        public int $order = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Global discovery category key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 80) {
            throw new InvalidArgumentException('Global discovery category label must contain 1..80 bytes.');
        }
        if ($documentTypes === [] || count($documentTypes) > 32) {
            throw new InvalidArgumentException('Global discovery category requires 1..32 document types.');
        }
        $normalized = [];
        foreach ($documentTypes as $type) {
            if (!is_string($type)) {
                throw new InvalidArgumentException('Global discovery document types must be strings.');
            }
            SearchDocument::validateIdentifier($type, 'document type');
            $normalized[$type] = true;
        }
        if (count($normalized) !== count($documentTypes)) {
            throw new InvalidArgumentException('Global discovery document types must be unique.');
        }
        if ($this->order < -10000 || $this->order > 10000) {
            throw new InvalidArgumentException('Global discovery category order is outside supported bounds.');
        }
        /** @var non-empty-list<string> $types */
        $types = array_keys($normalized);
        $this->documentTypes = $types;
    }
}
