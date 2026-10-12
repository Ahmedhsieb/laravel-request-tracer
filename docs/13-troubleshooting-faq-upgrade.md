# 13. Troubleshooting, FAQ, upgrading, contributing

## Troubleshooting

### The dashboard says 403

Outside the `local` environment nobody may open it until you define the gate (`viewRequestTracer` by default, `dashboard.gate`). See [Installation](02-installation.md#use). In `local`, the gate is opened for you only when your application has not defined one; if you defined it, it applies.

### The dashboard is empty

Work through this list; the first "no" is the answer.

1. Is `php artisan request-tracer:listen` running? The dashboard header says **Listening** (green) or **Not listening** (orange). Nothing is recorded while it is not, in any environment. (Or set `REQUEST_TRACER_MODE=always` to record without the command, locally.)
2. Is the listen command on the same machine as the web server? The heartbeat is a file in `storage/framework/request-tracer`: a command running on your laptop does not switch on a server.
3. Is the tracer on? `php artisan tinker --execute="dump(config('request-tracer.enabled'), config('request-tracer.mode'));"` (`REQUEST_TRACER_ENABLED`, `REQUEST_TRACER_MODE`).
4. Does the request path match `exclude.paths` (login, webhooks, health checks, the dashboard itself) or `exclude.routes`? Is `scope.paths` set and not matching? (A replay ignores these.)
5. Look at `storage/logs/request-tracer.log`: a trace that could not be stored (a database that cannot be written, a full disk) is logged there, never shown.
6. Is another middleware answering before the tracer's? The package prepends its middleware to the global stack; a custom kernel that replaces the stack must keep it.
7. With `storage.queue.enabled`, is a queue worker running?

### My signed header is ignored

Invalid tokens are ignored **silently**, on purpose. Check: the token was issued by the same application key; it has not expired (maximum one hour); if it was issued for a user or a route, the request matches; the header name is `triggers.header`; and a proxy or CDN is not dropping it. The reason is logged at debug level on the `request-tracer` channel.

### I see the request, but not my services

Is the file inside `code.paths` (default: `app`) and not named in `code.except`? Models, controllers, commands, service providers, form requests, jobs and exceptions get no container proxy (the framework builds them itself) but are traced line by line. Otherwise remember that a proxy can only see calls on an object resolved from the container. Objects created with `new`, static calls, private methods and final classes are not visible; the Problems tab lists the "not traceable" ones it noticed. You can always wrap a value with `trace($value, 'label')`.

### "Inside the code" is empty or says the step was not traced

The file has to be compiled before it is loaded, which happens for files in `code.paths` while somebody listens. Common reasons it does not apply: nobody was listening when the class was first loaded in a long-running worker (Octane, `queue:work`: restart it), the class was loaded earlier in the same process (a service provider or a config file referenced it before the package's autoloader ran, or `opcache.preload`), `code.line_by_line` or `deep_trace.enabled` is `false`, the file is outside `code.paths` or named in `code.except`, or it is in `vendor/`. For a file outside the folders, press *Trace this file line by line* and send the request again. A file that cannot be compiled is used unchanged and reported in the Problems tab.

### Lines show up as "not run" that did

"Not run" is only shown on lines that carry a probe, and the data is limited by `deep_trace.max_hits`. If the trace says it was truncated, later parts of the request are not in it. Raise `max_hits` or narrow the classes.

### Everything is slow

Check what is on: a listen command somebody left running, `code.paths` that cover very hot code (put it in `code.except`), or `mode = always` somewhere that is not local. Compare with [benchmarks](benchmarks.md). Lower `limits.max_spans` or add `#[NoTrace]` to the hot classes.

### The database is growing

`request-tracer:prune` runs hourly through the scheduler (`php artisan schedule:run` must be running, as for any scheduled command). Check `retention.hours` and `retention.max_rows`, or run `php artisan request-tracer:prune --hours=6` by hand.

## FAQ

**Is it safe in production?** That is the design goal: nothing is recorded unless asked for, masking happens before storage, the dashboard is closed until you open the gate, and tracer failures never reach the request. Read [Running in production](07-running-in-production.md) and [SECURITY.md](../SECURITY.md) before you rely on it.

**Does it work with Octane?** Yes. State lives in one object per request that is dropped at the end; the repeated-request tests assert that nothing is left behind.

**Does it work with Inertia / Livewire / API-only apps?** Anything that goes through the HTTP kernel is a request. Livewire's own endpoints (`livewire/*`) are excluded by default; remove the pattern from `exclude.paths` if you want them.

**Can I trace a queued job?** Yes, see [queues, commands and scheduled tasks](10-queues-commands-scheduled-tasks.md). A job is linked to the request that dispatched it.

**Does Deep trace modify my files?** No. It compiles a copy into `storage/framework/request-tracer/compiled` and the autoloader serves that copy while Deep trace is on for the class. Line numbers are identical to the original by construction, and the compiled copy is verified (same line count, still parses) before it is used.

**What does it store about the user?** The user id, a keyed hash of the IP address, and whatever you put in the tenant and module columns. Request headers are an allowlist; `Authorization` and `Cookie` are never stored.

**Can I go back to a request nobody traced, when I do not know what it looked like?** Only if something noted it when it happened. Turn the [request journal](09-using-the-dashboard.md#the-journal) on (`REQUEST_TRACER_JOURNAL=true`): it keeps one light row per request for an hour, and any row can be replayed with a full trace. Without it there is nothing to go back to: a request's body and headers are not kept anywhere.

**Can I delete a trace?** Yes: `DELETE /request-tracer/api/v1/traces/{id}` (the API is behind the same gate), or `php artisan request-tracer:prune --hours=0` to remove everything.

**The dashboard is slow, or the search and the filters seem to do nothing.** Each call of the dashboard's API runs the middleware of your `web` group. Since 0.3.1 the default is a light stack (cookies, session, CSRF and the gate), but a `config/request-tracer.php` published by an older version still says `'middleware' => ['web', Authorize::class]`: change that line to `'middleware' => null`. Also check that the dashboard you see is the installed one (the *Journal* tab is in 0.3.0 and later): a copy in `public/vendor/request-tracer` that is older than the package is ignored since 0.3.1, and before that it must be refreshed with `php artisan vendor:publish --tag=request-tracer-assets --force`.

**I select the controller and its code is not there, only other functions.** The controller's file is not under `request-tracer.code.paths` (default `['app']`): code in a package or module folder (`packages/...`, `Modules/...`) is only traced line by line when its folder is listed. Since 0.5.1 the code view shows the text of the action anyway and tells you which folder to add; add it, restart `request-tracer:listen` is not needed, and send the request again.

**A controller action says "did not run".** The step started (the form request was resolved) but no line of the action was recorded because the request never reached it: a validation or an authorization stopped it, and Laravel answered with a redirect (`302`) or `422`. Look at the *validation* step in the flow and the Problems tab. When you replay a request whose body was not recorded, the validation fails the same way: describe the body in the replay form.

**Does a replay change my data?** Your choice, every time. A *simulation* (the default) rolls the database changes back and keeps e-mails, notifications, jobs and the HTTP client from going out; it cannot control files or an SDK that calls a service by itself. *Run for real* keeps the changes and records every Eloquent write so that *Undo these changes* (or `request-tracer:undo <trace>`) can take them back for 24 hours; writes made with the query builder or raw SQL, e-mails, calls and files cannot be undone, and the banner on the trace lists them. See [Replay a request](09-using-the-dashboard.md#replay-a-request-that-already-happened).

**Why `0.x`?** The stored format and the API may still change between minor versions. The changelog says so when it happens.

## Upgrading

The package follows [semantic versioning](https://semver.org). While it is `0.x`, treat a minor version (`0.2` → `0.3`) as potentially breaking and read the changelog first.

1. `composer update your-vendor/laravel-request-tracer`
2. `php artisan vendor:publish --tag=request-tracer-assets --force` (the dashboard is part of the package; this refreshes a published copy)
3. If the changelog mentions a migration, publish and run it: `php artisan vendor:publish --tag=request-tracer-migrations` and `php artisan migrate`
4. Compare your `config/request-tracer.php` with the new default (every key is in [the reference](04-configuration.md)); new keys fall back to their defaults if they are missing. A key that your published file spells out keeps its old value, for example `dashboard.middleware` (see the FAQ above).
5. Clear generated code if something odd shows up after an upgrade: delete `storage/framework/request-tracer`. It is rebuilt on demand, and the caches are keyed by package version so this is rarely needed.
6. Restart long-running workers (Octane, Horizon, `queue:work`).

## Contributing

Bug reports and pull requests are welcome. Please open an issue first for anything bigger than a fix.

```bash
git clone … && cd laravel-request-tracer
composer install
npm ci

vendor/bin/pest                          # PHP tests (SQLite in memory)
vendor/bin/pint --test                   # code style
vendor/bin/phpstan analyse               # static analysis, level 8
npm run lint && npm run typecheck && npm test
npm run build                            # rebuilds dist/ — commit the result
npm run dev                              # dashboard with demo data, no backend needed
```

- **Other Laravel versions:** `composer require "laravel/framework:10.*" "orchestra/testbench:^8.0" --no-update && composer update --prefer-lowest`.
- **Other databases:** `TRACER_TEST_DB=mysql|pgsql|sqlite` with `TRACER_TEST_DB_HOST`, `_USERNAME`, `_PASSWORD`, `_DATABASE`.
- **`dist/` is committed** and CI fails when it differs from a fresh build, so run `npm run build` whenever you change `resources/js`.
- **PHP 8.1 syntax only** in `src/` (no readonly classes, typed class constants, `#[\Override]`, `json_validate`). CI lints it on 8.1.
- Every behaviour change comes with a test. Decisions that are not obvious from the code go into [docs/decisions.md](decisions.md); every new config key goes into [the reference](04-configuration.md).
- **Never push a closure into the config file**: `config:cache` cannot serialise it.

## Changelog

See [CHANGELOG.md](../CHANGELOG.md).
