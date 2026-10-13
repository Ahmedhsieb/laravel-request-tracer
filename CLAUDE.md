# CLAUDE.md – Request Tracer

Laravel package that traces a request end to end (spans, per-step input/output, line-by-line "Deep trace", table writes, problems)
with a Vue 3 dashboard. Composer name `ahmedhsieb/laravel-request-tracer`, namespace `Ahmedhsieb\RequestTracer\`, config `config/request-tracer.php`, route prefix `/request-tracer`.
The brand lives in `src/Support/Brand.php` only.

## Commands

All PHP commands go through `.dev/run.sh <php-version> ...` on this Windows/Laragon machine (it selects the PHP binary and a generated `php.ini` with `pdo_sqlite`).
On CI / a normal machine just drop the prefix.

| What | Command |
|---|---|
| Install | `.dev/run.sh 8.4 composer update` |
| Tests | `.dev/run.sh 8.4 php vendor/bin/pest` (also `8.1`, `8.3`) |
| One test | `.dev/run.sh 8.4 php vendor/bin/pest --filter=Masking` |
| Other DB | `TRACER_TEST_DB=mysql\|pgsql\|sqlite` + `TRACER_TEST_DB_{HOST,USERNAME,PASSWORD,DATABASE}` |
| Style | `vendor/bin/pint` (check: `--test`) |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=1G` (level 8) |
| Other Laravel | `composer require "laravel/framework:10.*" "orchestra/testbench:^8.0" --no-update && composer update` |
| Frontend | `npm ci`, `npm run dev` (demo mode, no backend), `npm run build` (writes `dist/`, commit it), `npm run lint`, `npm run typecheck`, `npm test` |
| Benchmarks | `php benchmarks/run.php` (see `docs/benchmarks.md`) |
| Fresh-app smoke test | `.dev/fresh-app.sh l12 8.4 "^12.0" 8121` (creates a Laravel app, `composer require`, listen, replay, production checks) |

Git: one branch per phase `phase/<n>-<slug>`, merge to `main` when tests + analysis are green, add a `CHANGELOG.md` entry per phase.

## Hard rules (brief sections 3 and 7) – never break these

1. **Disabled = zero effect.** One boolean check per intercepted call, nothing else. `enabled=false` registers no middleware, listener, autoloader or container hook.
2. **The tracer never breaks a request.** Every tracer code path is wrapped (`Tracer::guard()` / `try … catch (Throwable)`) → log to the `request-tracer` channel → swallow. Probe/proxy code re-throws only the *user's* exceptions.
3. **All trace writes happen in `terminate()`** (after the response is sent), never during the request. Jobs/commands persist in their `…Finished` event.
4. **Octane/long-lived safe.** All per-request state lives in `RequestState`/`Recorder` and is reset at the start and end of every request. No static state that survives a request (the only statics are the `Probe::$on` flag and callbacks registered once at boot).
5. **Secrets are masked before storage.** Masking happens inside the Dumper (and in the write/header/body capture), never later. The raw value must never reach the DB. Tests prove it.
6. **No dependency beyond what Laravel ships, except `nikic/php-parser ^4.18|^5.0`.**
7. **PHP 8.1 syntax only** in package code: no readonly classes, typed class constants, `#[\Override]`, `json_validate`, `array_find`, property hooks, etc. CI runs `php -l` on 8.1 and PHPStan with `phpVersion: 80100`.
8. Dashboard + API are behind the `viewRequestTracer` gate (open only in `local` when the app defines no gate). Never record unauthenticated requests unless the signed header is valid. Invalid tokens are ignored silently (debug log).
9. **Nothing records on its own, in any environment** (local included): only while `request-tracer:listen` runs (a heartbeat file, checked with one `stat()`), or for a signed header, a watched user, a sample, a replay, or a process started with `REQUEST_TRACER=1`. `mode=always` is an explicit opt-in. The one exception is the request journal (`journal.enabled`, off by default): one light masked row per request, never a trace, kept `journal.hours`.
10. **Installing is `composer require`.** No config, migration or publish step may become necessary: every key has a default, the tables create themselves, the dashboard is served from the package.
11. **The scope is folders** (`code.paths`, default `app`, minus `code.except`): everything inside is traced, nothing is listed class by class. Compiled copies are only loaded while recording is wanted (`Listening::wanted()`).
12. **A replay is a choice, and a simulation changes nothing**: its database changes are rolled back and mail, notifications, jobs, broadcasts and HTTP-client calls do not go out. **Running for real** keeps the changes, records every Eloquent write in the encrypted undo log (the one place raw values are kept, short-lived, never returned by the API) and says what it cannot undo. Replay is off in the `production` environment unless `replay.enabled` says so; real needs `replay.real` too.

## Conventions

- `declare(strict_types=1);` in every file. Small `final` classes, constructor injection, interfaces where a seam is useful (`TraceStorage`, `Collector`).
- No clever magic where a plain interface works. No closures in config (see `docs/decisions.md` D12).
- Span = plain data object (`src/Span.php`). Collectors only talk to the `Recorder`; the `Recorder` only talks to `Storage` through `TraceStorage` at terminate time.
- Frontend: `resources/js` (Vue 3 + TS + Vite). API is versioned `/request-tracer/api/v1`. Payloads are never part of the tree response; they load lazily by span id.
- Tests: Pest; unit tests under `tests/Unit`, anything that boots Laravel under `tests/Feature`. Example app: `tests/Fixtures` (Order flow). Add a test with every behaviour change.
- Docs: write them as you go (`docs/`). Every config key must be in `docs/04-configuration.md`. Record non-obvious decisions in `docs/decisions.md`.

## Layout

```
src/Recorder.php, Span.php, Tracer.php   core
src/Collectors/                          layer 1 (framework events)
src/Proxy/                               layer 2/3 (container proxies, attributes)
src/DeepTrace/                           compiler + probes
src/Dumper/                              Symfony VarCloner → stored structure, masking
src/Storage/                             TraceStorage + database driver
src/Http/ routes/                        API + dashboard
src/Console/                             listen, replay, undo, prune, install, token
src/Replay/                              run a request again (cURL parser, transactions, replayer, undo log)
src/Recording/                           when to record: decider, listening heartbeat, tokens, watch list
resources/js/                            Vue SPA   → dist/ (committed build)
tests/Fixtures/                          example Order app used by the end-to-end test
benchmarks/                              request/sec comparison scripts
.dev/                                    local-only helpers (gitignored)
```

## Status

All phases (0 to 7) are done and released as `v0.1.0`; see `CHANGELOG.md` for what each phase delivered, `docs/decisions.md` for the decisions and known limitations, and `RELEASING.md` for the release checklist.
