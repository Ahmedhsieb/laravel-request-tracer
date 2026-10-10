<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RequestTracer\Storage\ConnectionResolver;

it('writes traces on a dedicated connection, outside the application transaction', function () {
    if (! getenv('TRACER_TEST_DB')) {
        $this->markTestSkipped('In-memory SQLite cannot be duplicated; run with TRACER_TEST_DB=sqlite|mysql|pgsql.');
    }

    Route::get('/in-txn', fn () => 'ok');

    $app = DB::connection('testing');
    $app->beginTransaction();
    $this->get('/in-txn');
    $app->rollBack();                 // the application rolls everything back...

    expect($this->tracerTable('traces')->where('path', '/in-txn')->count())->toBe(1);   // ...the trace survives

    $writer = app(ConnectionResolver::class)->resolve();
    expect($writer)->not->toBe($app);
});

it('shares the application connection when separate_connection is off', function () {
    config(['request-tracer.storage.separate_connection' => false]);

    expect(app(ConnectionResolver::class)->resolve())->toBe(DB::connection('testing'));
});
