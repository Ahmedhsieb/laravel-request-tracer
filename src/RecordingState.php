<?php

declare(strict_types=1);

namespace RequestTracer;

enum RecordingState: string
{
    /** Not recording; collectors see `Tracer::$on === false`. */
    case Off = 'off';

    /** Recording into memory; kept only if the request is later confirmed (watch-list user / sampled + authenticated). */
    case Tentative = 'tentative';

    /** Recording; will be stored at terminate(). */
    case On = 'on';
}
