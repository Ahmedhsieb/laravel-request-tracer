<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Support\Summary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

it('summarises values in one short phrase', function (mixed $value, string $expected) {
    expect(Summary::of($value))->toBe($expected);
})->with([
    'null' => [null, 'null'],
    'bool' => [true, 'true'],
    'int' => [42, '42'],
    'float' => [85.5, '85.5'],
    'string' => ['hello', "'hello'"],
    'array' => [[1, 2, 3], 'array (3)'],
    'collection' => [new Collection([1, 2]), 'Collection (2)'],
    'response' => [new JsonResponse(['a' => 1], 201), '201 JsonResponse'],
    'exception' => [new RuntimeException('boom'), 'RuntimeException: boom'],
    'closure' => [fn () => 1, 'Closure'],
]);

it('names models by class and key without touching accessors', function () {
    $model = new class extends Model
    {
        protected $guarded = [];

        public function getTitleAttribute(): never
        {
            throw new LogicException('an accessor must never run');
        }
    };
    $model->forceFill(['id' => 1057]);

    expect(Summary::of($model))->toEndWith('#1057');
});

it('clips long strings and long messages', function () {
    expect(mb_strlen(Summary::of(str_repeat('x', 500))))->toBeLessThanOrEqual(70)
        ->and(mb_strlen(Summary::of(new RuntimeException(str_repeat('y', 500)))))->toBeLessThanOrEqual(70);
});
