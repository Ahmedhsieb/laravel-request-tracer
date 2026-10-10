<?php

declare(strict_types=1);

namespace RequestTracer\Attributes;

use Attribute;

/**
 * Trace this class or method.
 *
 *  - On a method: its calls become spans even when its class is outside the configured proxy namespaces
 *    (the class is proxied when it is resolved from the container).
 *  - On a class: every public method is traced.
 *  - `deep: true` additionally compiles the class for line-by-line Deep trace.
 *  - `label` replaces the default "Class::method" label.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Trace
{
    public function __construct(
        public readonly ?string $label = null,
        public readonly bool $deep = false,
    ) {}
}
