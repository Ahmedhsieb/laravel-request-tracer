<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Analysis;

/** One exception seen during a trace. `uncaught` means the response was built from it; `ignore` means that is normal control flow (404, 422, ...). */
final class ExceptionRecord
{
    public function __construct(
        public readonly string $class,
        public readonly string $message,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly int $span,
        public bool $uncaught = false,
        public bool $ignore = false,
    ) {}
}
