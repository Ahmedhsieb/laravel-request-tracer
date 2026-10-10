<?php

declare(strict_types=1);

namespace RequestTracer;

enum SpanType: string
{
    case Request = 'request';
    case Command = 'command';
    case Middleware = 'middleware';
    case Controller = 'controller';
    case Validation = 'validation';
    case Service = 'service';
    case Query = 'query';
    case Model = 'model';
    case Http = 'http';
    case Event = 'event';
    case Job = 'job';
    case Exception = 'exception';
    case Cache = 'cache';
    case Log = 'log';

    /** Types that can never have children; identical siblings of these collapse without needing a ghost span. */
    public function isLeaf(): bool
    {
        return match ($this) {
            self::Query, self::Model, self::Exception, self::Cache, self::Log, self::Event => true,
            default => false,
        };
    }
}
