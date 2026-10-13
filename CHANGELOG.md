# Changelog

All notable changes are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.8.0] - 2026-10-13

**The flow is made of folders.** In a deep request the names of the steps disappeared: every level took a piece of the name column. A step with steps inside is now a folder and the flow shows one folder at a time, so a name always has the whole column.

- **Folders**: open one with the button on its row (it counts what is inside), double-click or the Right arrow; Up, Left or Backspace goes back; a path above the list (long paths fold their middle behind `…`) jumps to any level. The time axis follows: an open folder shows its own stretch of time. The first folder is the one of the controller.
- **Filters work inside the open folder, on everything below it** (a switch, *Filters → Look in*, narrows them to the folder's own steps). Matches are listed flat with the folders between the open one and each match; the kinds of step count what is inside the open folder.
- **A Graph view** next to the Waterfall (remembered per browser): the open folder and its steps as nodes with their full names, lines as thick as the share of time, and a third column for the pinned folder. The two views share the folder, the filters and the pinned step.
- The name column is wider, and the "show in the flow" links, the problems and the command palette open the right folder and flash the step.

## [0.7.0] - 2026-10-13

**A new dashboard.** Same data, same API, same store and the same logic (`store.ts`, `lib/*`); the whole interface on top of them is new, and built on ready libraries (Reka UI, motion-v, Tailwind 4, lucide, vue-sonner, floating-ui, VueUse) instead of hand-made widgets.

- **Waterfall flow**: every step is a bar at its real start and length on a time ruler, with a crosshair that tells the time, virtual rows for thousands of steps, and filters (type, speed, result) in a popover.
- **Time map**: a small overview of the whole request; drag over it to zoom the waterfall to that span.
- **Ctrl+K palette**: actions, requests and the steps of the open request, reached by typing.
- **Time-by-type ribbon** that doubles as the type filter, count-up metric tiles, a *pulse* strip over the list (one column per request), a sliding selection marker.
- **Resizable panes** (remembered), dark and light themes (dark by default, remembered, no flash on load), toasts, animated dialogs; replay's choice (simulation or real) is a pair of cards and a sticky replay bar sits over the code.
- The line pop-up uses floating-ui and stays on screen; the layout stacks below 1180 px and shows one pane at a time below 900 px. Animations stop for `prefers-reduced-motion`.
- The fonts (Inter, JetBrains Mono) ship inside `dist/`: no request leaves the page. `dist/app.js` is 668 KB (215 KB gzip), more than before, in exchange for the libraries.

## [0.6.0] - 2026-10-13

**The package is now `ahmedhsieb/laravel-request-tracer` and its classes live in `Ahmedhsieb\RequestTracer\`** (it was `your-vendor/laravel-request-tracer` and `RequestTracer\`, placeholders). Nothing else changes: the config file, the `request-tracer:*` commands, the routes, the tables, the header and the dashboard keep their names.

To upgrade an application:

1. In `composer.json` replace `your-vendor/laravel-request-tracer` with `ahmedhsieb/laravel-request-tracer` (and the `repositories` entry if you use a path), then `composer update ahmedhsieb/laravel-request-tracer`.
2. Anywhere your own code names a class of the package, use the new namespace: `Ahmedhsieb\RequestTracer\Facades\Tracer`, `Ahmedhsieb\RequestTracer\Attributes\Trace`, a custom collector's `Collector` interface. The `Tracer` alias is unchanged.
3. A published `config/request-tracer.php` that names classes of the package (`RequestTracer\Http\Middleware\Authorize`, a custom collector) keeps working: such names are read as the classes they became. Change them when you next edit the file.
4. Generated code is rebuilt on its own (the caches are keyed by package version); delete `storage/framework/request-tracer` if something odd shows up, and run `php artisan package:discover` if the application caches its providers.

- The configuration reads class names of the old namespace as the new ones (`ConfigMerger::modernize()`).

## [0.5.5] - 2026-10-13

- **The journal no longer forgets the pages you loaded.** The list refreshed itself every few seconds by fetching the first page and *replacing* the list with it, so what *Load more* had added disappeared and the list jumped back to the first 50. The refresh now only puts requests noted since the last look on top and leaves everything that is shown as it is; *Load more* cannot start twice (the button shows *Loading…*), skips an entry that is already listed, and a page that was still on its way when the search or the filter changed is dropped instead of being mixed into the new list.

## [0.5.4] - 2026-10-12

- **A function with no run data says why.** The code view called every function without lines *not traced line by line*, but there are three different reasons and only one is a setting: the folder is outside `code.paths` (*not traced line by line*, with the line to add); other functions of the step were recorded but not this one, so it **did not run** (a form request failed its validation and Laravel redirected, an authorization refused: the tag says *did not run* and the notice points at the validation step); or nothing at all was recorded for the step (the request was not recorded with line-by-line tracing: the notice offers *Trace this file line by line*, which the static-text fallback of 0.5.1 had hidden).

## [0.5.3] - 2026-10-12

- Tests: the undo logs of the tests have their own folder per test process (`replay.undo_path`, a new setting that also lets an application keep them elsewhere), as do the files some tests create, so test runs that overlap no longer delete each other's files; a timing assertion that failed under load on Windows is looser.
- **The journal groups by route too.** The *Group by route* button, the number of segments and *Close all* / *Open all* are in the Journal tab as well, with the same prefix rule as the request list (the URL without host, query string and fragment, up to the first record id). A group header shows how many requests it holds, how many failed (a 5xx or an exception) and the slowest one. The setting is the same one for both lists; the groups you closed are remembered separately for each.

## [0.5.2] - 2026-10-12

- **A line's output sits on its own line even when the file was edited after the request.** The recorded lines are numbers, and a file that gets a line added above a function after the request was traced has its code one line lower than the numbers say: every chip, dot and pop-up then appeared on the line above its statement (`array (2)` on a blank line, the next statement's value on the previous one). The server now follows the edit: every probe remembers the text of its statement, so it finds where the statements of each function are now (the nearest line that holds the text, the most common answer wins, up to 80 lines either way), moves the function and its rows there and reports `shift`. Functions in one file can move differently (code was added in one place, another was removed). The header says *edited since: moved +1 lines*; when no statement could be matched it still says *line numbers may not match*. On a real project 318 of 318 rows landed on their statement.

## [0.5.1] - 2026-10-12

- **A step whose code is outside `code.paths` says so, and still shows its code.** A controller in `packages/Accounting/src/Controllers` with the default `code.paths = ['app']` has no line data, and the code view listed only unrelated functions of `app/` that ran under it (a model's `booted()`, a navigation helper) as if they were its code. Now the step's own function is shown from its text (marked *not traced line by line*, no run dots), a notice names the file, the current `code.paths` and the line to add (`'paths' => ['app', 'packages/Accounting/src']`), and the calls written in it still link to the functions and steps that ran. The server reads only PHP files inside the application for this.

## [0.5.0] - 2026-10-12

A dashboard that stays put and shows the code that matters.

- **The page no longer scrolls.** The request list and the request details each scroll inside their own box; the steps and the details of a step are two boxes of their own. The figures above the flow fold away (*Hide summary*) and the flow takes the room. On a narrow window the two columns stack and the pane scrolls as a whole.
- **Group the request list by route** (*Group by route*): `/api/payments` with its count, problems and slowest request; click to close or open a group, choose how many segments make the prefix. Remembered in the browser.
- **Filters on the steps of a request**: toggles for the kinds of step (several at once, with counts), *Slow* / *Fast* and *Failed* / *OK*, combined with the search; matches keep their ancestors, dimmed. New setting `dashboard.slow_step_ms` (100).
- **A controller's action is one step, not two.** Since Laravel 11 the base controller extends nothing, so controllers were proxied like services and the action showed up twice (the `controller` step and a `service` step below it), with the lines of the action stored on the copy: clicking the controller showed its constructor and nothing of the action. The copy is folded into the controller step, which keeps the arguments. Traces recorded before this still have the copy; the code view finds it and shows it as the code of the step.
- **The code view shows the right code.** The server listed the functions of a step sorted by file name and cut the list at 20, so a base controller's constructor came before the action and could push it out. The step's own function now comes first, the rest in the order they ran, up to 40.
- **Code that is called from the code you read**: the functions that ran and are called by name from the open function appear under the calling line (closed, with *called from*), recursively; a service or job called from there is offered as a step of its own and loaded when opened. Functions that were not called from it go under *Other code that ran during this step*, closed. Every function folds.
- **The output of a line is a pop-up beside the code** (hover to preview, click to keep it open, Esc or ✕ to close) instead of a card below that scrolls out of view.
- **A controller that returns a view shows what it passed to the view** (`compact('voucher')`...) as its output, with the view's name in the summary, instead of the rendered HTML.

## [0.4.0] - 2026-10-11

- **A replay is a choice: a simulation, or for real with an undo.** The replay dialog (and `request-tracer:replay`, and `POST replay` with `mode`) now asks what the request may change.
  - **Simulation** (the default) changes nothing: database changes are rolled back, and on top of what 0.2 did (mail to the `array` mailer, jobs and broadcasts dropped) notifications are dropped, calls made with Laravel's HTTP client are answered locally with an empty `200`, and the cache and the session stay in memory. What no simulation can control is said in the docs: files, a Guzzle or curl call made directly, an SDK, tables without transactions, DDL.
  - **Run for real** (`--real`, a confirmation in the dashboard and in the API) lets the request do what it does. Meanwhile every Eloquent write is recorded exactly in an **undo log** (insert: the row; update: the changed columns before and after; delete: the whole row; soft deletes included), separate from the trace, whose values are masked and cut. The log is encrypted with the application key, never shown and deleted when undone, after `replay.undo_hours` (24) and by `request-tracer:prune`.
  - **Undo**: a banner on the trace lists what can be taken back and what cannot (writes made with the query builder or raw SQL, by table; e-mails, jobs, files and calls to other services). *Undo these changes* (`request-tracer:undo <trace>`, `POST traces/{id}/undo`) takes the writes back, last first, in one transaction per connection: all or nothing. A row that changed after the replay is not touched; the conflicts are listed and *Undo anyway* (`--force`) is the explicit way.
- New config: `replay.real` (`null` = wherever replay is allowed except production; production needs `real => true` as well as `enabled => true`) and `replay.undo_hours`. New endpoints `GET|POST traces/{id}/undo`; `meta.replay` gains `real` and `undo_hours`.

## [0.3.1] - 2026-10-11

- **The dashboard no longer pays for the application's own middleware.** Its default stack is now `LightWeb`: only the part of the application's `web` group that the dashboard needs (cookie encryption, session, CSRF, including the application's own subclasses of those), plus the gate. In a real project the whole `web` group (permission sync, locale, "last seen", debug bars) made every call of the dashboard's API take 2.9 s, against 12 ms with the light stack, and the application's side effects ran on every poll. `dashboard.middleware` is `null` by default; `['web', Authorize::class]` gives the old behaviour. **A config file published by an earlier version still lists `['web', Authorize::class]`: set it to `null` to get the light stack.**
- **A stale published copy of the dashboard no longer hides an upgrade.** `public/vendor/request-tracer` is used only while it is at least as new as the package's own `dist/`; after `composer update` the new dashboard is served even if `vendor:publish --tag=request-tracer-assets` was run long ago.
- Dashboard: the *Traces* / *Journal* tabs are always there (the *Journal* tab says how to switch the journal on), the list is dimmed with a "Loading…" line while a search or filter is running, and a failed request is shown instead of leaving the old list as if nothing happened.

## [0.3.0] - 2026-10-11

- **The request journal** (opt-in: `journal.enabled` / `REQUEST_TRACER_JOURNAL=true`): one light row per request (method, URL, allow-listed headers, masked body, user, status, duration, the exception), so a request that nobody traced can still be found and **replayed with a full trace**. Dashboard: a *Journal* tab next to *Traces* with *Failed* and search filters; clicking a row opens the replay form filled in (a *Trace* button when the request was traced as well). Terminal: `request-tracer:replay --journal=failed|last|<id>`. API: `GET journal`, `GET journal/{id}`, `DELETE journal`.
- Secrets are masked before the journal stores anything (by key name and value pattern; `Authorization` and cookies are never kept), so a replay of such a request carries the mask, and the entry and the form say so. A body larger than `journal.body_max_bytes` is not kept.
- It trims itself (about every hundredth write) and `request-tracer:prune` trims it; its table is created the first time it is needed. A replay is never noted, nor is the dashboard, an excluded path or an excluded route.
- Measured cost of the journal: one `INSERT` per request after the response was sent, 2.1 ms on MySQL and 5.4 ms on a SQLite file (Windows laptop).

## [0.2.1] - 2026-10-11

- **A config file only has to hold what it changes, for real.** Laravel's `mergeConfigFrom()` merges the first level only, so `'exclude' => ['paths' => ['admin/*']]` replaced the whole `exclude` section and silently dropped the built-in exclusions (login, webhooks, the queue worker, the tracer's own commands), and `'masking' => ['keys' => ['ssn']]` dropped the default masking keys. The package now merges section by section (`ConfigMerger`): sections merge key by key, `code.paths` and other "what to look at" lists replace the default, and the lists of things to exclude or mask (`exclude.*`, `masking.keys`, `masking.value_patterns`, `capture.*` allowlists, `problems.ignore_exceptions`) **add** to the defaults; `'!login'` takes a default away.
- The dashboard middleware always includes the gate, even when a config lists its own `dashboard.middleware`.
- Docs: how to leave a URL prefix or a route name out of the traces.

## [0.2.0] - 2026-10-11

Simpler to install, run and point. Changes behaviour: nothing records on its own any more (see below), and the configuration moved to `code.paths` / `code.except` for what is traced.

### Simpler to install, run and point
- **Two steps**: `composer require` and `php artisan request-tracer:listen`. No config file, no migration, nothing to publish: the tables create themselves the first time they are needed (the shipped migration is idempotent, so `php artisan migrate` and a published copy still work), the dashboard is served from the package, and every key has a default.
- **`request-tracer:listen`** works like `queue:work`: while it runs, requests, queued jobs, artisan commands and scheduled tasks are traced, and each trace is printed in the terminal as it is stored. Stop it and recording stops within seconds. It works through a heartbeat file that every PHP process on the machine checks with one `stat()`. The dashboard header shows *Listening* / *Not listening*, with the command to copy.
- **Nothing records on its own, local included** (changed: the `local` environment used to record every request). `triggered` is the default everywhere; `always` is an explicit opt-in.
- **The scope is folders**: `code.paths` (default `app`) and `code.except`. Everything inside is traced, with input and output and line by line, and nothing is listed class by class. Files and class patterns work too. Compiled copies are only used while somebody listens, so a request nobody traces runs your original files. Container proxies skip what the framework builds or calls itself (models, controllers, commands, providers, form requests, jobs, exceptions, kernels), which are still traced line by line. `proxies.namespaces` and `deep_trace.namespaces` remain as extras.
- **Replay** (`request-tracer:replay` and the dashboard's *Replay a request* / *Replay this request*): run a request that already happened again, traced, from a pasted cURL command, a described request or a recorded trace. It runs in a process of its own through the application's HTTP kernel, as the user you name, with a CSRF token; database changes are rolled back, mail and queued jobs go nowhere. Off in the `production` environment unless `replay.enabled` is `true`.
- Docs, README and the dashboard's empty states describe the two-step flow.


## [0.1.0] - 2026-10-11

First release. Everything below shipped together; the phases are the order the work was done in.

### Phase 0 – scaffold
- Package skeleton: `composer.json` (PHP `^8.1`, `illuminate/*` `^10 | ^11 | ^12 | ^13`, `nikic/php-parser ^4.18 | ^5.0`), service provider, `Brand`, facade, `trace()` helper, full config file.
- Tooling: Testbench + Pest, PHPStan level 8 (Larastan), Pint, `.dev/` helper scripts for running every local PHP version.
- CI: Laravel 10/11/12/13 × PHP matrix with `prefer-lowest`/`prefer-stable`, DB jobs (MySQL, PostgreSQL, SQLite), PHP 8.1 syntax guard, dist freshness job.
- `docs/decisions.md`, `CLAUDE.md`.

### Phase 1 – recorder, dumper, storage
- `Span`, `Recorder` (parent/child tree, span cap, collapsing of identical siblings into aggregate spans, ghost spans), `Clock` that excludes tracer overhead.
- Value dumper on Symfony `VarCloner` with casters for models, collections, exceptions, requests and responses; depth/items/string/span/trace limits; word-based key masking and value patterns.
- `TraceStorage` interface and `DatabaseStorage` (separate connection outside the app transaction, chunked inserts, gzip+base64 payloads, cursor pagination, filters, pruning); migration for the five tables.
- `Tracer`, `TraceSession`, global `RecordRequest` middleware (records in `handle`, persists in `terminate`), `RecordingDecider` (`always` / `triggered` / `off`, excluded paths), queued persistence job.
- First end-to-end recorded trace; masking proven against every stored table.

### Phase 2 – framework collectors, table writes, problems
- Collectors: request/route/controller span, validation, queries (calling file:line, masked bindings), transactions, Eloquent models (before/after), logs, exceptions (incl. caught), HTTP client, cache, events (with listener names), dispatched jobs (+ payload stamp).
- SQL analyser for MySQL, PostgreSQL, SQLite and SQL Server: statement kind, written and read tables (CTEs, multi-table UPDATE/DELETE, MERGE, upserts, quoted and schema-qualified names), fingerprints, binding→column naming. 109 dataset tests.
- Write tracking: model events claim the query write they caused; transaction state (`none`/`committed`/`rolled_back`) per write.
- Problem detector: caught/uncaught exceptions, N+1, repeated queries and writes, slow queries, slow steps (self time), failed HTTP calls, truncation.
- Example "Order flow" app under `tests/Fixtures` and 18 collector tests; suite verified on Laravel 10 (PHP 8.1), 11 (8.3), 12 (8.4), 13 (8.4).

### Phase 3 – proxies, attributes, helper
- Container proxies: reflection-exact subclass generator (by-ref, variadic, union/intersection/nullable types, `self`/`static`/`void`/`never`, constant/enum/array defaults, attributes), on-disk cache with a dependency manifest, `beforeResolving` hook that keeps singleton and contextual bindings, middleware spans, "not traceable" notes for final classes/methods.
- `#[Trace(label:, deep:)]` and `#[NoTrace]`; `Hot::$on` single static flag for proxies, probes and `trace()`.
- Exceptions thrown through traced spans are recorded once per instance and the spans are flagged `failed` (ended the request) or `caught`.
- 17 generator edge-case tests and 15 integration tests (tree shape, masking, contextual bindings, singletons, attributes, state not leaking between requests).

### Phase 3b – Deep trace
- Compiler: php-parser 4.18+/5.x, **insertion-only** text edits at AST positions (no pretty printing), brace normalisation for brace-less bodies, `__FILE__`/`__DIR__` rewritten; verified by a line guard (same lines, every token on its line, still parses).
- Probes: conditions (with comparison operands), short-circuit parts, blocks, `catch` (exception received), loops (iterations + snapshots), assignments (RHS wrap, no property reads), arguments, returns, throws, `match`/`switch` subjects and arms, plain statements ("this line ran").
- Loader: prepended autoloader with mtime/version cache and negative cache, fallback to the original with a dashboard warning, "trace this file" queue, optional `#[Trace(deep: true)]` scan.
- Probe hits aggregate per (span, probe); `max_hits` cap marks the trace truncated; names of secrets mask values and snippet literals.
- Corpus tests: control flow (incl. alt syntax, brace-less, nested else-if), strict types, namespaces, heredoc/nowdoc, attributes, enums, readonly, first-class callables, named args, match, constant-expression contexts, inline HTML, by-reference returns, magic constants.
- End-to-end test for `POST /api/orders` including the Deep trace of every condition in `OrderService::create()`.

### Phase 4 – API, gate, triggers, prune
- Gate `viewRequestTracer` (Horizon-style: permissive default only in `local`), `Authorize` middleware, configurable middleware stack/domain/path.
- Signed recording tokens (HMAC-SHA256, ≤1 h, optional user id and path pattern), watch list (cache), sampling (needs an authenticated user unless configured), tentative recording confirmed on `Authenticated`, route-name exclusions, dashboard paths never recorded.
- JSON API v1: meta, trace list (filters, cursor), trace summary, step tree without payloads, lazy payloads, Deep trace lines with function sources, writes, problems, delete/clear, token issuing, watch list, Deep trace queue.
- Commands: `request-tracer:prune` (scheduled hourly automatically), `request-tracer:install`, `request-tracer:token`.
- Docs: running in production, API reference, SECURITY.md.

### Phase 5 – dashboard
- Vue 3 + Vite + TypeScript single-page app (no router or UI library dependency), built to `dist/app.js` and `dist/app.css` (committed, fixed file names, served by the package).
- Request list with search, "With problems" / "Slow" filters, module filter, polling and slide-in of new rows, cursor pagination; header with environment badge, pulsing recording indicator and the "Record a request" dialog (signed header, watch list).
- Request view: metric strip, animated stacked "where the time went" bar (self time per type), Flow / Database / Problems tabs.
- Flow: virtualised step tree with elbow connectors, per-step type, one-line summary ("Returns Order #1057"), time bars and flags; hover previews and click pins a step; keyboard navigation (arrows, Enter).
- Step detail: lazy-loaded Input / Output in `dd()` style (collapsible, copy, masked and truncated markers) and "Inside the code" (coloured dots, chips, hover card with variables, Replay with Back/Next/progress, "Trace this file line by line" queue button).
- Database tab: INSERT / UPDATE / DELETE cards with before → after grids, masked cells, writing step link, transaction state, read-only tables. Problems tab: cards that jump to the step in the flow, plus the notes about things the tracer could not follow.
- Seeded demo mode (`npm run dev`, or `?demo=1`): an order flow with a failed-then-retried HTTP call, an N+1, a caught exception, Deep trace and table writes; nothing leaves the browser.
- Accessibility: 44 px hit areas, visible focus ring, tested 4.5:1 colour contrast, all animation disabled under `prefers-reduced-motion`, no horizontal scroll at 375 px.
- Backend: one-line `meta.summary` for steps (`Returns Order #1057`, `Threw …`), and `sources[].statements` in the span-lines API so only lines that carry a probe can be shown as "not run".
- Tests: 127 frontend tests (formatting, tree, value, code view, contrast and stylesheet rules).

### Phase 6 – hardening, background work, benchmarks
- Queued jobs, artisan commands and scheduled closures/jobs are traced as `job`, `command` and `schedule` traces (`BackgroundDecider`, `CommandCollector`, worker listeners in `JobCollector`). Production default: a job is recorded only when the request that dispatched it was recorded, a command only with `REQUEST_TRACER=1`.
- Job → dispatching request link (`parent_trace_id`, from a stamp in the queue payload) in both directions in the dashboard ("Dispatched by a request" and "Started by this request").
- A trace is stored when the job is processed, throws or fails; a command that dies without `CommandFinished` is stored by a shutdown hook; `sync` jobs and nested `Artisan::call()` stay part of the outer trace; the tracer never traces its own queued writes.
- Fixed: the queue payload stamp was registered once per process, which the framework's test teardown defeats; it is now registered on every boot.
- Cheaper idle path: in `triggered` mode the trigger checks run before the exclusion patterns (about 25 µs per untriggered request).
- Tests for the span cap and collapse, queued persistence, repeated requests in one process (no state or memory left behind), tenant/module resolvers (including a resolver that throws) and writes on two database connections.
- `benchmarks/run.php` (five scenarios, each in its own process) and `docs/benchmarks.md` with results; docs: queues, commands and scheduled tasks.

### Phase 7 – documentation and release
- Docs 1 to 13: introduction, installation, quick start, configuration reference (every key), how capture works, Deep trace, production, large applications and ERPs, dashboard tour, jobs/commands/scheduled tasks, extending, comparison with Telescope / Debugbar / Clockwork, troubleshooting, FAQ, upgrading and contributing. `README.md`, `README.ar.md`, `CONTRIBUTING.md`, `RELEASING.md` (release checklist).
- Removed the unimplemented `scope.modules` config key; added indexes on `tenant` and `module`.
- Found by installing the package into a fresh Laravel 12 application and fixed:
  - the Deep trace autoloader failed with "class not found" for the classes it uses itself the first time a class was loaded through it (PHP does not re-enter the autoloader for a class that is being autoloaded); it now steps aside for the package's own and php-parser classes and runs its code once before it is registered. Covered by a test that runs in a PHP process of its own.
  - middleware that the container builds with a closure (Laravel's `StartSession`) had no step; it is now wrapped in a decorator, and its `terminate()` still runs.
  - queries and log lines that no application file caused were attributed to a generated proxy file, and the framework's own double read of the `sessions` row was reported as a "repeated query"; generated code is now an internal frame, and repeated-query problems need an application file to point at.
  - internal commands (`invoke-serialized-closure`, `schedule:finish`, `package:*`...) are excluded by default.
  - `created_at` was read by the browser as its own local time: it is stored in UTC and returned as ISO 8601 with its offset (Carbon 2 and Carbon 3 disagree about `createFromTimestamp()`, which also skewed the retention cut-off by the UTC offset).
