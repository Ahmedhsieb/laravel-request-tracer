# 1. Introduction

When something in a Laravel application behaves strangely, the usual move is `dd()`: stop the request, look at one value, change the code, try again. It works, but it tells you about one place, it destroys the request it is debugging, and you cannot leave it in production.

**Request Tracer shows you the whole request instead.** One request becomes a tree of everything that happened: middleware, controller, validation, services, queries, HTTP calls, events, jobs, exceptions. For every step you see what went in and what came out, printed the way `dd()` prints it. For the code you care about you can go one level deeper and see **line by line** which conditions were true or false, which blocks ran, and what the variables held. For the database you see which tables were written, and the row before and after. And a list of problems tells you what to look at first.

> *Screenshot placeholder:* `docs/images/dashboard-flow.png` — the request view with the step tree on the left and Input / Output on the right. See [docs/images/README.md](images/README.md) for what to capture.

## What you get

| You want to know | Where to look |
|---|---|
| What happened in this request, in order? | **Flow**: the tree of steps with time bars. |
| What did this service receive and return? | **Input / Output** of the step, in `dd()` format. |
| Why did this `if` not run? Which branch was taken? | **Inside the code**: every condition, block and variable, with the original line numbers. |
| What did this request change in the database? | **Database**: one card per table, with before → after. |
| What went wrong, or is about to? | **Problems**: caught exceptions, N+1 queries, repeated and slow queries, slow steps, failed HTTP calls. |
| Where did the time go? | The stacked bar above the tabs: self time per kind of step. |

## What it is not

- It is not a profiler for CPU hot spots, and it does not replace an APM for fleet-wide metrics.
- It does not record anything on its own, in any environment: **nothing is recorded unless you ask** (the listen command, a signed header for one request, a watch list for one user, or a small sample). See [Running in production](07-running-in-production.md).
- It is not magic: it sees what the framework tells it about (queries, events, HTTP calls, jobs) plus the classes you opt in. [How capture works](05-how-capture-works.md) lists exactly what is seen and what is not.

## How you use it

```bash
composer require your-vendor/laravel-request-tracer
php artisan request-tracer:listen
```

No config, no migration, nothing to publish. `request-tracer:listen` works like `queue:work`: while it runs, everything the project does is traced (requests, queued jobs, artisan commands, scheduled tasks); stop it and nothing is recorded. Open `/request-tracer` to look.

**What is traced is simple to say**: every call in your `app/` folder, with input and output, and, line by line, every condition, block and variable inside it. To look at other folders or single files, or to leave something out, change two lists (`code.paths`, `code.except`). You never name classes one by one.

The request you care about already happened? **Replay it**: paste its cURL command, and the application runs it again, traced: as a simulation that changes nothing, or for real, with what it wrote recorded so it can be undone.

Underneath there are four ways code gets seen, and all of them are on by default for the project:

1. **Framework collectors**: queries, events, HTTP calls, cache, logs, exceptions, validation, model writes.
2. **Container proxies**: every class of the project that the container builds becomes a step with input and output.
3. **Line by line (Deep trace)**: the files of the project are compiled with probes while somebody listens.
4. **Attributes and the helper**: `#[Trace]` / `#[NoTrace]`, and `trace($value, 'label')` for any value anywhere.

## Safe by design

- **Off costs nothing.** With `enabled = false` nothing is registered, and when nobody listens the cost is 25 to 65 µs per request ([benchmarks](benchmarks.md)).
- **It never breaks a request.** Every tracer code path is guarded; a failure is logged to its own channel and swallowed. Traces are written after the response was sent.
- **Secrets are masked before they are stored**, not after. Headers use an allowlist.
- **The dashboard is closed** outside the `local` environment until you define the `viewRequestTracer` gate.
- **Long-running workers are fine**: state lives in one object per request or job and is dropped at the end (Octane, Horizon, `queue:work`).

## Where to go next

[Installation](02-installation.md) → [Quick start](03-quick-start.md) → [Using the dashboard](09-using-the-dashboard.md). If you run a large application: [Large applications and ERPs](08-large-applications.md).
