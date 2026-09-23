<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

use InvalidArgumentException;
use JsonException;

final class LayoutDocumentCodec
{
    public const SCHEMA = 'forwext-layout-builder';

    public function export(string $layoutKey, LayoutDocument $document): string
    {
        try {
            return json_encode([
                'schema' => self::SCHEMA,
                'version' => 1,
                'layout_key' => $layoutKey,
                'document' => $document->toArray(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "
";
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Layout export could not be encoded.', previous: $exception);
        }
    }

    public function import(string $json, string $expectedLayoutKey): LayoutDocument
    {
        if (strlen($json) > 1_048_576) {
            throw new InvalidArgumentException('Layout import payload is too large.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Layout import is not valid JSON.', previous: $exception);
        }

        if (
            !is_array($data)
            || ($data['schema'] ?? null) !== self::SCHEMA
            || ($data['version'] ?? null) !== 1
            || ($data['layout_key'] ?? null) !== $expectedLayoutKey
            || !is_array($data['document'] ?? null)
        ) {
            throw new InvalidArgumentException('Layout import envelope is invalid.');
        }

        return LayoutDocument::fromArray($data['document']);
    }
}
