<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

use InvalidArgumentException;
use JsonException;

final readonly class AddonArtifactSignature
{
    public const ALGORITHM = 'openssl-sha256';

    public function __construct(
        public string $keyId,
        public string $artifactChecksum,
        public string $signature,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,95}$/D', $this->keyId) !== 1) {
            throw new InvalidArgumentException('Add-on signature key id is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->artifactChecksum) !== 1) {
            throw new InvalidArgumentException('Add-on signature artifact checksum is invalid.');
        }
        $decoded = base64_decode($this->signature, true);
        if (!is_string($decoded) || $decoded === '' || strlen($decoded) > 16384) {
            throw new InvalidArgumentException('Add-on signature payload is invalid.');
        }
    }

    public static function fromJson(string $json): self
    {
        if ($json === '' || strlen($json) > 32768) {
            throw new InvalidArgumentException('Add-on signature document size is invalid.');
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Add-on signature document is invalid JSON.', previous:$exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Add-on signature document must be an object.');
        }
        if (array_diff(array_keys($data), ['schema','algorithm','key_id','artifact_sha256','signature']) !== []) {
            throw new InvalidArgumentException('Add-on signature document contains an unknown key.');
        }
        if (($data['schema'] ?? null) !== 1 || ($data['algorithm'] ?? null) !== self::ALGORITHM) {
            throw new InvalidArgumentException('Unsupported add-on signature schema or algorithm.');
        }
        foreach (['key_id','artifact_sha256','signature'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                throw new InvalidArgumentException('Add-on signature document is missing a string field.');
            }
        }

        return new self($data['key_id'], $data['artifact_sha256'], $data['signature']);
    }

    public function json(): string
    {
        try {
            return json_encode([
                'schema'=>1,
                'algorithm'=>self::ALGORITHM,
                'key_id'=>$this->keyId,
                'artifact_sha256'=>$this->artifactChecksum,
                'signature'=>$this->signature,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode add-on signature document.', previous:$exception);
        }
    }

    public function signingPayload(): string
    {
        return "forwext-addon-artifact-v1\n" . $this->artifactChecksum . "\n";
    }
}
