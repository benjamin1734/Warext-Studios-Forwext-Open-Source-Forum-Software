<?php

declare(strict_types=1);

namespace Forwext\Core\Search\External;

use DateTimeInterface;
use Forwext\Core\Search\SearchAttribute;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;
use Forwext\Core\Search\SearchHit;
use Forwext\Core\Search\SearchQuery;
use JsonException;

final readonly class MeilisearchExternalSearchClient implements ExternalSearchClient
{
    private const MAX_RESPONSE_BYTES = 8_388_608;

    public function __construct(
        private string $endpoint,
        private string $index,
        private ?string $apiKey = null,
        private int $timeoutMs = 5000,
    ) {
        $parts = parse_url($endpoint);
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new SearchException('Meilisearch endpoint is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $index) !== 1) {
            throw new SearchException('Meilisearch index name is invalid.');
        }
        if ($apiKey !== null && (strlen($apiKey) < 8 || strlen($apiKey) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1)) {
            throw new SearchException('Meilisearch API key is invalid.');
        }
        if ($timeoutMs < 250 || $timeoutMs > 30000) {
            throw new SearchException('Meilisearch timeout must be between 250 and 30000 milliseconds.');
        }
        if (!function_exists('curl_init')) {
            throw new SearchException('The optional cURL extension is required for external search.');
        }
    }

    public function upsert(SearchDocument $document): void
    {
        $attributes = [];
        foreach ($document->attributes as $key => $values) {
            SearchAttribute::validateKey($key);
            $attributes['attr_' . $key] = $values;
        }

        $payload = [[
            'id' => $document->key(),
            'document_type' => $document->documentType,
            'document_id' => $document->documentId,
            'title' => $document->title,
            'body' => $document->body,
            'access_scopes' => $document->accessScopes,
            'updated_at' => $document->updatedAt->format(DateTimeInterface::ATOM),
            'updated_at_unix' => $document->updatedAt->getTimestamp(),
            'locale' => $document->locale,
            ...$attributes,
        ]];

        $this->request(
            'POST',
            '/indexes/' . rawurlencode($this->index) . '/documents?primaryKey=id',
            $payload,
            [200, 202],
        );
    }

    public function delete(string $documentType, string $documentId): bool
    {
        SearchDocument::validateIdentifier($documentType, 'document type');
        SearchDocument::validateIdentifier($documentId, 'document id');
        $key = hash('sha256', $documentType . "\0" . $documentId);

        $response = $this->request(
            'DELETE',
            '/indexes/' . rawurlencode($this->index) . '/documents/' . rawurlencode($key),
            null,
            [200, 202, 204, 404],
        );

        return $response['status'] !== 404;
    }

    public function search(SearchQuery $query): array
    {
        $filters = [];
        $filters[] = self::orFilter('access_scopes', $query->accessScopes);
        if ($query->documentTypes !== []) {
            $filters[] = self::orFilter('document_type', $query->documentTypes);
        }
        if ($query->locale !== null) {
            $filters[] = 'locale = ' . self::literal($query->locale);
        }

        foreach ($query->filters->attributes() as $key => $values) {
            SearchAttribute::validateKey($key);
            if ($values !== []) {
                $filters[] = self::orFilter('attr_' . $key, $values);
            }
        }
        if ($query->filters->updatedAfter !== null) {
            $filters[] = 'updated_at_unix >= ' . $query->filters->updatedAfter->getTimestamp();
        }
        if ($query->filters->updatedBefore !== null) {
            $filters[] = 'updated_at_unix <= ' . $query->filters->updatedBefore->getTimestamp();
        }

        $response = $this->request(
            'POST',
            '/indexes/' . rawurlencode($this->index) . '/search',
            [
                'q' => $query->text,
                'filter' => implode(' AND ', array_map(
                    static fn (string $filter): string => '(' . $filter . ')',
                    $filters,
                )),
                'limit' => $query->limit,
                'offset' => $query->offset,
                'attributesToRetrieve' => ['document_type', 'document_id'],
                'showRankingScore' => true,
            ],
            [200],
        );

        $payload = $response['json'];
        $rows = $payload['hits'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new SearchException('Meilisearch search response contains invalid hits.');
        }

        $hits = [];
        foreach ($rows as $row) {
            if (
                !is_array($row)
                || !is_string($row['document_type'] ?? null)
                || !is_string($row['document_id'] ?? null)
            ) {
                throw new SearchException('Meilisearch hit has an invalid shape.');
            }
            $score = $row['_rankingScore'] ?? 0.0;
            if (!is_int($score) && !is_float($score)) {
                throw new SearchException('Meilisearch hit score is invalid.');
            }
            $hits[] = new SearchHit(
                $row['document_type'],
                $row['document_id'],
                max(0.0, (float) $score),
            );
            if (count($hits) >= $query->limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * Configure the provider index for Forwext's safe candidate-discovery contract.
     * This is intentionally explicit so normal web requests never mutate provider settings.
     */
    public function provision(): void
    {
        $filterable = [
            'access_scopes',
            'document_type',
            'locale',
            'updated_at_unix',
            ...array_map(
                static fn (string $key): string => 'attr_' . $key,
                SearchAttribute::filterable(),
            ),
        ];

        $this->request(
            'PUT',
            '/indexes/' . rawurlencode($this->index) . '/settings/filterable-attributes',
            $filterable,
            [200, 202],
        );
        $this->request(
            'PUT',
            '/indexes/' . rawurlencode($this->index) . '/settings/searchable-attributes',
            ['title', 'body'],
            [200, 202],
        );
        $this->request(
            'PUT',
            '/indexes/' . rawurlencode($this->index) . '/settings/displayed-attributes',
            ['document_type', 'document_id'],
            [200, 202],
        );
    }

    /** @param non-empty-list<string>|list<string> $values */
    private static function orFilter(string $field, array $values): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $field) !== 1 || $values === []) {
            throw new SearchException('Meilisearch filter field/value set is invalid.');
        }

        $parts = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new SearchException('Meilisearch filter value is invalid.');
            }
            $parts[] = $field . ' = ' . self::literal($value);
        }

        return implode(' OR ', $parts);
    }

    private static function literal(string $value): string
    {
        if (strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new SearchException('Meilisearch filter literal is invalid.');
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * @param array<mixed>|null $body
     * @param list<int> $acceptedStatuses
     * @return array{status:int,json:array<mixed>}
     */
    private function request(string $method, string $path, ?array $body, array $acceptedStatuses): array
    {
        $url = rtrim($this->endpoint, '/') . $path;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SearchException('Unable to initialize Meilisearch request.');
        }

        $responseBody = '';
        $responseBytes = 0;
        $headers = ['Accept: application/json'];
        if ($this->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->timeoutMs, 5000),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$responseBytes): int {
                $responseBytes += strlen($chunk);
                if ($responseBytes > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];

        if ($body !== null) {
            try {
                $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $exception) {
                curl_close($handle);
                throw new SearchException('Unable to encode Meilisearch request.', previous: $exception);
            }
            $options[CURLOPT_POSTFIELDS] = $json;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($ok === false || $responseBytes > self::MAX_RESPONSE_BYTES) {
            throw new SearchException($responseBytes > self::MAX_RESPONSE_BYTES
                ? 'Meilisearch response exceeded the configured safety limit.'
                : 'Meilisearch request failed: ' . ($error !== '' ? $error : 'transport_error'));
        }

        $decoded = [];
        if ($responseBody !== '') {
            try {
                $value = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new SearchException('Meilisearch returned invalid JSON.', previous: $exception);
            }
            if (!is_array($value)) {
                throw new SearchException('Meilisearch returned an invalid response shape.');
            }
            $decoded = $value;
        }

        if (!in_array($status, $acceptedStatuses, true)) {
            $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'provider_error';
            throw new SearchException(sprintf('Meilisearch request failed with HTTP %d (%s).', $status, $code));
        }

        return ['status' => $status, 'json' => $decoded];
    }
}
