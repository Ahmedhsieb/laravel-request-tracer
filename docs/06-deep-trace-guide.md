# Deep trace guide

Deep trace answers "did this condition pass? did the request enter this block? what is `$x` at this line?" **without editing your code**. It works the way Blade compiles views: a copy of the file with probes in it is loaded instead of the original, and only while a request is being recorded do the probes do anything.

## What is traced, and when

**Everything in `app/`** (the folders and files of `code.paths`, minus `code.except`), **while somebody is listening** (`php artisan request-tracer:listen`), while a replay runs, when a request carries a valid signed header, or when the process was started with `REQUEST_TRACER=1`. You do not list classes:

```php
// config/request-tracer.php: only what you change has to be there
'code' => [
    'paths'        => ['app', 'modules/Billing/src', 'lib/Money.php'],   // folders or single files, relative to the project
    'except'       => ['app/Legacy', 'App\Reports\*'],                   // leave these out; nothing else is
    'line_by_line' => true,                                                // false: calls with input/output only
],
'deep_trace' => [
    'max_hits' => 20000,                                                   // probe hits per trace, then recording stops
],
```

A file of `code.paths` is compiled the first time it is loaded **while recording is wanted**. In any other request your original file is loaded, so a request nobody traces is not affected at all, and nothing has to be compiled in advance. The compiled copy is cached; later requests reuse it.

A class can also leave itself out with `#[NoTrace]`, or be put in by hand:

* **Dashboard**: open a step that has no line data and press *Trace this file line by line*. The file is queued for the next recorded request (`deep_trace.queue_ttl`, default one hour) and removed from the queue once a recorded request has used it. Useful for a file outside `code.paths`.
* **`#[Trace(deep: true)]`** on a class, when `deep_trace.attribute_scan` is on (off by default: it reads every class under `proxies.attribute_namespaces` once per process).
* **`deep_trace.namespaces`** / **`deep_trace.files`**: classes or files that are compiled all the time, even when nobody listens. Rarely needed now.
* Queue it from code: `RequestTracer\DeepTrace\Loader::queue()->add($absolutePath, 3600)`.

A long-running worker (Octane, `queue:work`) keeps the classes it had loaded before the listen command started as they were, uncompiled, until it restarts. PHP-FPM and `artisan serve` load the classes again for every request, so for them it just works.

Deep trace needs no proxies: probes record against the span that is open when they run (the service span if the class is proxied, otherwise the nearest one). Proxies and Deep trace complement each other: the proxy gives you the *call* (arguments in, value out, duration), Deep trace gives you the *inside*. Models, controllers, commands and a few other classes the framework builds itself get no proxy but are still traced line by line.

## How it works

1. A prepended Composer-compatible autoloader (`spl_autoload_register(..., prepend: true)`) sees a class that qualifies. It finds the original file through Composer's `ClassLoader`.
2. `nikic/php-parser` (4.18+ and 5.x) parses the file **only to find positions**. Probes are *inserted into the original text* at those offsets; nothing is re-printed. Whitespace, comments, formatting and heredocs stay byte-for-byte the same, and no inserted text contains a line break, so **every line keeps its number** (stack traces and the dashboard line up with your editor).
3. The compiler verifies the result before it is used: same number of lines, every original token still on its original line, the code still parses. A file that fails is not used; the original loads and the dashboard shows a warning.
4. The compiled copy is cached in `storage/framework/request-tracer/compiled`, keyed by the source file's mtime, the compiler version and the package version.
5. Each probe has an id that maps to `{file, line, kind, snippet}`. The map is written once per file and read only for probes that were hit.

### What gets a probe

