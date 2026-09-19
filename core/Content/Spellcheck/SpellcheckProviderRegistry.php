<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use InvalidArgumentException;

final class SpellcheckProviderRegistry
{
    /** @var array<string,SpellcheckProvider> */
    private array $providers = [];

    /** @param iterable<SpellcheckProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(SpellcheckProvider $provider): void
    {
        $key = $provider->key();
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Spellcheck provider key is invalid.');
        }
        if (isset($this->providers[$key])) {
            throw new InvalidArgumentException('Spellcheck provider is already registered.');
        }
        $this->providers[$key] = $provider;
    }

    public function resolve(string $language, ?string $preferredKey = null): SpellcheckProvider
    {
        $language = SpellcheckLanguage::normalize($language);
        if ($preferredKey !== null) {
            $provider = $this->providers[$preferredKey]
                ?? throw new InvalidArgumentException('Preferred spellcheck provider is not registered.');
            if (!$provider->supports($language)) {
                throw new InvalidArgumentException('Preferred spellcheck provider does not support the language.');
            }
            return $provider;
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports($language)) {
                return $provider;
            }
        }
        throw new InvalidArgumentException('No spellcheck provider supports the requested language.');
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->providers);
        sort($keys, SORT_STRING);
        return $keys;
    }
}
