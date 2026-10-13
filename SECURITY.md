# Security policy

Request Tracer records what your application does, so its security model matters. This page describes the guarantees, the known limits and how to report a problem.

## Guarantees

* **Closed by default.** Outside the `local` environment the dashboard and the JSON API return `403` unless the application defines the `viewRequestTracer` gate. There is no built-in user or token that bypasses it.
* **Recording is opt-in in production.** `mode` defaults to `triggered` there: a request is recorded only with a valid signed header, for a watched user, or by sampling (off by default). Unauthenticated requests are never kept unless the signed header is valid.
* **Signed tokens.** `X-Request-Tracer` tokens are `HMAC-SHA256` over their payload (expiry, optional user id, optional path pattern) with a key derived from `APP_KEY`; verified in constant time, one hour at most, ignored silently when invalid (debug log only). A token authorises *recording the holder's own requests*; it never grants access to stored traces.
* **Secrets never reach storage.** Values are masked while they are converted (before any structure, JSON string or database row exists). Headers use an allowlist. The test suite asserts that masked values do not appear in any column of the five tables.
* **Never breaks a request.** Every tracer code path is guarded; failures go to the `request-tracer` log channel and are swallowed. Writes happen after the response was sent.
* **The dashboard only reads what a trace contains.** Source code shown for Deep trace is read only from files that appear in that trace's own line data.

## Known limits

* Masking is name based. A secret under an innocent name (or inside free text) is stored as is; review `masking.keys` and keep the gate narrow.
* A trace contains your SQL, request/response bodies (masked), and for Deep trace the source of the functions that ran. Anyone who passes the gate can read it. Do not put the gate on "any logged-in user" in production.
* Generated code (proxies, compiled Deep trace copies) is written to `storage/framework/request-tracer`. Anyone who can write to that directory can run code in your application; protect `storage/` as you already do.
* Proxy classes change `get_class()` / `static::class` of proxied services.

## Reporting a vulnerability

Please do **not** open a public issue. Email the maintainers at `security@ahmedhsieb.example` (replace with the real address when you publish) with a description, the affected version and, if possible, a reproduction. You will get an acknowledgement within 72 hours and a fix or mitigation plan within 14 days. Supported versions: the latest minor release.
