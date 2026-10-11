# 3. Quick start

Five minutes from `composer require` to the first trace you can learn something from.

## Step 1: install and listen

```bash
composer require your-vendor/laravel-request-tracer
php artisan request-tracer:listen
```

Leave the second command running in a terminal, the way you leave `queue:work` running. It prints a line for every request, job and command it traces, with a link to the dashboard.

Nothing else is needed: no config file, no migration, nothing to publish.

## Step 2: look at one request

Use your application as usual (or `php artisan serve` and open a page), then open `/request-tracer`.

You see a list of requests, newest first. Click one.

- **Flow** is the tree of what ran. Hover a step to preview it, click to pin it. The panel on the right shows its **Input** and **Output** in `dd()` format.
- **Database** shows which tables the request wrote and what changed (the row before and after).
- **Problems** lists what looks wrong, with a link to the step in the flow.

## Step 3: see inside your own code

Everything in `app/` is already traced: every service, listener, middleware and helper class gets a step with its arguments and return value. Pin the step of one of your classes and choose **Inside the code**: each line has a dot (green ran, purple was a false condition, grey did not run), hover a line for the variables, or press **Replay** to walk through the lines in the order they ran. More in [Deep trace](06-deep-trace-guide.md).

To trace another folder, or one file, or to leave something out, say so in `config/request-tracer.php` (publish it first with `php artisan vendor:publish --tag=request-tracer-config`). Only the keys you change need to be there:

```php
'code' => [
    'paths' => ['app', 'modules/Billing/src', 'lib/Money.php'],
    'except' => ['app/Legacy', 'App\Reports\*'],
],
```

You never list classes one by one. See [configuration](04-configuration.md#code).

To keep some **URLs** out of the traces (an admin area, an internal API, a noisy endpoint), add them to `exclude`. They are added to the built-in exclusions (login, webhooks, health checks), nothing is lost:

```php
'exclude' => [
    'paths' => ['admin/*', 'api/internal/*', 'reports*'],   // URL patterns, no leading slash
    'routes' => ['horizon.*'],                              // or route names
],
```

## Step 4: a request that already happened

The request you want to understand happened when nothing was listening? Run it again, traced:

- **Dashboard:** *Replay a request*. Paste a cURL command (in the browser's Network tab, right-click the request, *Copy → Copy as cURL (bash)*), or fill in the method, URL, headers and body. On a trace you already have, *Replay this request* fills the form in for you.
- **Terminal:**

  ```bash
  php artisan request-tracer:replay "POST /api/orders" --data='{"items":[{"product_id":1,"qty":2}]}' --as=42
  php artisan request-tracer:replay --curl="curl 'https://shop.test/api/orders' -H 'content-type: application/json' --data-raw '{...}'"
  ```

`--as=42` runs it as the user with id 42, with no login. The request goes through your application's own HTTP kernel, with all its middleware, and the new trace opens in the dashboard. By default it is a **simulation**: database changes are rolled back, e-mails and queued jobs go nowhere, and calls made with Laravel's HTTP client are answered locally. Add `--real` to run it for real (changes are kept, e-mails go out) and take it back with `php artisan request-tracer:undo <trace>`; see [Replay a request](09-using-the-dashboard.md#replay-a-request-that-already-happened) for what can and cannot be undone. Replay is off in the production environment unless you turn it on. Fields that were masked when the request was recorded (passwords, tokens) cannot be sent again: the form tells you to fill them in.

**Do not know what the request looked like?** Turn the [request journal](09-using-the-dashboard.md#the-journal) on (`REQUEST_TRACER_JOURNAL=true`). It notes every request in one light row; in the dashboard's *Journal* tab pick the one that failed, and it replays itself, with the same URL, body and user. From the terminal: `php artisan request-tracer:replay --journal=failed`.

## Step 5: add a value of your own

```php
return trace($this->calculate($order), 'order total');
```

`trace()` returns the value unchanged. While recording, it shows up as a step with that label and the value in `dd()` format. When nothing is recording it costs one boolean check.

## Stopping

Press Ctrl+C in the terminal where `request-tracer:listen` runs. Within a few seconds nothing is recorded any more, and the package costs 25 to 65 µs per request.

## In production

The same two steps, run on the server: `php artisan request-tracer:listen` in an SSH session. The dashboard needs a gate before anybody can open it:

```php
Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
```

If you cannot run the command where the application runs, one request can be traced with a signed header instead (dashboard: *Record from outside*, or `php artisan request-tracer:token`). More on that, retention and masking in [Running in production](07-running-in-production.md).

## Something not showing up?

See [Troubleshooting](13-troubleshooting-faq-upgrade.md). The usual reasons: the listen command is not running (the dashboard header says *Not listening*), the request path is excluded (`login`, `webhooks/*`, health checks), or the class is outside `code.paths`.
