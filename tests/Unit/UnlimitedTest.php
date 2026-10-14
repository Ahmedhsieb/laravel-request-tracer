<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Dumper\DumpLimits;
use Ahmedhsieb\RequestTracer\Dumper\Masker;
use Ahmedhsieb\RequestTracer\Dumper\ValueDumper;
use Ahmedhsieb\RequestTracer\Support\MemoryGuard;

function bigValue(): array
{
    // 300 rows of 40 fields with a long text each: well over the default caps in depth, items, string and total size
    $rows = [];
    for ($i = 0; $i < 300; $i++) {
        $row = ['id' => $i, 'nested' => ['a' => ['b' => ['c' => ['d' => 'deep']]]]];
        for ($f = 0; $f < 40; $f++) {
            $row["field_$f"] = str_repeat('x', 3000);
        }
        $rows[] = $row;
    }

    return ['data' => $rows];
}

it('keeps every cap by default', function () {
    $dumper = new ValueDumper(Masker::fromConfig([]), DumpLimits::fromConfig([]));
    $json = $dumper->dump(bigValue());

    expect($dumper->truncated())->toBeTrue()
        ->and(strlen($json))->toBeLessThanOrEqual(65536);
});

it('stores a value of any size when it is unlimited', function () {
    $dumper = new ValueDumper(Masker::fromConfig([]), DumpLimits::fromConfig([], true));
    $json = $dumper->dump(bigValue());
    $back = json_decode($json, true);

    expect($dumper->truncated())->toBeFalse()
        ->and($dumper->guardTripped())->toBeFalse()
        ->and(strlen($json))->toBeGreaterThan(30_000_000)                      // 300 rows x 40 fields x 3000 bytes
        ->and(json_encode($back))->toContain('deep');
    expect(count($back['i'][0]['i'] ?? $back['i'] ?? []))->toBeGreaterThan(0);
});

it('still masks secrets when it is unlimited', function () {
    $dumper = new ValueDumper(Masker::fromConfig(['keys' => ['password']]), DumpLimits::fromConfig([], true));

    expect($dumper->dump(['user' => 'a', 'password' => 'hunter2']))->not->toContain('hunter2');
});

it('reads the memory limit of PHP', function () {
    expect(MemoryGuard::limit('512M'))->toBe(512 * 1024 * 1024)
        ->and(MemoryGuard::limit('2G'))->toBe(2 * 1024 ** 3)
        ->and(MemoryGuard::limit('128k'))->toBe(128 * 1024)
        ->and(MemoryGuard::limit('1048576'))->toBe(1_048_576)
        ->and(MemoryGuard::limit('-1'))->toBe(0)
        ->and(MemoryGuard::limit(''))->toBe(0);
});

it('stops storing when 70 percent of the memory limit is used, and never when there is no limit', function () {
    $limit = '100M';
    $mb = 1024 * 1024;

    expect(MemoryGuard::low(60 * $mb, $limit))->toBeFalse()
        ->and(MemoryGuard::low(71 * $mb, $limit))->toBeTrue()
        ->and(MemoryGuard::low(900 * $mb, '-1'))->toBeFalse();
});

it('works out how many items a value may be cloned with from the memory that is left', function () {
    $mb = 1024 * 1024;

    expect(MemoryGuard::itemBudget(10 * $mb, '-1'))->toBe(-1)
        ->and(MemoryGuard::itemBudget(10 * $mb, '1G'))->toBeGreaterThan(MemoryGuard::itemBudget(600 * $mb, '1G'))
        ->and(MemoryGuard::itemBudget(900 * $mb, '1G'))->toBe(1000);            // never below a useful minimum
});
