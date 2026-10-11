<?php

declare(strict_types=1);

namespace RequestTracer\Console;

use Illuminate\Console\Command;
use RequestTracer\Recording\Listening;
use RequestTracer\Replay\CurlParser;
use RequestTracer\Replay\Replayer;
use RequestTracer\Replay\ReplayRequest;
use RequestTracer\Support\App;
use RequestTracer\Support\Brand;

/**
 * `php artisan request-tracer:replay "POST /orders" --data='{"items":[1]}' --as=42`: run a request that already happened again, traced.
 * Paste a cURL command instead (`--curl="curl 'https://...' -H ... --data-raw ..."`) to repeat what a browser or a client sent.
 * Database changes are rolled back; see the Replayer for what that does and does not cover.
 */
final class ReplayCommand extends Command
{
    protected $signature = 'request-tracer:replay
                            {request? : "POST /orders", "GET /orders?page=2" or a full URL}
                            {--curl= : A cURL command to repeat (browser: network tab, "Copy as cURL (bash)")}
                            {--data= : The request body (JSON or form data)}
                            {--header=* : A header, "Name: value" (repeat the option for more)}
                            {--as= : Act as the user with this id (no login needed)}
                            {--payload-file= : (internal) a JSON file that describes the request}
                            {--json : (internal) print the result on a line a program can read}';

    protected $description = 'Run a request that already happened again, traced (database changes are rolled back)';

    public function handle(): int
    {
        if (! Replayer::allowed()) {
            return $this->failWith('Replay is switched off in this environment. Set request-tracer.replay.enabled to true to allow it.');
        }

        try {
            $request = $this->buildRequest();
        } catch (\InvalidArgumentException $e) {
            return $this->failWith($e->getMessage());
        }

        $replayer = App::container()->make(Replayer::class);
        // A process that has served other requests cannot compile its code for line-by-line tracing any more: do it in a new one.
        $result = Listening::flagged() ? $replayer->run($request) : $replayer->runInChild($request);

        if ($this->option('json')) {
            $this->line(Replayer::RESULT_PREFIX.json_encode($result));

            return $result['error'] === null ? self::SUCCESS : self::FAILURE;
        }

        if ($result['error'] !== null) {
            return $this->failWith($result['error']);
        }

        $this->components->info(sprintf('%s %s answered %s. Database changes were rolled back.', $request->method, $request->url, $result['status'] ?? '?'));
        if ($result['trace_id'] !== null) {
            $this->line('  Trace: <fg=cyan>'.rtrim((string) config('app.url', 'http://localhost'), '/').'/'.trim((string) config(Brand::NAME.'.dashboard.path', Brand::NAME), '/').'/trace/'.$result['trace_id'].'</>');
        } else {
            $this->components->warn('The request ran, but no trace was stored (is the path excluded, or the package disabled?).');
        }

        return self::SUCCESS;
    }

    private function buildRequest(): ReplayRequest
    {
        $as = $this->option('as');
        $as = is_string($as) && $as !== '' ? $as : null;

        $file = $this->option('payload-file');
        if (is_string($file) && $file !== '') {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('The payload file could not be read.');
            }
            $request = ReplayRequest::fromArray($decoded);
        } elseif (is_string($this->option('curl')) && $this->option('curl') !== '') {
            $request = CurlParser::parse((string) $this->option('curl'));
        } elseif (is_string($this->argument('request')) && $this->argument('request') !== '') {
            $data = $this->option('data');
            $request = ReplayRequest::fromLine((string) $this->argument('request'), is_string($data) && $data !== '' ? $data : null);
        } else {
            throw new \InvalidArgumentException('Say what to replay: a request ("POST /orders") or --curl="curl ...".');
        }

        $headers = $request->headers;
        foreach ((array) $this->option('header') as $line) {
            [$name, $value] = array_pad(explode(':', (string) $line, 2), 2, '');
            if (trim($name) !== '') {
                $headers[trim($name)] = trim($value);
            }
        }
        $data = $this->option('data');
        $request = new ReplayRequest($request->method, $request->url, $headers, $request->body ?? (is_string($data) && $data !== '' ? $data : null), $request->asUser);

        return $as !== null ? $request->withUser($as) : $request;
    }

    private function failWith(string $message): int
    {
        if ($this->option('json')) {
            $this->line(Replayer::RESULT_PREFIX.json_encode(['trace_id' => null, 'status' => null, 'error' => $message]));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
