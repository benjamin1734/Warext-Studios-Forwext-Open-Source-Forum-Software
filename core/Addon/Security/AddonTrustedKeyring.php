<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

use InvalidArgumentException;
use JsonException;

final class AddonTrustedKeyring
{
    /** @var array<string,AddonTrustedKey> */
    private array $keys = [];

    /** @param iterable<AddonTrustedKey> $keys */
    public function __construct(iterable $keys = [])
    {
        foreach ($keys as $key) {
            if (!$key instanceof AddonTrustedKey) {
                throw new InvalidArgumentException('Trusted add-on keyring contains an invalid entry.');
            }
            if (isset($this->keys[$key->id])) {
                throw new InvalidArgumentException('Trusted add-on key id is duplicated: ' . $key->id);
            }
            $this->keys[$key->id] = $key;
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Trusted add-on keyring JSON is invalid.', previous:$exception);
        }
        if (!is_array($data) || array_is_list($data) || ($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Trusted add-on keyring shape is invalid.');
        }
        if (array_diff(array_keys($data), ['schema','keys']) !== []) {
            throw new InvalidArgumentException('Trusted add-on keyring contains an unknown key.');
        }
        $entries = $data['keys'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > 128) {
            throw new InvalidArgumentException('Trusted add-on keyring entries are invalid.');
        }

        $keys = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new InvalidArgumentException('Trusted add-on key entry is invalid.');
            }
            if (array_diff(array_keys($entry), ['id','public_key_pem','official']) !== []) {
                throw new InvalidArgumentException('Trusted add-on key entry contains an unknown field.');
            }
            $id = $entry['id'] ?? null;
            $pem = $entry['public_key_pem'] ?? null;
            $official = $entry['official'] ?? false;
            if (!is_string($id) || !is_string($pem) || !is_bool($official)) {
                throw new InvalidArgumentException('Trusted add-on key entry fields are invalid.');
            }
            $keys[] = new AddonTrustedKey($id, $pem, $official);
        }

        return new self($keys);
    }

    public function find(string $id): ?AddonTrustedKey
    {
        return $this->keys[$id] ?? null;
    }

    /** @return list<AddonTrustedKey> */
    public function all(): array
    {
        $keys = $this->keys;
        ksort($keys, SORT_STRING);

        return array_values($keys);
    }
}
