# 9. Using the dashboard

Open `/request-tracer` (the path is `request-tracer.dashboard.path`). You need to pass the `viewRequestTracer` gate; in a `local` environment with no gate defined, everyone does.

Want to look around first? Run `npm run dev` in the package folder (or add `?demo=1` to the URL). You get the dashboard with seeded demo data and no backend.

## The header

| Item | Meaning |
|---|---|
| Environment badge | Orange in production, green elsewhere. |
| Recording indicator | A pulsing dot: **Recording every request** (`always`), **Recording on trigger** (`triggered`, the production default) or **Recording is off**. |
| Module filter | Appears when your traces carry a module (see the module resolver). |
| Record a request | Makes a signed header for one request (valid for seconds to an hour, optionally for one user or route) or puts a user on the watch list. Hidden when recording is off. |

## The request list

Newest first. Each row shows the method, path, status, duration, age, and a short note: *4 problems*, *exception*, *slow* or the module. The list refreshes every `dashboard.poll_seconds`; new requests slide in at the top. Filter with the search box (path, route name, id) or the chips **With problems** and **Slow** (`dashboard.slow_ms`).

## A request

Above the tabs you see the metric strip (time, memory, queries, steps, problems, tables written) and **Where the time went**: the *self time* of every step, grouped by type, so a controller that spends its time in a service is not counted twice.

### Flow

The tree of everything that ran: middleware, controller, validation, services, queries, HTTP calls, events, jobs, cache, logs and exceptions.

- Hover a step to preview it on the right; click it to **pin** it (click again to release). Arrow keys move through the tree, Left/Right collapse and expand, Enter pins.
- Under each label: a one-line summary (*Returns Order #1057*, *Responded 200 OK*) and the file and line.
- Chips: `×6` identical statements were merged into one step, `slow`, `repeated`, `caught`, `failed`.
- Search finds steps by label, SQL, file or summary and keeps their ancestors visible.

**Input / Output** shows the arguments and return value in `dd()` format. Nodes collapse, *Copy* copies the value, and the markers tell you when something was **masked** (secrets never reach storage) or **truncated** (the limits in `request-tracer.dumper`).

**Inside the code** (steps that were traced line by line, see [Deep trace](06-deep-trace-guide.md)):

| Dot | Meaning |
|---|---|
| green | the line ran (a `true` condition counts) |
| purple | a condition was `false`, so the block was not entered |
| red | the line threw |
| orange | an exception was caught here |
| blue | a loop (the chip shows the iterations) |
| grey | the line carries a probe but never ran |

Hover or focus a line for a plain sentence and the variables involved. **Replay** walks through the executed lines in the order they first ran (Back / Next / progress). If a step was not traced, **Trace this file line by line** queues the file; send the request again.

### Database

One card per table and operation: `INSERT`, `UPDATE` or `DELETE`, the rows, and for updates *before → after* with the changed cells highlighted. Each card names the step that wrote it (click to jump to it in the flow) and says whether the write was inside a transaction that committed or rolled back. Tables that were only read are listed at the bottom.

### Problems

Uncaught and caught exceptions, N+1 queries, repeated and slow queries, slow steps and failed HTTP calls, each with an explanation, the file and line, and **Show in the flow**. At the bottom: notes about things the tracer could not follow (a `final` class that cannot be proxied, a file that could not be compiled for Deep trace).

## Keyboard and accessibility

Every control is at least 44 px, has a visible focus ring and a text label; colours meet 4.5:1 contrast; animations stop when the system asks for reduced motion; the layout works from 375 px wide (an open request takes the whole screen, "← All requests" goes back).
