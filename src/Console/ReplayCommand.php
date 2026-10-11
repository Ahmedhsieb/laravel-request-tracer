<?php

declare(strict_types=1);

namespace RequestTracer\Console;

use Illuminate\Console\Command;
use RequestTracer\Journal\Journal;
use RequestTracer\Journal\JournalStorage;
use RequestTracer\Recording\Listening;
use RequestTracer\Replay\CurlParser;
use RequestTracer\Replay\Replayer;
use RequestTracer\Replay\ReplayRequest;
use RequestTracer\Support\App;
use RequestTracer\Support\Brand;

/**
 * `php artisan request-tracer:replay "POST /orders" --data='{"items":[1]}' --as=42`: run a request that already happened again, traced.
 * Paste a cURL command instead (`--curl="curl 'https://...' -H ... --data-raw ..."`) to repeat what a browser or a client sent.
 * A simulation by default (nothing is changed); `--real` runs it for real, and `request-tracer:undo <trace>` takes the Eloquent writes back.
 * See the Replayer for what each mode does and does not cover.
 */
final class ReplayCommand extends Command
{
    protected $signature = 'request-tracer:replay
                            {request? : "POST /orders", "GET /orders?page=2" or a full URL}
                            {--curl= : A cURL command to repeat (browser: network tab, "Copy as cURL (bash)")}
                            {--journal= : An entry of the request journal: its id, `last` or `failed` (the newest request that failed)}
                            {--data= : The request body (JSON or form data)}
                            {--header=* : A header, "Name: value" (repeat the option for more)}
                            {--as= : Act as the user with this id (no login needed)}
                            {--real : Run it for real: changes are kept, e-mails and jobs are sent (take it back with request-tracer:undo <trace>)}
                            {--force : Do not ask before running for real}
                            {--payload-file= : (internal) a JSON file that describes the request}
                            {--json : (internal) print the result on a line a program can read}';

    protected $description = 'Run a request that already happened again, traced (a simulation by default: nothing is changed; --real keeps the changes)';

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

        if ($request->isReal()) {
            if (! Replayer::realAllowed()) {
                return $this->failWith('Running a replay for real is switched off in this environment. Set request-tracer.replay.real to true to allow it.');
            }
            $internal = is_string($this->option('payload-file')) && $this->option('payload-file') !== '';
            if (! $internal && ! $this->option('force') && ! $this->confirm('This runs the request FOR REAL: database changes are kept, e-mails and jobs are sent, other services are called. Continue?', false)) {
                return self::FAILURE;
            }
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

        $this->components->info(sprintf('%s %s answered %s. %s', $request->method, $request->url, $result['status'] ?? '?', $request->isReal() ? 'It ran for real: its changes were kept.' : 'Simulation: nothing was changed.'));
        if ($request->isReal() && is_array($result['undo'] ?? null) && $result['trace_id'] !== null) {
            $undo = $result['undo'];
            $uncovered = (array) ($undo['uncovered'] ?? []);
            if (($undo['entries'] ?? 0) > 0) {
                $this->line('  To take it back: <fg=cyan>php artisan request-tracer:undo '.$result['trace_id'].'</>');
            }
            if ($uncovered !== []) {
                $this->components->warn('Cannot be undone (query builder or raw SQL): '.implode(', ', array_map(static fn ($t, $n): string => $t.' ('.$n.')', array_keys($uncovered), $uncovered)));
            }
        }
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
        } elseif (is_string($this->option('journal')) && $this->option('journal') !== '') {
            $request = $this->fromJournal((string) $this->option('journal'));
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
        $request = new ReplayRequest($request->method, $request->url, $headers, $request->body ?? (is_string($data) && $data !== '' ? $data : null), $request->asUser, $request->mode);
        $request = $this->option('real') ? $request->withMode(ReplayRequest::REAL) : $request;

        return $as !== null ? $request->withUser($as) : $request;
    }

    private function fromJournal(string $which): ReplayRequest
    {
        $storage = App::container()->make(JournalStorage::class);
        $entry = match ($which) {
            'last' => $storage->latest(),
            'failed' => $storage->latest(true),
            default => $storage->find($which),
        };
        if ($entry === null) {
            throw new \InvalidArgumentException($which === 'last' || $which === 'failed' ? 'The journal has no such request yet (is `journal.enabled` on?).' : 'There is no journal entry with id '.$which.'.');
        }

        // the user is who it was; a masked field cannot be sent again, which the replay of a trace says too
        return Journal::toReplayRequest($entry);
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
