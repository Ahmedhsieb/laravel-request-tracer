# 9. Using the dashboard

Open `/request-tracer` (the path is `request-tracer.dashboard.path`). You need to pass the `viewRequestTracer` gate; in a `local` environment with no gate defined, everyone does.

Want to look around first? Run `npm run dev` in the package folder (or add `?demo=1` to the URL). You get the dashboard with seeded demo data and no backend.

## The header

| Item | Meaning |
|---|---|
| Environment badge | Orange in production, green elsewhere. |
| Status chip | **Listening: tracing everything now** (green, pulsing): `php artisan request-tracer:listen` is running. **Not listening** (orange): nothing is being traced; the command to start it sits next to the chip, with a Copy button. **Recording every request** (`mode = always`) or **Recording is off**. |
| Module filter | Appears when your traces carry a module (see the module resolver). |
| Replay a request | Runs a request that already happened again, traced. See below. Hidden where replay is off (the production environment, by default). |
| Record from outside | For a server where you cannot run the listen command: a signed header for one request, or watch a user for a while. Hidden when recording is off. |

## The request list

Newest first. Each row shows the method (or `JOB` / `COMMAND` for queued jobs and artisan commands, see [queues, commands and scheduled tasks](10-queues-commands-scheduled-tasks.md)), path, status, duration, age, and a short note: *4 problems*, *exception*, *slow* or the module. The list refreshes every `dashboard.poll_seconds`; a trace from a replay carries a *replay* badge; new requests slide in at the top. Filter with the search box (path, route name, id) or the chips **With problems** and **Slow** (`dashboard.slow_ms`).

**Group by route** puts the requests of one route prefix together: `/api/payments` with its count, its problems and its slowest request, and a click on the header closes or opens it (*Close all* / *Open all* for the lot). The prefix is the first segments of the route up to the first record id (`/api/payments/vouchers/12/edit` is `/api/payments` with two segments, `/api/payments/vouchers` with three; the number of segments is a choice next to the button). Jobs and commands group as *Jobs* and *Commands*. The choice is remembered in the browser.

The page itself does not scroll: the list on the left and the details on the right each scroll inside their own box, however many requests or lines of code there are. *Show overview* / *Hide overview* (next to the tabs) opens or folds the time map and the figures to give the flow more room; on a screen shorter than 1000 px it starts folded.

## A request

Above the tabs you see the metric strip (time, memory, queries, steps, problems, tables written) and **Where the time went**: the *self time* of every step, grouped by type, so a controller that spends its time in a service is not counted twice.

### Flow

The tree of everything that ran: middleware, controller, validation, services, queries, HTTP calls, events, jobs, cache, logs and exceptions.

