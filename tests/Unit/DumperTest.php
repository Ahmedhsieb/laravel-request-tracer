<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Dumper\DumpLimits;
use Ahmedhsieb\RequestTracer\Dumper\Masker;
use Ahmedhsieb\RequestTracer\Dumper\ValueDumper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

function makeDumper(array $limits = [], array $keys = ['password', 'secret', 'token', 'api_key', 'card', 'pan']): ValueDumper
{
    $l = new DumpLimits(
        $limits['depth'] ?? 3,
        $limits['items'] ?? 50,
        $limits['string'] ?? 2048,
        $limits['span'] ?? 65536,
        $limits['ceiling'] ?? 16_777_216,
    );

    return new ValueDumper(new Masker($keys, '••••••••', ['/Bearer\s+[A-Za-z0-9._-]+/']), $l, [
        'request_headers' => ['accept', 'content-type'],
        'response_headers' => ['content-type'],
    ]);
}

it('dumps scalars as plain JSON values', function () {
    $d = makeDumper();

    expect($d->dumpToStructure(5))->toBe(5)
        ->and($d->dumpToStructure('hi'))->toBe('hi')
        ->and($d->dumpToStructure(null))->toBeNull()
        ->and($d->dumpToStructure(true))->toBeTrue()
        ->and($d->dumpToStructure(1.5))->toBe(1.5)
        ->and($d->dump(null))->toBe('null');
});

it('dumps arrays as lists or maps', function () {
    $d = makeDumper();

    expect($d->dumpToStructure([1, 2, 3]))->toBe(['$' => 'arr', 'n' => 3, 'list' => true, 'i' => [1, 2, 3]])
        ->and($d->dumpToStructure(['a' => 1, 'b' => [true]]))->toBe([
            '$' => 'arr', 'n' => 2,
            'i' => [['a', 1], ['b', ['$' => 'arr', 'n' => 1, 'list' => true, 'i' => [true]]]],
        ])
        ->and($d->dumpToStructure([]))->toBe(['$' => 'arr', 'n' => 0]);
});

it('limits depth, item count and string length and marks truncation', function () {
    $d = makeDumper(['depth' => 2, 'items' => 3, 'string' => 20]);

    $deep = $d->dumpToStructure(['a' => ['b' => ['c' => ['d' => 1]]]]);
    expect($deep['i'][0][1]['i'][0][1])->toHaveKey('cut');

    $many = $d->dumpToStructure(range(1, 10));
    expect($many['n'])->toBe(10)->and($many['i'])->toHaveCount(3)->and($many['cut'])->toBe(7);

    $str = $d->dumpToStructure(str_repeat('x', 100));
    expect($str)->toHaveKeys(['$', 'v', 'cut'])->and($str['$'])->toBe('str');

    expect($d->truncated())->toBeTrue();
});

it('masks secret keys at any depth and never emits the value', function () {
    $d = makeDumper();
    $json = $d->dump([
        'user' => 'ann',
        'password' => 'hunter2',
        'nested' => ['apiKey' => 'sk_live_123', 'ok' => 1, 'cards' => ['4111111111111111']],
        'headers' => ['Authorization' => 'Bearer abc.def'],
    ]);

    expect($json)->not->toContain('hunter2')
        ->and($json)->not->toContain('sk_live_123')
        ->and($json)->not->toContain('4111111111111111')
        ->and($json)->toContain('"$":"mask"')
        ->and($json)->toContain('ann')
        ->and($d->masked())->toBeTrue();
});

it('masks bearer tokens inside string values', function () {
    $json = makeDumper()->dump(['note' => 'sent Authorization-less call with Bearer abc123.def to server']);

    expect($json)->not->toContain('abc123.def')->and($json)->toContain('••••••••');
});

it('masks object properties by name', function () {
    $obj = new class
    {
        public string $name = 'visible';

        protected string $token = 'tok_secret';

        private string $password = 'pw_secret';
    };

    $json = makeDumper()->dump($obj);

    expect($json)->not->toContain('tok_secret')->not->toContain('pw_secret')->toContain('visible');
});

it('dumps models with attributes, respects $hidden and lists loaded relations', function () {
    $model = new class extends Model
    {
        protected $guarded = [];

        protected $hidden = ['internal_note'];
    };
    $model->forceFill(['id' => 7, 'name' => 'Widget', 'internal_note' => 'do not leak']);
    $model->setRelation('items', collect([1, 2]));
    $model->exists = true;

    $json = makeDumper()->dump($model);
    $node = json_decode($json, true);

    expect($json)->not->toContain('do not leak')
        ->and($node['$'])->toBe('obj')
        ->and($node['kind'])->toBe('model');

    $byName = collect($node['i'])->mapWithKeys(fn ($i) => [$i[0] => $i[2]]);
    expect($byName['key'])->toBe(7)
        ->and($byName['relations']['i'])->toBe(['items']);
});

it('dumps collections as count plus first items', function () {
    $json = makeDumper(['items' => 2])->dump(new Collection([10, 20, 30, 40]));
    $node = json_decode($json, true);

    expect($node['kind'])->toBe('collection');
    $byName = collect($node['i'])->mapWithKeys(fn ($i) => [$i[0] => $i[2]]);
    expect($byName['count'])->toBe(4)->and($byName['items']['i'])->toHaveCount(2);
});

it('stores closures, resources and PDO as type names only', function () {
    $d = makeDumper();
    $res = fopen('php://memory', 'r');

    expect($d->dumpToStructure(fn () => 1))->toBe(['$' => 'type', 't' => 'Closure'])
        ->and($d->dumpToStructure($res))->toBe(['$' => 'type', 't' => 'resource (stream)'])
        ->and($d->dumpToStructure(new PDO('sqlite::memory:')))->toBe(['$' => 'type', 't' => 'PDO']);

    fclose($res);
});

it('dumps exceptions without a stack trace', function () {
    $json = makeDumper()->dump(new RuntimeException('boom', 3));
    $node = json_decode($json, true);

    expect($node['kind'])->toBe('exception')->and($json)->toContain('boom')->and($json)->not->toContain('#0 ');
});

it('falls back to a type marker when a value exceeds the span size', function () {
    $d = makeDumper(['span' => 1024, 'items' => 500, 'string' => 100000]);
    $big = array_fill(0, 400, str_repeat('abcdefgh', 10));

    $json = $d->dump($big);

    expect(strlen($json))->toBeLessThanOrEqual(1024)
        ->and($d->truncated())->toBeTrue();
});

it('switches to type and size only after the per-trace memory ceiling', function () {
    $d = makeDumper(['ceiling' => 300]);
    $d->dump(['x' => str_repeat('a', 400)]);

    expect($d->dump(['y' => 1, 'z' => 2]))->toBe('{"$":"type","t":"array","size":2}');
});

it('keeps non-finite floats, big ints and binary strings JSON safe', function () {
    $d = makeDumper();

    expect($d->dumpToStructure(INF))->toBe(['$' => 'float', 'v' => 'INF'])
        ->and($d->dumpToStructure(PHP_INT_MAX))->toBe(['$' => 'int', 'v' => (string) PHP_INT_MAX])
        ->and($d->dumpToStructure("\xff\xfe\x00"))->toBe(['$' => 'bin', 'len' => 3]);
});
