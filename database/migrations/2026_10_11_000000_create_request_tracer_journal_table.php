<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The request journal: one light row per request, so a request nobody traced can still be found and replayed. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('request-tracer.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $name = (string) config('request-tracer.storage.table_prefix', 'tracer_').'journal';
        if ($schema->hasTable($name)) {
            return;
        }

        $schema->create($name, function (Blueprint $table) {
            $table->char('id', 26)->primary();                       // ULID: sorts by time, used as the pagination cursor
            $table->string('method', 10);
            $table->string('url', 1024);                             // path and query string
            $table->string('route_name', 255)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->decimal('duration_ms', 12, 2)->default(0);
            $table->string('user_id', 64)->nullable();
            $table->string('failure', 500)->nullable();              // the exception that ended the request, or "HTTP 500"
            $table->text('headers')->nullable();                     // JSON, allow-listed and masked
            $table->mediumText('body')->nullable();                  // masked; JSON or urlencoded
            $table->boolean('body_cut')->default(false);             // too large to keep: the body is not stored
            $table->boolean('masked')->default(false);               // something was masked, so a replay cannot send the real value
            $table->char('trace_id', 26)->nullable();                // set when the request was also traced
            $table->timestamp('created_at')->useCurrent();           // always written explicitly, in UTC

            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists((string) config('request-tracer.storage.table_prefix', 'tracer_').'journal');
    }
};
