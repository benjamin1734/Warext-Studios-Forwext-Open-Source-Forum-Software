<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class ThreadMetadata
{
    /** @var list<TagName> */
    private array $tags;
    /** @var array<string, CustomFieldValue> */
    private array $fieldValues;

    /**
     * @param list<TagName> $tags
     * @param array<string, CustomFieldValue> $fieldValues
     */
    public function __construct(
        private readonly ?EntityId $prefixId,
        array $tags,
        array $fieldValues,
    ) {
        if ($this->prefixId !== null) {
            MetadataId::assert($this->prefixId);
        }

        $uniqueTags = [];
        foreach ($tags as $tag) {
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($tag->value(), 'UTF-8')
                : strtolower($tag->value());
            $uniqueTags[$key] = $tag;
        }
        $this->tags = array_values($uniqueTags);

        foreach ($fieldValues as $key => $value) {
            if (!is_string($key) || $key !== CustomFieldKey::fromString($key)->value()) {
                throw new InvalidArgumentException('Thread metadata contains an invalid custom field key.');
            }
            if (!$value instanceof CustomFieldValue) {
                throw new InvalidArgumentException('Thread metadata custom field values must be typed.');
            }
        }
        ksort($fieldValues, SORT_STRING);
        $this->fieldValues = $fieldValues;
    }

    public function prefixId(): ?EntityId
    {
        return $this->prefixId;
    }

    /** @return list<TagName> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return array<string, CustomFieldValue> */
    public function fieldValues(): array
    {
        return $this->fieldValues;
    }
}
