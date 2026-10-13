<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer;

enum SpanFlag: string
{
    case Slow = 'slow';
    case Repeated = 'repeated';
    case Caught = 'caught';
    case Failed = 'failed';
}
