# Changelog

All notable changes are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org).

## [Unreleased]

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
