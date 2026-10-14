<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Dumper;

final class DumpLimits
{
    public function __construct(
        public readonly int $maxDepth = 3,
        public readonly int $maxItems = 50,
        public readonly int $maxString = 2048,
        public readonly int $maxSpanBytes = 65536,
        public readonly int $memoryCeiling = 16_777_216,
        /** no caps at all (`unlimited`): the memory guard is the only limit */
        public readonly bool $unlimited = false,
    ) {}

    /** @param  array<string, mixed>  $config  the `dumper` section of the config */
    public static function fromConfig(array $config, bool $unlimited = false): self
    {
        if ($unlimited) {
            return new self(256, PHP_INT_MAX, -1, PHP_INT_MAX, 0, true);
        }

        return new self(
            max(0, (int) ($config['max_depth'] ?? 3)),
            max(1, (int) ($config['max_items'] ?? 50)),
            max(16, (int) ($config['max_string'] ?? 2048)),
            max(1024, (int) ($config['max_span_bytes'] ?? 65536)),
            max(0, (int) ($config['memory_ceiling'] ?? 16_777_216)),
        );
    }
}