| Construct | Probe | Recorded |
|---|---|---|
| `if`, `elseif`, `while`, `do … while`, `for`, ternary | condition | how often it was true / false, and for comparisons (`==`, `!=`, `===`, `!==`, `<`, `<=`, `>`, `>=`) **both operands** of the first hits |
| each operand of `&&`, `\|\|`, `and`, `or`, `!` | condition | the same, per part. A part that was short-circuited has no hit, so you see it was never evaluated |
| first statement of `if` / `elseif` / `else` / `catch` / `finally` / `case` bodies, `match` arms | block | the block ran N times; a `catch` also records the exception it received, an arm its value |
| loop bodies | loop | iterations, plus a snapshot of the loop variables for the first `deep_trace.loop_snapshots` (5) iterations of a `foreach` |
| every `=` / `+=` / `.=` / `??=` … | assignment | the assigned value (first and last), recorded at the right-hand side, so reading it never triggers `__get` or an accessor |
| function / method / closure entry | arguments | the arguments the function was called with |
| `return <expr>` / `throw <expr>` | return / throw | the returned value / the exception (which is also added to the trace's exception list) |
| `match` and `switch` subjects | subject | the value (and for scalars, how often each value occurred) |
| every other plain statement (call, echo, `unset`, `break`…) | block | "this line ran" |

Brace-less bodies (`if ($a) foo();`) and the alternative syntax (`if (): … endif;`) are handled; `else if` chains and nested brace-less bodies too.

### What is never touched

Places where a function call would be a compile error: property defaults, class constants, `static` variable initialisers, parameter defaults, enum cases and attribute arguments. Top-level code outside functions. Methods and classes marked `#[NoTrace]`. Inline HTML (`?>…<?php`) is left alone and does not break; templates keep rendering. `__FILE__` and `__DIR__` are rewritten to the original location, so code that builds paths from them keeps working.

## Reading the dashboard

In a step's **Inside the code** view each line has a dot:

| Dot | Meaning |
|---|---|
| green | the line ran |
| purple | a condition on this line was false (the request did not enter that block) |
| blue | a loop (the chip shows the iteration count) |
| orange | an exception was caught here |
| red | an exception was thrown here |
| grey | **not run** — no probe on this line was ever hit |

The right-hand chip shows `true` / `false`, the number of iterations, `caught`, `not run`, or the assigned value; hover or focus a line to see the variables in `dd()` format. *Replay* steps through the executed lines in the order they first ran.

## Cost

* **Not recording**: every probe is one static call that returns immediately (value probes return their argument untouched); statement probes are `Hot::$on && …`. Comparison probes still perform the comparison, exactly as PHP would. Only the files you opted in pay this.
* **Recording**: each hit updates one counter; values are dumped (with the usual limits and masking) for the first hits only. The whole trace stops recording at `max_hits` and is marked **truncated** with a problem entry. Benchmarks are in [benchmarks](benchmarks.md).
* **Storage**: one `tracer_lines` row per (step, probe), not per hit.

## Secrets

Values are masked by **name** before they are stored: an assignment to `$password`, a comparison with `$request->token`, a `return` inside `getApiKey()` all store `{"$":"mask"}`. Source snippets are shown with their string literals masked when the statement mentions a secret name. A secret that travels under an innocent name (`$x = $token;` is masked at the assignment of `$token` but not at `$x`) cannot be recognised.

## Limitations

* A class that is **already loaded** when the autoloader runs (service providers and anything touched while the app boots) cannot be compiled. Put code you want to trace in classes that are loaded during the request.
* In a **long-lived worker** (Octane, queue workers) a class is compiled when it is first loaded; changing the opt-in list or queueing a file takes effect after the worker restarts.
* PHP reports the **compiled file's path** in `ReflectionClass::getFileName()` and raw stack traces. The package maps it back to the original everywhere it displays a path; your own exception reporters will see the cache path (the line numbers are right).
* Syntax newer than the PHP version that runs the code is a parse error either way. php-parser 4 cannot read syntax newer than PHP 8.3; update to php-parser 5 for 8.4 syntax.
* The cache directory must be writable; if it is not, the original file is used and the dashboard says so.
* With `declare(ticks=…)` or code that inspects `func_get_args()`-style internals by file position, behave as usual — probes add function calls but do not change the evaluation order of your code.
