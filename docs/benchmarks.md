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

PHP 8.4.15, Laravel 13.35, Windows 11 laptop, SQLite file database, 500 requests per scenario after 30 warm-up requests, on an otherwise idle machine. "The project's code" is the services folder of the example application (the equivalent of `code.paths => ['app']` in a real project); each recorded request has 47 steps and 15 queries, and the line-by-line scenario adds 53 line rows.

| Scenario | handle median | handle p95 | terminate median | overhead vs. not installed | peak memory |
|---|---:|---:|---:|---:|---:|
| Package not installed | 14.04 ms | 17.80 ms | 0.14 ms | - | 36 MB |
| Installed, `enabled = false` | 13.79 ms | 17.50 ms | 0.14 ms | -0.24 ms | 38 MB |
| Installed, nobody is listening | 14.24 ms | 19.39 ms | 0.15 ms | +0.20 ms | 40 MB |
| Listening: every call of the project, with input and output (`code.line_by_line = false`) | 19.77 ms | 26.30 ms | 7.48 ms | +5.73 ms | 54 MB |
| Listening: + every line of the project (the default) | 21.58 ms | 28.54 ms | 9.89 ms | +7.54 ms | 54 MB |

Decision cost on a request nothing triggers (nobody listening, measured in isolation): **25 to 65 µs**, depending on how fast the file system answers a `stat()` (the Windows laptop is at the slow end; the figure includes the stat of the heartbeat file, one header lookup and a cache read for the watch list).

### Reading the table

- **Installed and idle costs nothing you can measure.** The differences in the first three rows are inside the noise of a laptop (between -1 and +1 ms from run to run, in both directions). The precise figure is the decision itself, in microseconds. With `enabled = false` no listener, middleware, autoloader or container hook is registered at all.
- **While listening, every call of the project adds about 5 to 6 ms to the response** for this flow, and **line by line adds 2 ms more** (7 to 8 ms in total), because the files of the project are the compiled copies and every statement reports to the recorder. Line by line costs in proportion to the lines that run, not to the number of steps, so measure it on your own code. It is paid by the person who is listening, and by requests that arrive while they listen.
- **Another 7 to 10 ms is spent after the response was sent**, in `terminate()`: building the trace, masking and compressing payloads and writing five tables in chunks. The user does not wait for it, but the PHP worker is busy for that long. With `storage.queue.enabled` the write moves to a queue worker and the worker is only busy for the time it takes to serialise and dispatch the job.
- **Memory**: about +16 MB peak while recording, all of it released at the end of the request (see the leak test in `tests/Order/HardeningTest.php`).

## What this does *not* tell you

- It is an in-process measurement: no web server, no network, a local SQLite file. The queries here take tens of microseconds. In an application whose requests spend 200 ms in a real database, the *absolute* overhead stays about the same (it follows the number of steps, queries and events, not the request time), so the *relative* overhead is smaller than the percentages above.
- The overhead scales with what happens in the request. A request with 5 000 steps costs more than one with 50, which is why `limits.max_spans`, `limits.collapse_after` and `deep_trace.max_hits` exist (see [Running in production](07-running-in-production.md)).
- A different database for the traces changes the `terminate` time (a remote MySQL is slower than a local file). Use the queue storage for that case.
- The numbers move by about a millisecond from run to run on a laptop. Compare scenarios from the *same* run, or use `--requests=2000` on a quiet machine.

## Production defaults follow from this

Nothing records on its own: the idle cost above is all a request pays until somebody runs `request-tracer:listen`, sends a signed header, watches a user or turns sampling on. The recording cost is only ever paid on requests somebody wanted to look at.
