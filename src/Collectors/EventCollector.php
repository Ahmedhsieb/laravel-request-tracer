<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use RequestTracer\SpanType;
use RequestTracer\Support\Failure;
use RequestTracer\Support\Locator;
use RequestTracer\Support\Paths;
use RequestTracer\Tracer;

/**
 * Application events with the list of listeners that handled them.
 *
 * Laravel runs wildcard listeners after the event's specific listeners and offers no before/after hook, so an event span
 * is an instant: it carries the payload and the listeners, but not their duration. Work done by listeners shows up as
 * the spans (queries, service calls...) that precede it; listener classes in a proxied namespace get their own `handle` span.
 */
final class EventCollector implements Collector
{
    /** @var array<string, bool> event name => ignored? */
    private array $ignored = [];

    public static function key(): string
    {
        return 'events';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen('*', function (string $eventName, array $payload) use ($tracer, $events): void {
            if (! $tracer->on) {
                return;
            }
            try {
                $this->handle($tracer, $events, $eventName, $payload);
            } catch (\Throwable $e) {
                Failure::report($e, 'event collector');
            }
        });
    }

    /** @param  array<int, mixed>  $payload */
    private function handle(Tracer $tracer, Dispatcher $events, string $name, array $payload): void
    {
        if ($this->ignored[$name] ??= $this->isIgnored($tracer, $name)) {
            return;
        }
        $session = $tracer->session();
        if ($session === null) {
            return;
        }

        $listeners = [];
        if (method_exists($events, 'getListeners')) {
            foreach ((array) $events->getListeners($name) as $listener) {
                $described = $this->describe($listener);
                if ($described !== null) {
                    $listeners[] = $described;
                }
            }
        }

        $object = $payload[0] ?? null;
        [$file, $line] = is_object($object) ? Locator::classFile($object::class) : [null, null];
        $span = $session->recorder->leaf(SpanType::Event, $name, 0, $file, $line);
        if ($span->ghost) {
            return;
        }
        $span->meta['listeners'] = array_slice($listeners, 0, 20);
        $span->meta['summary'] = count($listeners).' '.(count($listeners) === 1 ? 'listener' : 'listeners');
        $span->input = $session->dump([
            'event' => is_object($object) && count($payload) === 1 ? $object : $payload,
            'listeners' => $listeners,
        ]);
    }

    private function isIgnored(Tracer $tracer, string $name): bool
    {
        if (count($this->ignored) > 2000) {
            $this->ignored = [];
        }

        return Str::is((array) $tracer->config('capture.ignore_events', []), $name);
    }

    private function describe(mixed $listener): ?string
    {
        if (! $listener instanceof \Closure) {
            return Locator::describeCallable($listener);
        }

        try {
            $r = new \ReflectionFunction($listener);
            // listeners registered by class name are wrapped by the dispatcher; the original is a captured variable
            $vars = $r->getStaticVariables();
            $original = $vars['listener'] ?? null;
            if (is_string($original)) {
                return str_contains($original, '@') ? $original : $original.'@handle';   // Laravel's default listener method
            }
            if (is_array($original)) {
                return Locator::describeCallable($original);
            }
            $file = $r->getFileName() ?: '';
            if ($file !== '' && str_starts_with(str_replace('\\', '/', $file), rtrim((string) Paths::normalize(dirname(__DIR__)), '/').'/')) {
                return null;                                   // our own wildcard listener
            }

            return Locator::describeCallable($original instanceof \Closure ? $original : $listener);
        } catch (\Throwable) {
            return 'Closure';
        }
    }
}
