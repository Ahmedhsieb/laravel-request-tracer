<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\DeepTrace;

/** The kinds stored in `tracer_lines.kind`. */
final class ProbeKind
{
    public const COND = 'cond';

    public const BLOCK = 'block';

    public const ASSIGN = 'assign';

    public const LOOP = 'loop';

    public const RETURN = 'return';

    public const THROW = 'throw';
}
