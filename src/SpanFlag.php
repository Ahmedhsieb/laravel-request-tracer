<?php

declare(strict_types=1);

namespace RequestTracer;

enum SpanFlag: string
{
    case Slow = 'slow';
    case Repeated = 'repeated';
    case Caught = 'caught';
    case Failed = 'failed';
}
