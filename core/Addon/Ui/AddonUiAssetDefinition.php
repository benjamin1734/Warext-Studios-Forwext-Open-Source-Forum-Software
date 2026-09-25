<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use InvalidArgumentException;

final readonly class AddonUiAssetDefinition
{
    public string $checksum;

    public function __construct(
        public string $key,
        public AddonUiAssetKind $kind,
        public string $content,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,190}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Add-on UI asset key is invalid.');
        }
        $limit = $this->kind === AddonUiAssetKind::Css ? 262144 : 131072;
        if (
            strlen($this->content) > $limit
            || preg_match('//u', $this->content) !== 1
            || str_contains($this->content, "\0")
        ) {
            throw new InvalidArgumentException('Add-on UI asset content is invalid.');
        }
        if ($this->kind === AddonUiAssetKind::Css) {
            if (
                str_contains(strtolower($this->content), '@import')
                || preg_match('/url\s*\(\s*[\'"]?\s*(?:https?:|javascript:|data:)/i', $this->content) === 1
            ) {
                throw new InvalidArgumentException('Add-on CSS may not reference external or executable URLs.');
            }
        } elseif (stripos($this->content, '</script') !== false) {
            throw new InvalidArgumentException('Add-on JavaScript contains an unsafe script terminator.');
        }

        $this->checksum = hash('sha256', $this->content);
    }
}
