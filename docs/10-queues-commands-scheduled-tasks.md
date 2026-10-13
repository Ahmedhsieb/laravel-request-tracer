# 10. Queued jobs, artisan commands and scheduled tasks

A request is not the only thing worth tracing. Request Tracer records three more kinds of trace. They look like request traces in the dashboard (same tree, same Input / Output, same Database and Problems tabs); the list shows the kind instead of an HTTP method.

| Kind | What is recorded | Root step |
|---|---|---|
| `job` | one queued job processed by a worker | the job class |
| `command` | one artisan command | the command name, with its arguments and options |
| `schedule` | one scheduled closure, invokable or `->job()` that runs in the scheduler process | the task description |

Switch them off with `collectors.jobs` and `collectors.commands` in `config/request-tracer.php`.

## Who decides what is recorded

The rules are the same idea as for requests: **nothing is recorded unless somebody asked for it.**

| Situation | Job | Command / scheduled task |
|---|---|---|
| `php artisan request-tracer:listen` is running | recorded | recorded |
| `mode = always` (opt-in) | recorded | recorded |
| otherwise (`triggered`, the default) | recorded **only if the request or job that dispatched it was recorded** | recorded **only if the process was started with `REQUEST_TRACER=1`** |
| `mode = off` | never | never |

So `request-tracer:listen` is enough for a whole project: while it runs, the web requests, the jobs a worker processes, the commands you run and the scheduler's tasks on that machine are all traced, and the job is linked to the request that dispatched it.

In every mode `exclude.jobs` and `exclude.commands` (fnmatch patterns) win. The defaults already exclude the long-running and internal commands: `queue:work`, `queue:listen`, `schedule:run`, `schedule:work`, `schedule:finish`, `serve`, `tinker`, `invoke-serialized-closure`, `migrate*`, `package:*`, `config:*`, `cache:*`, `horizon*`, `octane:*`, `request-tracer:*` and a few more. The tracer's own queued writes (`Ahmedhsieb\RequestTracer\Jobs\*`) are never recorded.

```bash
# trace one run of a command in production
REQUEST_TRACER=1 php artisan orders:reconcile
```

## How a job is linked to the request that dispatched it

While a request is being recorded, every job it dispatches gets a small stamp in its queue payload (`request_tracer: {trace, span}`). When a worker picks the job up it sees the stamp, records the job (even in `triggered` mode) and stores the id of the dispatching trace as `parent_trace_id`.

In the dashboard you get both directions:

- on the **job**: "Dispatched by a request: open it";
- on the **request**: "Started by this request:" with a button per job it dispatched.

The stamp is only added while a trace is recording, so requests that are not recorded dispatch exactly the payload they always did. The stamp contains two ids and nothing else.

## Details worth knowing

- **One trace per job, even in a worker that runs for days.** A trace starts at `JobProcessing` and is stored when the job is processed (`JobProcessed`), throws (`JobExceptionOccurred`) or fails (`JobFailed`, which also covers time-outs and "attempted too many times"). State from one job never reaches the next one; this is covered by a test that runs several jobs in one worker.
- **A failed job** is stored with the exception as an "exception not caught" problem and its root step flagged `failed`. A job that is retried produces one trace per attempt.
- **The `sync` queue** runs the job inside the request. There is no separate trace: its steps are part of the request's tree, exactly where they ran.
- **`Artisan::call()` inside a request or another command** is part of the outer trace for the same reason.
- **Scheduled tasks that are artisan commands** (`$schedule->command(...)`) run in a child process and are recorded there as a command trace. Closures, invokables and `->job()` run in the `schedule:run` process and are recorded as `schedule` traces. `schedule:run` itself is never a trace.
- **A command that ends the process** with `exit()` or a fatal error never reaches `CommandFinished`; a shutdown hook stores what was recorded so far (summary: "The process ended before the command finished").
- **Queries, HTTP calls, events, table writes and Deep trace** work exactly as in a request, because the same collectors run.
- **Long-running workers** (`queue:work`, Octane, Horizon): nothing is kept between traces; the per-trace state lives in one object that is dropped when the trace is stored.

## Storing traces from a worker

Writing a trace in `terminate()` keeps the worker busy for a few milliseconds after the response. If that matters, set `storage.queue.enabled = true`: the trace is serialised, compressed and dispatched as a job, and a queue worker stores it. The default is off because it needs a queue worker to be running; without one, nothing is stored.
