<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Http\Controllers\AssetController;
use Ahmedhsieb\RequestTracer\Http\Controllers\DashboardController;
use Ahmedhsieb\RequestTracer\Http\Controllers\JournalController;
use Ahmedhsieb\RequestTracer\Http\Controllers\MetaController;
use Ahmedhsieb\RequestTracer\Http\Controllers\ToolsController;
use Ahmedhsieb\RequestTracer\Http\Controllers\TraceController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by the service provider inside a group that carries the configured prefix, domain and middleware
 * (`dashboard.path`, `dashboard.domain`, `dashboard.middleware`: web + the `viewRequestTracer` gate by default).
 */

Route::get('assets/{path}', AssetController::class)->where('path', '.*')->name('asset');

Route::prefix('api/v1')->name('api.')->group(function (): void {
    Route::get('meta', MetaController::class)->name('meta');

    Route::get('traces', [TraceController::class, 'index'])->name('traces.index');
    Route::delete('traces', [TraceController::class, 'clear'])->name('traces.clear');
    Route::get('traces/{trace}', [TraceController::class, 'show'])->name('traces.show');
    Route::delete('traces/{trace}', [TraceController::class, 'destroy'])->name('traces.destroy');
    Route::get('traces/{trace}/spans', [TraceController::class, 'spans'])->name('traces.spans');
    Route::get('traces/{trace}/spans/{span}', [TraceController::class, 'payload'])->whereNumber('span')->name('traces.payload');
    Route::get('traces/{trace}/spans/{span}/lines', [TraceController::class, 'spanLines'])->whereNumber('span')->name('traces.span-lines');
    Route::get('traces/{trace}/lines', [TraceController::class, 'lines'])->name('traces.lines');
    Route::get('traces/{trace}/writes', [TraceController::class, 'writes'])->name('traces.writes');
    Route::get('traces/{trace}/problems', [TraceController::class, 'problems'])->name('traces.problems');

    Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
    Route::delete('journal', [JournalController::class, 'clear'])->name('journal.clear');
    Route::get('journal/{entry}', [JournalController::class, 'show'])->name('journal.show');

    Route::post('tokens', [ToolsController::class, 'issueToken'])->name('tokens.issue');
    Route::post('replay', [ToolsController::class, 'replay'])->name('replay');
    Route::get('traces/{trace}/undo', [ToolsController::class, 'undoStatus'])->name('undo.show');
    Route::post('traces/{trace}/undo', [ToolsController::class, 'undo'])->name('undo.apply');

    Route::get('watch', [ToolsController::class, 'watchList'])->name('watch.index');
    Route::post('watch', [ToolsController::class, 'watch'])->name('watch.store');
    Route::delete('watch/{user}', [ToolsController::class, 'unwatch'])->name('watch.destroy');

    Route::get('deep-trace/queue', [ToolsController::class, 'queue'])->name('deep.queue');
    Route::post('deep-trace/queue', [ToolsController::class, 'queueFile'])->name('deep.queue.store');
    Route::delete('deep-trace/queue', [ToolsController::class, 'dequeueFile'])->name('deep.queue.destroy');
});

// Everything else below the dashboard path is the single-page app (client-side routes such as /trace/<id>).
Route::get('/{view?}', DashboardController::class)->where('view', '^(?!api/|assets/).*$')->name('dashboard');
