<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use RequestTracer\Dumper\Casters;
use RequestTracer\SpanFlag;
use RequestTracer\SpanType;
use RequestTracer\Support\CallSite;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/**
 * Outgoing HTTP calls made with Laravel's HTTP client. One `http` span per call, timed from RequestSending to the response
 * (or failure). Leaf spans are used on purpose: concurrent requests (`Http::pool`) interleave and cannot be nested.
 */
final class HttpClientCollector implements Collector
{
    public static function key(): string
    {
        return 'http';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(RequestSending::class, function (RequestSending $e) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            Failure::guard(function () use ($tracer, $e): void {
                $session = $tracer->session();
                if ($session !== null) {
                    $session->bag['http'][spl_object_id($e->request)] = [$session->clock->nowUs(), CallSite::find()];
                }
            }, 'http collector (sending)');
        });

        $events->listen(ResponseReceived::class, function (ResponseReceived $e) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            Failure::guard(fn () => $this->finish($tracer, $e->request, $e->response), 'http collector (response)');
        });

        $events->listen(ConnectionFailed::class, function (ConnectionFailed $e) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            Failure::guard(fn () => $this->finish($tracer, $e->request, null), 'http collector (failure)');
        });
    }

    private function finish(Tracer $tracer, Request $request, ?Response $response): void
    {
        $session = $tracer->session();
        if ($session === null) {
            return;
        }
        $id = spl_object_id($request);
        [$startUs, $site] = $session->bag['http'][$id] ?? [$session->clock->nowUs(), CallSite::find()];
        unset($session->bag['http'][$id]);
        [$file, $line] = $site;

        $durationUs = max(0, $session->clock->nowUs() - (int) $startUs);
        $url = $this->maskUrl($tracer, $request->url());
        $parts = parse_url($url) ?: [];
        $label = $request->method().' '.($parts['host'] ?? '').($parts['path'] ?? '');

        $span = $session->recorder->leaf(SpanType::Http, $label, $durationUs, $file, $line);
        $failedFrom = (int) $tracer->config('problems.http_failed_min_status', 400);
        $status = $response?->status();
        $failed = $response === null || ($status !== null && $status >= $failedFrom);
        $session->bag['http_stats'][] = ['span' => $span->id, 'failed' => $failed, 'status' => $status, 'label' => $label, 'file' => $file, 'line' => $line];
        if ($span->ghost) {
            return;
        }

        $span->meta['status'] = $status;
        $span->meta['summary'] = $status === null ? 'Connection failed' : 'HTTP '.$status;
        if ($failed) {
            $span->flag = SpanFlag::Failed;
        }
        $span->input = $session->dump([
            'method' => $request->method(),
            'url' => $url,
            'headers' => Casters::allowlist($request->headers(), $this->allowed($tracer, 'request_headers')),
            'body' => $this->body($tracer, $request->body(), $request->data(), $request->isJson()),
        ]);
        $span->output = $response === null
            ? $session->dump(['error' => 'connection failed'])
            : $session->dump([
                'status' => $status,
                'headers' => Casters::allowlist($response->headers(), $this->allowed($tracer, 'response_headers')),
                'body' => $this->responseBody($tracer, $response),
            ]);
    }

    /** @return list<string> */
    private function allowed(Tracer $tracer, string $key): array
    {
        return array_map(static fn ($h): string => strtolower((string) $h), array_values((array) $tracer->config('capture.'.$key, [])));
    }

    /** Mask secret query parameters ("?api_key=…") and credentials in the URL. */
    private function maskUrl(Tracer $tracer, string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $tracer->masker()->maskString($url);
        }
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($query as $k => $v) {
                if ($tracer->masker()->isSensitiveKey((string) $k)) {
                    $query[$k] = $tracer->masker()->mask;
                }
            }
            $parts['query'] = http_build_query($query);
        }

        $out = (isset($parts['scheme']) ? $parts['scheme'].'://' : '')
            .(isset($parts['user']) ? $tracer->masker()->mask.'@' : '')
            .($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '').(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');

        return $out === '' ? $tracer->masker()->maskString($url) : $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return mixed array (so key masking applies) or truncated text
     */
    private function body(Tracer $tracer, string $raw, array $data, bool $json): mixed
    {
        if ($data !== []) {
            return $data;
        }
        if ($json) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->clip($tracer, $raw);
    }

    private function responseBody(Tracer $tracer, Response $response): mixed
    {
        $raw = $response->body();
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $this->clip($tracer, $raw);
    }

    private function clip(Tracer $tracer, string $text): string
    {
        $max = (int) $tracer->config('capture.body_max_bytes', 4096);

        return strlen($text) > $max ? mb_strcut($text, 0, $max, 'UTF-8').'…' : $text;
    }
}
