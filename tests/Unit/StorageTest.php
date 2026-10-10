<?php

declare(strict_types=1);

use RequestTracer\Span;
use RequestTracer\SpanType;
use RequestTracer\Storage\PayloadCodec;
use RequestTracer\Storage\TraceStorage;
use RequestTracer\TraceData;

const TRACE_A = '01h0000000000000000000000a';
const TRACE_B = '01h0000000000000000000000b';
const TRACE_C = '01h0000000000000000000000c';
const TRACE_D = '01h0000000000000000000000d';

/** @param  array<string, mixed>  $over */
function sampleTrace(string $id, array $over = []): TraceData
{
    $root = new Span(1, null, SpanType::Request, 'GET /x', null, null, 0);
    $root->durationUs = 5000;
    $root->input = '{"a":1}';
    $root->output = json_encode(['big' => str_repeat('z', 5000)]);
    $root->open = false;
    $child = new Span(2, 1, SpanType::Query, 'select 1', 'app/Foo.php', 10, 100);
    $child->durationUs = 300;
    $child->connection = 'mysql';
    $child->meta = ['k' => 'v'];
    $child->open = false;

    return new TraceData(
        array_replace([
            'id' => $id, 'kind' => 'request', 'method' => 'GET', 'path' => '/x', 'route_name' => null, 'status' => 200,
            'duration_ms' => 5.0, 'memory_peak' => 1000, 'user_id' => '7', 'tenant' => null, 'module' => null, 'ip_hash' => null,
            'spans_count' => 2, 'queries_count' => 1, 'problems_count' => 1, 'tables_written' => '[{"table":"orders"}]', 'tables_read' => null,
            'truncated' => false, 'laravel_version' => '12.0.0', 'environment' => 'testing', 'mode' => 'always', 'parent_trace_id' => null,
            'created_at' => now(),
        ], $over),
        [$root, $child],
        [['span_id' => 2, 'file' => 'app/Foo.php', 'line' => 10, 'kind' => 'cond', 'hits' => 2, 'result' => '{"bool":true}', 'snippet' => '$a > 1']],
        [['span_id' => 2, 'connection' => 'mysql', 'table' => 'orders', 'operation' => 'update', 'row_key' => '5', 'before' => '{"s":"new"}', 'after' => '{"s":"paid"}', 'transaction' => 'committed', 'rows' => 1]],
        [['span_id' => 2, 'kind' => 'slow_query', 'message' => 'slow', 'file' => 'app/Foo.php', 'line' => 10]],
    );
}

it('round-trips a trace with all child tables', function () {
    $storage = app(TraceStorage::class);
    $storage->store(sampleTrace(TRACE_A));

    $trace = $storage->trace(TRACE_A);
    expect($trace['path'])->toBe('/x')
        ->and($trace['tables_written'])->toBe([['table' => 'orders']])
        ->and($trace['problems_count'])->toBe(1);

    $spans = $storage->spans(TRACE_A);
    expect($spans)->toHaveCount(2)
        ->and($spans[1]['connection'])->toBe('mysql')
        ->and($spans[1]['meta'])->toBe(['k' => 'v'])
        ->and($spans[0]['has_input'])->toBeTrue()
        ->and($spans[1]['has_input'])->toBeFalse();

    expect($storage->payload(TRACE_A, 1)['output'])->toContain(str_repeat('z', 5000));
    expect($storage->lines(TRACE_A)[0]['result'])->toBe(['bool' => true]);
    expect($storage->writes(TRACE_A)[0])->toMatchArray(['table' => 'orders', 'operation' => 'update', 'before' => ['s' => 'new'], 'after' => ['s' => 'paid']]);
    expect($storage->problems(TRACE_A)[0]['kind'])->toBe('slow_query');
});

it('compresses large payloads and leaves small ones readable', function () {
    $codec = new PayloadCodec(1024);
    $big = json_encode(['x' => str_repeat('a', 4000)]);

    expect($codec->encode('{"a":1}'))->toBe('{"a":1}')
        ->and(str_starts_with((string) $codec->encode($big), 'gz:'))->toBeTrue()
        ->and(strlen((string) $codec->encode($big)))->toBeLessThan(strlen($big))
        ->and($codec->decode($codec->encode($big)))->toBe($big)
        ->and($codec->encode(null))->toBeNull();
});

it('lists traces newest first with cursor pagination and filters', function () {
    $storage = app(TraceStorage::class);
    $storage->store(sampleTrace(TRACE_A, ['path' => '/a', 'status' => 200, 'problems_count' => 0]));
    $storage->store(sampleTrace(TRACE_B, ['path' => '/b', 'status' => 500, 'problems_count' => 2]));
    $storage->store(sampleTrace(TRACE_C, ['path' => '/c', 'status' => 404, 'problems_count' => 0, 'duration_ms' => 900.0]));

    $page1 = $storage->traces([], null, 2);
    expect(array_column($page1['data'], 'path'))->toBe(['/c', '/b'])->and($page1['next_cursor'])->not->toBeNull();

    $page2 = $storage->traces([], $page1['next_cursor'], 2);
    expect(array_column($page2['data'], 'path'))->toBe(['/a'])->and($page2['next_cursor'])->toBeNull();

    expect(array_column($storage->traces(['has_problems' => true])['data'], 'path'))->toBe(['/b'])
        ->and(array_column($storage->traces(['status' => '4xx'])['data'], 'path'))->toBe(['/c'])
        ->and(array_column($storage->traces(['slow' => true, 'slow_ms' => 500])['data'], 'path'))->toBe(['/c'])
        ->and(array_column($storage->traces(['search' => '/a'])['data'], 'path'))->toBe(['/a']);
});

it('prunes by age and by max rows, including child tables', function () {
    $storage = app(TraceStorage::class);
    $storage->store(sampleTrace(TRACE_A, ['created_at' => now()->subDays(3)]));
    $storage->store(sampleTrace(TRACE_B, ['created_at' => now()->subHour()]));
    $storage->store(sampleTrace(TRACE_C));
    $storage->store(sampleTrace(TRACE_D));

    expect($storage->prune(now()->subDay(), 0))->toBe(1)
        ->and($storage->count())->toBe(3);

    expect($storage->prune(now()->subYear(), 2))->toBe(1)      // keep the 2 newest
        ->and($storage->count())->toBe(2)
        ->and($this->tracerTable('spans')->count())->toBe(4)
        ->and($this->tracerTable('writes')->where('trace_id', TRACE_B)->count())->toBe(0);
});
