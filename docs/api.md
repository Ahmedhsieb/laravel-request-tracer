# JSON API (v1)

The dashboard is a client of this API. It lives under `<dashboard.path>/api/v1` (default `/request-tracer/api/v1`), uses the same middleware as the dashboard (`web` + the `viewRequestTracer` gate), and authenticates with the session cookie; mutating requests need the CSRF token (`X-CSRF-TOKEN` / `X-XSRF-TOKEN`). Responses are JSON. Errors: `403` (gate), `404` (unknown trace/step), `422` (validation).

`created_at` is an ISO 8601 timestamp with its offset (`2026-10-11T05:27:10+00:00`); it is stored in UTC and `from` / `to` are converted to UTC before they are compared.

Step **payloads are never part of the tree**: `spans` returns structure only; `spans/{id}` returns one step's input/output (the dumper format described in *How capture works*).

| Method & path | Purpose |
|---|---|
| `GET meta` | environment, `is_production`, recording mode, **`listening`** (the listen command is running), `listen_command`, **`replay.enabled`**, **`code`** (`paths`, `except`, `line_by_line`), trigger settings, signed-in user, thresholds, mask string |
| `GET traces` | list, newest first. Query: `limit` (≤200), `cursor`, `search`, `status` (`200` or `5xx`), `min_ms`, `max_ms`, `slow`, `has_problems`, `user_id`, `tenant`, `module`, `kind` (`request`/`job`/`command`/`schedule`), `method`, `route_name`, `from`, `to`, `parent_trace_id`, `deep_search` (also match step labels). Returns `{data, next_cursor}`; each row has `problem_kinds` |
| `GET traces/{id}` | one trace summary (`tables_written`, `tables_read`, `notes`, counters) |
| `GET traces/{id}/spans` | the step list without payloads: `id, parent_id, type, label, file, line, start_us, duration_us, memory_delta, flag, connection, count, meta, has_input, has_output` |
| `GET traces/{id}/spans/{span}` | `{input: {value}|null, output: {value}|null}` |
| `GET traces/{id}/spans/{span}/lines` | Deep trace rows of the step and `sources`: the source of each function that ran (`file, name, start, end, lines[{n,text}], statements, missing, changed_since_trace`; `statements` lists the lines that carry a probe, `null` when the file could not be analysed). Only files that appear in this trace's own line data are ever read |
| `GET traces/{id}/lines` | all Deep trace rows of the trace |
| `GET traces/{id}/writes` | rows written: `table, operation, row_key, before, after, transaction (none/committed/rolled_back/open), rows` |
| `GET traces/{id}/problems` | `kind, message, file, line, span_id` |
| `DELETE traces/{id}` / `DELETE traces` | delete one / all traces |
| `GET journal` | the request journal, newest first. Query: `limit` (≤200), `cursor`, `status` (`failed`, `errors` or a status code), `search` (URL, user, error). Returns `{data, next_cursor}`; a row has `id, method, url, route_name, status, duration_ms, user_id, failure, has_body, body_cut, masked, trace_id, created_at` (no headers, no body) |
| `GET journal/{id}` | one entry in full: the row plus `headers` and `body` (masked), what the replay form is filled in from |
| `DELETE journal` | empty the journal |
| `POST replay` | Run a request that already happened again, traced. Body: `curl` (a cURL command) **or** `method`, `url`, `headers` (object), `body`; optional `as_user` (the id of the user to act as). Optional `mode`: `simulate` (the default: nothing is changed) or `real` (changes are kept; needs `confirm_real: true`, and `replay.real` to allow it, else `403`/`422`). The request runs in a PHP process of its own. `200` with `{data: {trace_id, status, error: null, mode, undo}}`, where `undo` (real runs) is `{available, entries, tables, uncovered, expires_at}`: counts only, never values; `422` with `data.error` when it failed (no such user, not a cURL command, ...); `403` where replay is off. |
| `GET traces/{id}/undo` | What can still be undone for the trace of a replay that ran for real: `{available, entries, tables: {table: {insert, update, delete}}, uncovered: {table: statements}, expires_at}`, or `null`. `403` where replay is off. |
| `POST traces/{id}/undo` | Take that replay's recorded changes back, last first, all or nothing. Body: `{force?: bool}`. `200` with `{applied, undone, conflicts: [{table, key, operation, reason}], error}`: `applied: false` with conflicts means a row changed after the replay and nothing was touched; `force: true` undoes anyway. `422` with `data.error` when there is nothing to undo or the undo failed (then nothing was changed). |
| `POST tokens` | `{ttl?, user_id?, route?}` → `{token, expires_at, header, example}` |
| `GET watch` / `POST watch` / `DELETE watch/{user}` | the watch list: `{user_id, minutes?}` |
| `GET deep-trace/queue` / `POST` / `DELETE` | files queued for Deep trace: body `{file}` (a path relative to the application, as shown in the dashboard) |

Pagination is cursor based: pass the previous response's `next_cursor`. Trace ids are ULIDs, so they sort by time.

The version is part of the path; additive changes keep `v1`, breaking changes get `v2` and `v1` stays for one minor release.
