# Benchmarks

What does the package cost? The honest answer is "nothing when it is idle, a few milliseconds per recorded request, paid mostly *after* the response was sent". This page shows how that was measured, the numbers, and what they do not tell you.

## How to run it

```bash
php benchmarks/run.php                      # all scenarios, 300 requests each
php benchmarks/run.php --requests=1000      # more requests, steadier numbers
php benchmarks/run.php --scenario=deep      # one scenario, JSON output
```

The script boots the example "Order flow" application from `tests/Fixtures` (authentication, validation, several services, a caught exception, an HTTP call, an event and three tables written in a transaction; each request produces a trace of 47 steps and 15 queries) and sends `POST /api/orders` through Laravel's HTTP kernel in the same process. Each scenario runs in its own PHP process. **handle** is the time until the response exists, which is what a user waits for. **terminate** is the work the package postpones until after the response was sent.

It needs the development dependencies (it uses Testbench to boot the application), so it is a tool for this repository, not something that ships to your application.

## Results

PHP 8.4.15, Laravel 13.35, Windows 11 laptop, SQLite file database, 500 requests per scenario after 30 warm-up requests. Three consecutive runs; the table is the last one, the ranges are over all three.

| Scenario | handle median | handle p95 | terminate median | overhead vs. not installed | peak memory |
|---|---:|---:|---:|---:|---:|
| Package not installed | 14.99 ms | 21.05 ms | 0.15 ms | - | 36 MB |
| Installed, `enabled = false` | 14.88 ms | 21.63 ms | 0.16 ms | -0.12 ms | 38 MB |
| Installed, `triggered` mode, nothing triggers it | 15.07 ms | 21.19 ms | 0.18 ms | +0.08 ms | 40 MB |
| Recording every request: framework collectors + container proxies | 20.06 ms | 26.41 ms | 7.63 ms | +5.07 ms | 54 MB |
| Recording every request: + Deep trace of `OrderService` | 18.18 ms | 21.52 ms | 7.74 ms | +3.19 ms | 52 MB |

Decision cost on a request nothing triggers (triggered mode, measured in isolation, array cache for the watch list): **21 to 26 µs**.

### Reading the table

- **Installed but idle costs nothing you can measure.** The differences in the first three rows (about ±1 ms between runs, in both directions) are noise on a laptop. The precise figure is the decision itself: about 25 µs per request, which is one header lookup, one cache read for the watch list and a config read. With `enabled = false` no listener, middleware, autoloader or container hook is registered at all.
- **Recording adds roughly 3 to 6 ms to the response** for this flow (47 steps, 15 queries). The runs ranged from +3.2 to +6.1 ms for "collectors + proxies" and "+ Deep trace"; the two are within each other's noise, so the table does not claim that Deep trace is free or that it is cheaper than the layers below it. Plan for "a little more" when Deep trace is on, and measure on your own code: its cost grows with the number of lines executed, not with the number of steps.
- **Another 7 to 10 ms is spent after the response was sent**, in `terminate()`: building the trace, masking and compressing payloads and writing five tables in chunks. The user does not wait for it, but the PHP worker is busy for that long. With `storage.queue.enabled` the write moves to a queue worker and the worker is only busy for the time it takes to serialise and dispatch the job.
- **Memory**: about +16 MB peak while recording, all of it released at the end of the request (see the leak test in `tests/Order/HardeningTest.php`).

## What this does *not* tell you

- It is an in-process measurement: no web server, no network, a local SQLite file. The queries here take tens of microseconds. In an application whose requests spend 200 ms in a real database, the *absolute* overhead stays about the same (it follows the number of steps, queries and events, not the request time), so the *relative* overhead is smaller than the percentages above.
- The overhead scales with what happens in the request. A request with 5 000 steps costs more than one with 50, which is why `limits.max_spans`, `limits.collapse_after` and `deep_trace.max_hits` exist (see [Running in production](07-running-in-production.md)).
- A different database for the traces changes the `terminate` time (a remote MySQL is slower than a local file). Use the queue storage for that case.
- The numbers move by several milliseconds from run to run on a laptop. Compare scenarios from the *same* run, or use `--requests=2000` on a quiet machine.

## Production defaults follow from this

In production the package starts in `triggered` mode: the idle cost above is all a request pays. A recording is switched on per request (signed header), per user for a while (watch list) or for a small share of authenticated traffic (sampling, off by default), so the 3 to 10 ms is only ever paid on requests somebody wanted to look at.
