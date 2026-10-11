# Changelog

All notable changes are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org).

## [Unreleased]

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
