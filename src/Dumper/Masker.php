<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Dumper;

/**
 * Decides which keys are secret and scrubs secret-looking string values.
 *
 * Keys are matched as whole words after splitting on separators and camelCase, so `api_key`, `apiKey` and `api-key`
 * are the same key, `credit_card_number` matches `card`, and `company` does NOT match `pan`. Long entries (5+ characters)
 * additionally match as a substring of the squashed key (`accesstoken`, `passwordhash`). Entries starting with "/" are regexes.
 */
final class Masker
{
    /** @var list<list<string>> */
    private array $wordEntries = [];

    /** @var list<string> */
    private array $substringEntries = [];

    /** @var list<string> */
    private array $regexEntries = [];

    /** @var array<string, bool> */
    private array $memo = [];

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $valuePatterns
     */
    public function __construct(
        array $keys,
        public readonly string $mask,
        private readonly array $valuePatterns = [],
    ) {
        foreach ($keys as $entry) {
            $entry = (string) $entry;
            if ($entry === '') {
                continue;
            }
            if ($entry[0] === '/') {
                $this->regexEntries[] = $entry;

                continue;
            }
            $words = self::words($entry);
            if ($words === []) {
                continue;
            }
            $this->wordEntries[] = $words;
            $squashed = implode('', $words);
            if (strlen($squashed) >= 5) {
                $this->substringEntries[] = $squashed;
            }
        }
    }

    /** @param  array<string, mixed>  $config  the `masking` section of the config */
    public static function fromConfig(array $config): self
    {
        return new self(
            array_map('strval', array_values((array) ($config['keys'] ?? []))),
            (string) ($config['mask'] ?? '••••••••'),
            array_map('strval', array_values((array) ($config['value_patterns'] ?? []))),
        );
    }

    public function isSensitiveKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (count($this->memo) > 5000) {
            $this->memo = [];
        }

        return $this->memo[$key] = $this->compute($key);
    }

    /** Replace secret-looking substrings (e.g. bearer tokens) of a string value. */
    public function maskString(string $value): string
    {
        foreach ($this->valuePatterns as $pattern) {
            $replaced = @preg_replace($pattern, $this->mask, $value);
            if ($replaced !== null) {
                $value = $replaced;
            }
        }

        return $value;
    }

    public function hasValuePatterns(): bool
    {
        return $this->valuePatterns !== [];
    }

    /** @return list<string> */
    public static function words(string $key): array
    {
        $key = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $key) ?? $key;
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    private function compute(string $key): bool
    {
        foreach ($this->regexEntries as $regex) {
            if (@preg_match($regex, $key) === 1) {
                return true;
            }
        }

        $words = self::words($key);
        if ($words === []) {
            return false;
        }
        // naive plural handling: "tokens" -> "token"
        $singular = array_map(static fn (string $w): string => strlen($w) > 3 && str_ends_with($w, 's') ? substr($w, 0, -1) : $w, $words);

        foreach ($this->wordEntries as $entry) {
            if (self::containsSequence($words, $entry) || self::containsSequence($singular, $entry)) {
                return true;
            }
        }

        $squashed = implode('', $words);
        foreach ($this->substringEntries as $needle) {
            if (str_contains($squashed, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private static function containsSequence(array $haystack, array $needle): bool
    {
        $n = count($needle);
        $max = count($haystack) - $n;
        for ($i = 0; $i <= $max; $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }
}
