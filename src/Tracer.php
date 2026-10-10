<?php

declare(strict_types=1);

namespace RequestTracer;

/**
 * Entry point of the package. (Scaffold: grows in phase 1.)
 */
final class Tracer
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config = []) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function recording(): bool
    {
        return false;
    }

    public static function helper(mixed $value, ?string $label = null): mixed
    {
        return $value;
    }
}
