# 3. Quick start

Ten minutes from `composer require` to the first trace you can learn something from. This page uses the `local` environment; at the end it shows the production version.

## Step 1: install

```bash
composer require your-vendor/laravel-request-tracer
php artisan request-tracer:install --migrate
```

## Step 2: look at one request

Use your application as usual, or run `php artisan serve` and open any page. Then open `/request-tracer`.

You see a list of requests, newest first. Click one.

- **Flow** is the tree of what ran. Hover a step to preview it, click to pin it. The panel on the right shows its **Input** and **Output** in `dd()` format.
- **Database** shows which tables the request wrote and what changed.
- **Problems** lists what looks wrong, with a link to the step in the flow.

## Step 3: see inside your own code

By default you see framework events (queries, events, HTTP calls) plus the controller. To see your services, name their namespaces:

```php
// config/request-tracer.php
'proxies' => [
    'namespaces' => ['App\Services', 'App\Domain\*'],
],
```

Reload the page that uses them. Every call to a class in those namespaces is now a step with its arguments and return value.

> The trace is only as detailed as you ask. Namespaces opt classes in; the attribute `#[Trace]` opts in one class or method; `trace($value, 'label')` records any value anywhere.

## Step 4: line by line (Deep trace)

Pick the class whose `if` statements you want to understand:

```php
'deep_trace' => [
    'namespaces' => ['App\Services\OrderService'],
],
```

Reload the page, open the trace, pin the `OrderService::create` step and choose **Inside the code**. Each line has a dot: green ran, purple was a false condition, grey never ran. Hover a line for the variables, or press **Replay** to walk through the lines in the order they ran. More in [Deep trace](06-deep-trace-guide.md).

No config edit needed to try it on one file: in the dashboard, open a step that has no line data and press **Trace this file line by line**; the next request that runs the file is traced.

## Step 5: add a value of your own

```php
return trace($this->calculate($order), 'order total');
```

`trace()` returns the value unchanged. While recording, it shows up as a step with that label and the value in `dd()` format. When nothing is recording it costs one boolean check.

## The production version

In production nothing is recorded by default. To look at one request:

1. Define the gate ([Installation](02-installation.md#3-open-the-dashboard)) and open the dashboard.
2. Click **Record a request**, choose how long the header should be valid (and optionally which user or route), and copy the header.
3. Send one request with it:

   ```bash
   curl -H 'X-Request-Tracer: <token>' https://shop.example.com/orders
   ```

   or from the command line: `php artisan request-tracer:token --ttl=300 --route='orders*'`.
4. The request appears in the list. Anyone without the header is not recorded, and costs the application about 25 µs.

To follow a user for a while instead, use **Watch a user** in the same dialog. More on triggers, retention and masking in [Running in production](07-running-in-production.md).

## Something not showing up?

See [Troubleshooting](13-troubleshooting-faq-upgrade.md): the most common reasons are a missing gate, a request on an excluded path (`login`, `webhooks/*`, health checks), `mode` set to `triggered` in a non-local environment, or a namespace that is not listed under `proxies.namespaces`.
