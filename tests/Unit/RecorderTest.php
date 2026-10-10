<?php

declare(strict_types=1);

use RequestTracer\Clock;
use RequestTracer\Recorder;
use RequestTracer\SpanFlag;
use RequestTracer\SpanType;

function recorder(int $max = 5000, int $collapse = 20): Recorder
{
    return new Recorder(new Clock, $max, $collapse);
}

it('builds a parent/child tree with durations', function () {
    $r = recorder();
    $root = $r->begin(SpanType::Request, 'GET /');
    $child = $r->begin(SpanType::Service, 'OrderService::create');
    usleep(2000);
    $r->end($child);
    $r->end($root);

    $spans = $r->finish();

    expect($spans)->toHaveCount(2)
        ->and($spans[0]->parentId)->toBeNull()
        ->and($spans[1]->parentId)->toBe($spans[0]->id)
        ->and($spans[1]->durationUs)->toBeGreaterThanOrEqual(1500)
        ->and($spans[0]->durationUs)->toBeGreaterThanOrEqual($spans[1]->durationUs)
        ->and($spans[0]->open)->toBeFalse();
});

it('closes spans a collector forgot to end', function () {
    $r = recorder();
    $root = $r->begin(SpanType::Request, 'x');
    $r->begin(SpanType::Service, 'forgotten');
    $r->end($root);

    expect(array_map(fn ($s) => $s->open, $r->finish()))->toBe([false, false]);
});

it('records leaf spans with a known duration ending now', function () {
    $r = recorder();
    $r->begin(SpanType::Request, 'x');
    $q = $r->leaf(SpanType::Query, 'select 1', 5000);

    expect($q->ghost)->toBeFalse()->and($q->durationUs)->toBe(5000)->and($q->parentId)->toBe(1);
});

it('collapses identical siblings beyond the threshold into one aggregate span', function () {
    $r = recorder(5000, 3);
    $r->begin(SpanType::Request, 'x');
    $ghosts = 0;
    for ($i = 0; $i < 10; $i++) {
        $q = $r->leaf(SpanType::Query, 'select * from t where id = ?', 1000);
        $ghosts += $q->ghost ? 1 : 0;
    }
    $spans = $r->finish();
    $agg = array_values(array_filter($spans, fn ($s) => $s->flag === SpanFlag::Repeated));

    expect($ghosts)->toBe(7)
        ->and($spans)->toHaveCount(1 + 3 + 1)                 // root + 3 real + 1 aggregate
        ->and($agg)->toHaveCount(1)
        ->and($agg[0]->count)->toBe(7)
        ->and($agg[0]->durationUs)->toBe(7000);
});

it('collapses repeated non-leaf spans and swallows their subtree', function () {
    $r = recorder(5000, 2);
    $r->begin(SpanType::Request, 'x');
    for ($i = 0; $i < 6; $i++) {
        $s = $r->begin(SpanType::Service, 'Price::line');
        $r->leaf(SpanType::Query, 'select price', 100);
        $r->end($s);
    }
    $spans = $r->finish();
    $byLabel = array_count_values(array_map(fn ($s) => $s->type->value.':'.$s->label, $spans));

    expect($byLabel['service:Price::line'])->toBe(3)         // 2 real + 1 aggregate
        ->and($byLabel['query:select price'])->toBe(2);      // only the 2 real occurrences kept their children
});

it('stops creating spans at the cap and reports it', function () {
    $r = recorder(5, 1000);
    $r->begin(SpanType::Request, 'x');
    for ($i = 0; $i < 20; $i++) {
        $r->leaf(SpanType::Query, "q$i", 10);
    }

    expect($r->count())->toBe(5)->and($r->capped())->toBeTrue()->and($r->dropped())->toBe(16);
});

it('attributes ghosts to a real span id', function () {
    $r = recorder(3, 1000);
    $root = $r->begin(SpanType::Request, 'x');
    $r->leaf(SpanType::Query, 'a');
    $r->leaf(SpanType::Query, 'b');
    $ghost = $r->begin(SpanType::Service, 'over cap');

    expect($ghost->ghost)->toBeTrue()->and($r->currentId())->toBe($root->id);
});

it('clips very long labels', function () {
    expect(strlen(Recorder::clip(str_repeat('é', 1000))))->toBeLessThanOrEqual(504);
});
