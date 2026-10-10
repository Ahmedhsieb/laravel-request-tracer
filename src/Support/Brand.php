<?php

declare(strict_types=1);

namespace RequestTracer\Support;

/**
 * Single source of truth for the package's name and brand.
 *
 * Renaming the package means: change composer.json ("name"), the `RequestTracer\` namespace
 * (find-and-replace), and the constants below. Nothing else hard-codes the brand.
 */
final class Brand
{
    /** Composer vendor (see composer.json). */
    public const VENDOR = 'your-vendor';

    /** Used for the config file, route prefix, view namespace, cache keys, log channel and command prefix. */
    public const NAME = 'request-tracer';

    /** Human readable name shown in the dashboard. */
    public const TITLE = 'Request Tracer';

    /** Header that carries the signed recording token. */
    public const HEADER = 'X-Request-Tracer';

    /** Authorization gate that protects the dashboard and API. */
    public const GATE = 'viewRequestTracer';

    /** Name of the log channel internal failures are written to. */
    public const LOG_CHANNEL = 'request-tracer';

    /** Directory (inside storage/framework) for generated code. */
    public const STORAGE_DIR = 'request-tracer';

    /** Placeholder that replaces masked values. */
    public const MASK = '••••••••';

    /** Container alias of the Tracer singleton. */
    public const CONTAINER_KEY = 'request-tracer';
}