- Hover a step to preview it on the right; click it to **pin** it (click again to release). Arrow keys move through the tree, Left/Right collapse and expand, Enter pins.
- Under each label: a one-line summary (*Returns Order #1057*, *Responded 200 OK*) and the file and line.
- Chips: `×6` identical statements were merged into one step, `slow`, `repeated`, `caught`, `failed`.
- A request that dispatched jobs shows a **Started by this request** row under the step details, one button per job; a job's own page links back with **Dispatched by a request**.
- Search finds steps by label, SQL, file or summary and keeps their ancestors visible.
- **Filters above the steps** narrow the tree down. *Kinds of step* are toggles with a count each (middleware, controller, query, HTTP...): pick one or several. *Speed* is *Slow* (flagged slow by the analysis, or at least `dashboard.slow_step_ms`, 100 ms) or *Fast*; *Result* is *Failed* (threw, was caught after throwing, or an HTTP call answered 4xx/5xx) or *OK*. They combine with each other and with the search; the steps above a match stay visible, dimmed, so you can see where it sits. *3 of 21 steps* says how many match, *Clear filters* resets.

**Input / Output** shows the arguments and return value in `dd()` format. For a controller that returns a view (`return view('payments.show', compact('voucher', 'items'))`) the output is **what was passed to the view** (`voucher`, `items`...) with the view's name in the summary, not the rendered HTML. Nodes collapse, *Copy* copies the value, and the markers tell you when something was **masked** (secrets never reach storage) or **truncated** (the limits in `request-tracer.dumper`).

**Inside the code** (steps that were traced line by line, see [Deep trace](06-deep-trace-guide.md)):

| Dot | Meaning |
|---|---|
| green | the line ran (a `true` condition counts) |
| purple | a condition was `false`, so the block was not entered |
| red | the line threw |
| orange | an exception was caught here |
| blue | a loop (the chip shows the iterations) |
| grey | the line carries a probe but never ran |

The code view is built around the step itself:

- **The step's own function comes first** (the action of a controller, the method of a service), open. A controller's constructor, its base class and other framework hooks ran inside the step too, but they are not what you clicked: they are under **Other code that ran during this step**, closed.
- **What it calls comes under the line that calls it.** If the action calls a helper or a private method that ran, that function appears right under the call, closed, with *called from ...*; open it to read its code, and so on down. If it calls a service (or anything else that is a step of its own), that step's code is offered the same way and loaded when you open it. Only functions that actually ran and are called by name from the code you are reading are shown.
- **A step outside `code.paths` still shows its code**, from its text and marked *not traced line by line*, with a notice that names the file and the folder to add to `request-tracer.code.paths` (for a package that is its `src` folder, for example `'paths' => ['app', 'packages/Accounting/src']`). Without that folder in the list the lines never ran through the compiler, so there are no dots and no variables, only the code.
- **A function with no run data says why.** *Not traced line by line* with the folder to add: its folder is not in `code.paths`. *Did not run*: the step started and other functions of it were recorded, but not this one, so the request left before reaching it (the usual case is a form request whose validation failed, answered with a `302` or `422`: open the validation step and the Problems tab). *No line was recorded for this step*: the request was recorded without line-by-line tracing; start `request-tracer:listen` first, or use *Trace this file line by line*.
- **An edited file is followed.** If the file was changed after the request (a line added above a function, a method moved), the numbers recorded then no longer match its text. The dashboard finds where each function's statements are now by their text and moves the function and its lines there; the header says *edited since: moved +1 lines*. If nothing could be matched it says *line numbers may not match*, and the safe thing is to send the request again.
- **Every function folds**: click its header to hide or show its lines.
- **The output of a line is a pop-up beside the code**, not a card underneath it that scrolls out of sight: hover (or focus) a line to see the sentence and the variables, **click it to keep the pop-up open** (close it with its ✕, with Esc or by clicking elsewhere).

**Replay** walks through the executed lines of the open functions in the order they first ran (Back / Next / progress), with the pop-up following. If a step was not traced, **Trace this file line by line** queues the file; send the request again.

### Database

One card per table and operation: `INSERT`, `UPDATE` or `DELETE`, the rows, and for updates *before → after* with the changed cells highlighted. Each card names the step that wrote it (click to jump to it in the flow) and says whether the write was inside a transaction that committed or rolled back. Tables that were only read are listed at the bottom.

### Problems

Uncaught and caught exceptions, N+1 queries, repeated and slow queries, slow steps and failed HTTP calls, each with an explanation, the file and line, and **Show in the flow**. At the bottom: notes about things the tracer could not follow (a `final` class that cannot be proxied, a file that could not be compiled for Deep trace).

## Moving around fast

- **Ctrl+K (⌘K)** opens the search palette: actions (replay, record, group, theme, reload), the requests of the list and, when a request is open, **its steps**. Pick a step and the flow scrolls to it and opens it. Arrow keys move, Enter picks, Esc closes.
- **The flow is a waterfall.** Every step is a bar placed at its real start and length on a time ruler; a crosshair follows the pointer and says the time at that point. Above the flow a small **time map** draws the whole request; drag over it to zoom the waterfall into that part of the time, *Reset zoom* gets the whole request back.
- **The ribbon under the figures** shows where the time went by step type; click a type to filter the flow by it.
- **The panes are yours.** Drag the line between the list and the request, and between the steps and the details; the sizes are remembered in the browser. The bar of little columns above the list is the *pulse* of the list: one column per request, as high as it was slow, red when it failed.
- **Dark and light.** The dashboard starts dark; the moon/sun button (or the palette) switches, and the choice is remembered.

## Keyboard and accessibility

Every control has a visible focus ring and a text label; colours meet 4.5:1 contrast; animations stop when the system asks for reduced motion; the layout works from 375 px wide (an open request takes the whole screen, "← All requests" goes back). Dialogs, menus, tabs and the palette are built on Reka UI, so they trap focus, close with Esc and are announced to screen readers.

## Replay a request that already happened

The request you need to understand happened while nothing was listening? Run it again, traced:

1. **Replay a request** (header), then either **Paste cURL** (browser: Network tab, right-click the request, *Copy → Copy as cURL (bash)*; Postman and bug reports have one too) or **Describe it** (method, URL or path, headers, body).
2. Optionally the id of the user to **act as**: the request runs as that user, with no login.
3. Choose what it may change: **Simulation** (nothing is changed, the default) or **Run for real** (it affects data, and you can undo it afterwards; you must tick a confirmation).
4. **Simulate it** / **Run it for real**. The new trace opens, marked *replay*.

On any trace, **Replay this request** opens the same dialog filled in from what was recorded: method, path and query, headers and body. Values that were masked when the request was recorded (passwords, tokens) cannot be recovered, so the dialog warns you and shows the mask where the secret was: replace it before you run.

What a replay does and does not do:

- The request goes through your application's **own HTTP kernel**, middleware and all, in a PHP process of its own, so its code is compiled for line-by-line tracing from the first class.
- In a **simulation** (the default) **database changes are rolled back** (a transaction on every connection the request touches); the trace still shows what the request wrote, in the Database tab. **E-mails** go to the `array` mailer, **notifications** and **broadcasts** are dropped, **queued jobs** are discarded, calls made with **Laravel's HTTP client** are answered with an empty `200` and the **cache and the session** stay in memory.
- A simulation cannot control what happens outside the framework: **files**, a **Guzzle or curl call made directly**, an SDK that talks to a service by itself, a table that does not support transactions, a statement that commits on its own (DDL). While it runs, the rows it touches are locked and auto-increment counters move.
- **Run for real** lets the request do what it does: rows are written and kept, e-mails and jobs go out, other services are called. While it runs, **every Eloquent write is recorded exactly** (the row for an insert, the changed columns before and after for an update, the whole row for a delete; a soft delete too) in a log that is separate from the trace, because the trace masks and cuts values. The log is encrypted with the application key and never shown.
- On the trace of a real replay, a banner lists what can be **undone** (`orders: 1 insert, 1 update`, ...) and what cannot: writes made with the query builder or raw SQL (listed by table), e-mails, jobs, files and calls to other services. **Undo these changes** takes the recorded writes back, last first, in one transaction per connection: **all or nothing**. A row that changed after the replay (somebody edited it) is not touched: the banner lists those rows and **Undo anyway** is the explicit way to force it. From the terminal: `php artisan request-tracer:undo <trace>` (`--force`, `--yes`). The log expires after `replay.undo_hours` (24 by default).
- Replay is off in the `production` environment unless `request-tracer.replay.enabled` is `true`, and running for real there also needs `request-tracer.replay.real` to be `true`.
- POSTs get a valid CSRF token automatically. Multipart uploads are not replayed.

From the terminal: `php artisan request-tracer:replay "POST /orders" --data='{"items":[1]}' --as=42`, or `--curl="curl '...' ..."`; add `--real` (it asks to confirm, or `--force`) to run it for real, and undo it with `php artisan request-tracer:undo <trace>`.

## The journal

You do not always know what the request looked like. A user says "it failed around 3 pm", and nothing was listening. **The journal** is for that: switch it on (`REQUEST_TRACER_JOURNAL=true`) and every request is noted in one light row, whether it is traced or not. The left pane then has two tabs, **Traces** and **Journal**.

Each journal row shows the method, the URL with its query string, the status, who made the request (user id, or *guest*), how long it took, and, for a failure, the exception (`RuntimeException: Invoice 9912 has no ledger entry.`) or `HTTP 500`. Filter with **Failed** (a 5xx or an exception) and the search box (URL, user, error).

**Group by route** works here too, the same way as in the request list (same button, same prefix length): a group header shows its request count, how many of them failed and the slowest one.

**Click a row** and the replay form opens filled in from it: method, URL, headers, the body, and the user it ran as. Choose the mode and run it, and you get the full trace, line by line, of what that request does now (a simulation changes nothing; see above for running it for real). If the request was traced when it happened, the row has a **Trace** button that opens that trace instead.

From the terminal, without opening the dashboard: `php artisan request-tracer:replay --journal=failed` (the newest request that failed), `--journal=last`, or `--journal=<id>`.

Things to know:

- **Secrets are masked before the journal stores them**, so a replay of a request with a password in it sends the mask. The row says *masked*, and the form warns you: replace the value, or accept that the request fails where it needs the secret. Most business requests (orders, imports, searches) have none and replay exactly.
- A body larger than `journal.body_max_bytes` is not kept (*no body* on the row): the replay sends none.
- It is not a trace: nothing about what happened *inside* the request is kept. That is the point of the replay, which runs the request again with the **current** code and the **current** data; if either changed since, the result can differ from what happened then.
- It keeps the last hour and at most 500 requests by default (`journal.hours`, `journal.max_rows`). It costs an `INSERT` per request after the response was sent, which is why it is off until you turn it on.
