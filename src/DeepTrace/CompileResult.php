<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

final class CompileResult
{
    /**
     * @param  list<array{k: string, l: int, s: string, n: string|null, f: array{0: string, 1: int, 2: int}|null}>  $probes  index = local probe number
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $code,
        public readonly array $probes,
        public readonly int $fileKey,
        public readonly array $warnings = [],
    ) {}
}
