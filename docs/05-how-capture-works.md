# How capture works

> Status: written phase by phase. This page currently covers the core (spans, recorder, value format, masking). Collectors, proxies and Deep trace are added to it by their phases.

A trace is a **tree of spans**. Every collector produces spans into one `Recorder`; at `terminate()` the finished tree
is written to storage. Nothing is written while the request runs.

## Spans

| Field | Meaning |
|---|---|
| `id`, `parent_id` | per-trace integers; the tree is rebuilt from them |
| `type` | `request`, `command`, `middleware`, `controller`, `validation`, `service`, `query`, `model`, `http`, `event`, `job`, `exception`, `cache`, `log` |
| `label` | what the dashboard shows (`GET /api/orders`, `OrderService::create`, the SQL text...) |
| `file`, `line` | where it happened in *your* code (relative to the application base path) |
| `start_us`, `duration_us` | microseconds since the start of the request. Time spent by the tracer itself (dumping values) is subtracted. |
| `memory_delta` | bytes of PHP memory allocated while the span was open |
| `input`, `output` | the captured values as JSON (see below), loaded lazily by the dashboard |
| `flag` | `slow`, `repeated`, `caught`, `failed` or none |
| `connection` | database connection for queries |
| `count` | `> 1` when identical spans were collapsed into this one |

## Caps and collapsing (large applications)

* `limits.max_spans` (default 5 000): after this many stored spans new spans become *ghosts* – they are timed and counted but not stored, and everything that happens inside a ghost is swallowed. The trace is marked `truncated` and gets a `truncated` problem.
* `limits.collapse_after` (default 20): the 21st identical sibling (same parent, type and label) and all later ones merge into one aggregate span with `count` and the *total* time, flagged `repeated`.
* Counters (queries, writes, problems) are independent of spans, so detection stays correct when spans are collapsed.

## The value format (`input` / `output`)

Values are converted with Symfony's `VarCloner` and a custom dumper into a compact JSON tree. Plain JSON scalars stand for themselves; everything else is a tagged node (`"$"` is the tag):

```jsonc
"text"  42  1.5  true  null                         // scalars
{"$":"str","v":"first 2 KB…","cut":3912}            // truncated string
{"$":"arr","n":3,"list":true,"i":[1,2,3]}           // list;  "n" is the real size
{"$":"arr","n":2,"i":[["a",1],["b",2]],"cut":40}    // map; "cut" = items not stored
{"$":"obj","c":"App\\Models\\Order","kind":"model","i":[["attributes","virtual",{…}]]}
{"$":"mask"}                                        // a secret; the real value was never stored
{"$":"type","t":"Closure"}                          // closures, resources and PDO: type name only
{"$":"bin","len":2048}  {"$":"float","v":"INF"}  {"$":"int","v":"9223372036854775807"}
```

Limits (all in `config/request-tracer.php` → `dumper`): depth 3, 50 items per array/collection, 2 KB per string, 64 KB per value, 16 MB per trace. A value over its limit is shortened and marked (`cut`); a value over 64 KB is re-dumped at lower depth and finally reduced to a type marker; after the per-trace ceiling every value is stored as type and size only.

Eloquent models are stored as class, key, `attributes` (raw, no accessors – so dumping never runs queries) and the *names* of loaded relations. Collections as `count` plus the first items. `$hidden` attributes are masked.

## Masking

Masking happens **inside the dumper, while the value is converted**; the raw secret is never written to a structure, a JSON string or a database column.

* A key is sensitive when it matches an entry of `masking.keys` as whole words: `api_key`, `apiKey` and `api-key` are the same key; `credit_card_number` matches `card`; `company` does **not** match `pan`. Entries of five or more characters also match inside run-together keys (`accesstoken`). Entries starting with `/` are regular expressions.
* A sensitive key replaces its whole value (even a nested array) with `{"$":"mask"}`.
* `masking.value_patterns` scrub secret-looking *values* (default: `Bearer …` tokens) inside strings.
* Request/response headers use an allowlist (`capture.request_headers`, `capture.response_headers`); everything else is dropped, not masked.
* The test-suite asserts that none of the five trace tables ever contains a masked value.
