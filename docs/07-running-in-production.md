# Running in production

Request Tracer is built to be installed in production **and stay out of the way**: nothing is recorded unless you ask for it, everything it records is masked before it is stored, and the dashboard is closed unless you open it.

## The model in one paragraph

**Nothing is recorded unless somebody asks for it**, in every environment. The ways to ask, from simplest to most specific: (1) run `php artisan request-tracer:listen` on the server, and everything is traced for as long as it runs; (2) send one request with a **signed header**; (3) put a user on the **watch list**; (4) turn on **sampling** for a small share of authenticated traffic. Everything else costs one `stat()` of a heartbeat file and a header lookup (25 to 65 µs). All trace data is written in `terminate()`, after the response was sent, on a dedicated database connection. The dashboard needs the `viewRequestTracer` gate.

## Checklist

1. **Define the gate** (nothing is visible outside `local` without it):

   ```php
   // app/Providers/AppServiceProvider.php
   use Illuminate\Support\Facades\Gate;

   Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
   ```

2. **Put the tables on a connection you control** (optional but recommended): `REQUEST_TRACER_DB_CONNECTION=tracer`. Traces are written on a *separate PDO* by default (`storage.separate_connection`), so a transaction that the application rolled back never takes the trace with it.
3. **Keep `mode` at `triggered`** (the default everywhere). Never set `always` in production.
4. **Decide how you will trigger it**: pick the least permissive that works.
   * *Listen* (the usual way): SSH to the server and run `php artisan request-tracer:listen`. Everything the project does is traced until you press Ctrl+C: requests, jobs, commands, scheduled tasks. It works through a small heartbeat file in `storage/framework/request-tracer`, so every PHP process **on that machine** sees it; behind a load balancer, run it on the server you want to look at (or use a signed header, which travels with the request).
   * *Signed header* — from the dashboard (**Record from outside**) or `php artisan request-tracer:token --ttl=300 --route='api/orders*'`. Send it as `X-Request-Tracer: <token>`. Tokens are HMAC-signed with the app key, expire in at most one hour, and can be limited to a path pattern or to one authenticated user.
   * *Watch list* — "record Ann's requests for the next 30 minutes". One cache read per request while the feature is on (`triggers.watch_list.enabled`).
   * *Sampling* — `triggers.sampling.enabled` with a small `rate`. Sampled requests are only **kept** when a user is authenticated (`require_auth`).
   * *Jobs and commands* — a job is recorded only when the request that dispatched it was recorded; a command or scheduled task only when the process was started with `REQUEST_TRACER=1`. See [queues, commands and scheduled tasks](10-queues-commands-scheduled-tasks.md).
5. **Trim what is traced**: `code.paths` is `['app']` by default; narrow it to the folders you are looking at, and list what must be left out in `code.except`. List the routes that must never be recorded in `exclude`.
6. **Retention**: `retention.hours` (24) and `retention.max_rows` (5 000) are enforced by `request-tracer:prune`, which is scheduled hourly automatically (run `php artisan schedule:run` as usual). Turn the automatic schedule off with `retention.schedule => false`.
7. **Check the masking list** (`masking.keys`) against your own field names. See *What is masked* below.
8. **Busy PHP workers?** Set `storage.queue.enabled = true` so traces are written by a queue worker instead of in `terminate()` (you must then run a worker).
9. **Run the benchmarks** on your hardware: [benchmarks](benchmarks.md).

## What is recorded, when

| Situation | Recording | Cost for a request that is not recorded |
|---|---|---|
| `enabled = false` | none, nothing is registered | zero |
| `mode = off` | none (dashboard works) | one boolean check |
| `mode = triggered`, nobody listens, no trigger | none | one header lookup and one `stat()` of the heartbeat file (+ one cache read if the watch list is enabled, + one random number if sampling is enabled) |
| `request-tracer:listen` is running | on, for everything (requests, jobs, commands, scheduled tasks) | n/a |
| valid signed header | on, immediately | — |
| watch list non-empty / sampled | *tentative*: recorded in memory, **kept only if the user matches**, dropped otherwise with no write | the cost of recording, for every request, while the watch list is non-empty |
| `mode = always` (opt-in, never in production) | on | n/a |

A request that is never authenticated is never kept unless its signed header is valid.

## What is masked

Masking happens **while a value is converted**, before it exists in any structure the package keeps, so the raw secret can never reach the database, a log or the API.

* Keys that match `masking.keys` (default: `password`, `passwd`, `secret`, `token`, `authorization`, `cookie`, `api_key`, `card`, `cvv`, `pan`, `iban`) — as whole words, so `apiKey`, `api-key`, `credit_card_number` and `X-CSRF-TOKEN` match and `company` does not match `pan`. Applies to method arguments, return values, array keys, object properties, request bodies, query strings, cache keys, log context, HTTP-client bodies, Eloquent attributes and Deep trace variable names.
* Query bindings, **by column name** (`where password = ?`, `insert into users (email, api_token) …`), plus password hashes and bearer tokens recognised by shape.
* Eloquent `$hidden` / `$visible`.
* Headers: only an allowlist is stored (`capture.request_headers`, `capture.response_headers`); everything else is dropped.
* String values: `masking.value_patterns` (default: `Bearer …`).

What masking cannot do: recognise a secret that travels under an innocent name and was never assigned to something named like a secret (`$x = $token;` is masked at `$token`, not at `$x`), or a secret embedded in a free-text field. Treat the dashboard as sensitive and keep the gate narrow.

## Overhead

Measured numbers (disabled, installed-and-idle, recording with layers 1–2, recording with Deep trace) are in [benchmarks](benchmarks.md). The design target is **under 1 % when disabled**.

## Multi-server and Octane

* Signed tokens are valid on every server that shares `APP_KEY`.
* The watch list lives in the application cache — use a shared store (`triggers.watch_list.store`).
* The listen command's heartbeat is a file in `storage/framework/request-tracer`: it reaches every PHP process **on the same machine**. Run the command on the server you want to look at.
* The Deep trace queue is a file in `storage/framework/request-tracer/compiled`; point `deep_trace.cache_path` at shared storage or queue per server.
* Octane / long-lived workers: all per-request state is created and dropped per request; the only statics are one boolean flag and callbacks registered once at boot. Classes are compiled/proxied when first loaded in a worker, so restart workers after you change `code.paths` or start listening, if you want the classes they already loaded to be traced line by line.

## If something goes wrong

The tracer never breaks a request: every internal failure is caught, written to the `request-tracer` log channel (`storage/logs/request-tracer.log` unless you define the channel) and swallowed. To stop everything immediately set `REQUEST_TRACER_ENABLED=false` (and `php artisan config:clear` if config is cached).
