<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Support;

/**
 * Lays the application's `config/request-tracer.php` over the package defaults, so a config file only has to contain what it changes.
 *
 * Laravel's own `mergeConfigFrom()` merges the first level only: a file that says `'exclude' => ['paths' => ['admin/*']]` would
 * replace the whole `exclude` section and silently drop the defaults (login, webhooks, the queue worker...). This merges section by
 * section instead:
 *
 *  - a section (an associative array) is merged key by key, down to the last level;
 *  - a list replaces the default list (`code.paths`: `['modules/Billing']` means "only that");
 *  - except the lists named in ADDITIVE, which *add* to the defaults (`exclude.paths`, `masking.keys`...). A list entry that starts with
 *    `!` takes a default away again: `'paths' => ['admin/*', '!login']` also traces the login page.
 */
final class ConfigMerger
{
    /** Lists a config file adds to. Everything else that is a list is replaced. */
    private const ADDITIVE = [
        'exclude.paths', 'exclude.routes', 'exclude.commands', 'exclude.jobs',
        'masking.keys', 'masking.value_patterns',
        'capture.ignore_events', 'capture.request_headers', 'capture.response_headers',
        'problems.ignore_exceptions',
    ];

    /**
     * A config file written for the package before its namespace was `Ahmedhsieb\RequestTracer\` names classes as `RequestTracer\...`
     * (`RequestTracer\Http\Middleware\Authorize`, a custom collector): read those as the classes they became.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    public static function modernize(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = self::modernize($value);
            } elseif (is_string($value) && preg_match('/^\\\\?RequestTracer\\\\/', $value) === 1) {
                $config[$key] = 'Ahmedhsieb\\'.ltrim($value, '\\');
            }
        }

        return $config;
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $user
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $user, string $path = ''): array
    {
        $merged = $defaults;
        foreach ($user as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;
            $default = $defaults[$key] ?? null;

            if (! is_array($value) || ! is_array($default)) {
                $merged[$key] = $value;                               // a scalar, or something with no default to merge with

                continue;
            }
            if (! array_is_list($default)) {
                // a section: `[]` means "nothing set", anything else is merged key by key
                $merged[$key] = $value === [] ? $default : (array_is_list($value) ? $value : self::merge($default, $value, $here));

                continue;
            }
            $merged[$key] = in_array($here, self::ADDITIVE, true) && array_is_list($value) ? self::add($default, $value) : $value;
        }

        return $merged;
    }

    /**
     * @param  array<int, mixed>  $default
     * @param  array<int, mixed>  $user
     * @return array<int, mixed>
     */
    private static function add(array $default, array $user): array
    {
        $take = [];
        $add = [];
        foreach ($user as $entry) {
            if (is_string($entry) && str_starts_with($entry, '!')) {
                $take[] = substr($entry, 1);
            } else {
                $add[] = $entry;
            }
        }
        $kept = array_filter($default, static fn (mixed $entry): bool => ! (is_string($entry) && in_array($entry, $take, true)));

        return array_values(array_unique(array_merge($kept, $add), SORT_REGULAR));
    }
}
