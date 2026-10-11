<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RequestTracer\Tests\LocalTestCase;
use RequestTracer\Tests\OrderAppTestCase;
use RequestTracer\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(OrderAppTestCase::class)->in('Order');
uses(LocalTestCase::class)->in('Local');

/**
 * Point the queue at a `jobs` table (database driver) for one test. Real database servers keep tables between tests,
 * so the table is dropped first and again when the test is over.
 */
function useDatabaseQueue(): void
{
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]]);
    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    test()->beforeApplicationDestroyed(fn () => Schema::dropIfExists('jobs'));
}
