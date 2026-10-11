# 12. How it compares

Laravel already has good tools. This page says what Request Tracer does differently, where the others are the better choice, and what it costs you. It describes the other tools as we understand them at the time of writing; check their documentation before you decide, they change.

## In one table

| | Request Tracer | Laravel Telescope | Laravel Debugbar | Clockwork |
|---|---|---|---|---|
| Main idea | Explain **one request** end to end, down to the line | Watch what the application does over time (requests, jobs, mail, exceptions...) | A toolbar on the page you are looking at | Timeline and data of a request, shown in browser tools or a standalone app |
| Per-step input and output in `dd()` format | Yes, for the services you opt in | Not a goal | Partly (views, queries, messages) | Partly (events, queries, log) |
| Line by line: conditions, blocks, variables | **Yes** (Deep trace, opt-in per class) | No | No | No |
| Tables written with before and after | **Yes** | Model events are watched; no before/after grid | No | No |
| Problems list (N+1, slow, caught exceptions, failed HTTP) | Yes | Some (slow queries are tagged) | Warnings for some (duplicate queries) | Some (N+1 and slow query detection) |
| Meant for production | Yes: **off by default**, per-request triggers | Possible with filtering and a gate; records broadly | Local only | Local and, with care, elsewhere |
| Watchers for mail, notifications, schedule, gates, redis, dumps | Jobs, events, cache, logs, HTTP, queries, commands and scheduled closures; **no** mail, notification, gate or redis views | **Yes**, a dedicated screen for each | Some | Some |
| Where data lives | Your database (five tables) | Your database | In the page / a storage folder | Files or a database |
| Maturity | New (`0.x`) | Mature, maintained by the Laravel team | Mature | Mature |

## Choose Request Tracer when

- you need to know **why** one request behaved as it did, not only what it touched: which branch was taken, what a service received, what changed in which table;
- you want to look at a **production** request without leaving a recorder running: run the listen command for as long as you look, or send one request with a signed header; both are gone after the retention window;
- the request already happened and you only have its cURL: **run it again, traced**, with its database changes rolled back;
- the application is **large** (modules, tenants, several databases) and "record everything" is not an option.

## Choose something else when

- **You want a live feed of everything** that happens in a development environment, including mail, notifications, scheduled tasks and cache hits as separate screens: **Telescope** is built for that and does it well.
- **You want a quick glance in the page** while you build a view: **Debugbar** puts the numbers right under your cursor, with no dashboard to open.
- **You prefer browser developer tools** and a timeline, or you debug APIs without a page: **Clockwork**'s extension and app are very good at that.
- **You need fleet-wide numbers** (error rates, percentiles, traces across services): that is an APM's job. Request Tracer explains one request and does not aggregate.

They also work side by side. Nothing stops you from using Telescope in local development and Request Tracer on staging and production.

## What Request Tracer costs you

Honest list:

- **You have to start it.** Nothing is recorded until `request-tracer:listen` runs (or a header, a watched user or a sample asks for it). That is the price of not recording everything, in production or on a laptop.
- **Proxies change class identity.** A proxied service is a generated subclass: `get_class()` and `static::class` show the proxy name, and final classes, final methods, private methods, static methods and objects created with `new` cannot be proxied. The dashboard says "not traceable: final" when it meets one.
- **Line-by-line tracing compiles a copy of your files** (the originals are never modified), and only while somebody listens. Classes a long-running worker had loaded before that are not compiled, and the compiled path shows up in raw reflection and stack traces. Details in [Deep trace](06-deep-trace-guide.md).
- **Masking is name based.** A secret held under an innocent name is stored. See [Security](../SECURITY.md).
- **Timing precision.** Event listeners are listed but their individual durations are not measured. Row counts for reads are exact only for rows hydrated through Eloquent.
- **It is new.** It has not been used by as many applications as the tools above. Version `0.x` means the stored format and the API may still change between minor versions; the [changelog](../CHANGELOG.md) says when.
