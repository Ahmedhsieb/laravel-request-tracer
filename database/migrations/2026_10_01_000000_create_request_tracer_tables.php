<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('request-tracer.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function table(string $name): string
    {
        return (string) config('request-tracer.storage.table_prefix', 'tracer_').$name;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create($this->table('traces'), function (Blueprint $table) {
            $table->char('id', 26)->primary();                       // ULID: sorts by time, used as the pagination cursor
            $table->string('kind', 16)->default('request');          // request | job | command | schedule
            $table->string('method', 10)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('route_name', 255)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->decimal('duration_ms', 12, 2)->default(0);
            $table->unsignedBigInteger('memory_peak')->default(0);
            $table->string('user_id', 64)->nullable();
            $table->string('tenant', 64)->nullable();
            $table->string('module', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->unsignedInteger('spans_count')->default(0);
            $table->unsignedInteger('queries_count')->default(0);
            $table->unsignedInteger('problems_count')->default(0);
            $table->json('tables_written')->nullable();
            $table->json('tables_read')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('laravel_version', 16)->nullable();
            $table->string('environment', 32)->nullable();
            $table->string('mode', 16)->nullable();                  // how recording was triggered: always | token | watch | sample
            $table->char('parent_trace_id', 26)->nullable();         // a job trace links back to the trace that dispatched it
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('path');
            $table->index('status');
            $table->index('user_id');
            $table->index('problems_count');
            $table->index('parent_trace_id');
        });

        $schema->create($this->table('spans'), function (Blueprint $table) {
            $table->char('trace_id', 26);
            $table->unsignedInteger('id');                           // per-trace sequence; (trace_id, id) is the key
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('type', 16);
            $table->string('label', 512);
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedBigInteger('start_us')->default(0);
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->bigInteger('memory_delta')->default(0);
            $table->string('flag', 12)->nullable();
            $table->string('connection', 64)->nullable();
            $table->unsignedInteger('count')->default(1);            // >1 when identical spans were collapsed
            $table->text('meta')->nullable();
            $table->longText('input')->nullable();                   // JSON, gzip+base64 when large ("gz:" prefix)
            $table->longText('output')->nullable();

            $table->primary(['trace_id', 'id']);                     // also serves "all spans of a trace"
        });

        $schema->create($this->table('lines'), function (Blueprint $table) {
            $table->id();
            $table->char('trace_id', 26);
            $table->unsignedInteger('span_id')->nullable();
            $table->string('file', 512);
            $table->unsignedInteger('line');
            $table->string('kind', 8);                               // cond | block | assign | loop | return | throw
            $table->unsignedInteger('hits')->default(0);
            $table->longText('result')->nullable();                  // JSON: bool, value, iterations
            $table->string('snippet', 255)->nullable();

            $table->index(['trace_id', 'span_id']);
        });

        $schema->create($this->table('writes'), function (Blueprint $table) {
            $table->id();
            $table->char('trace_id', 26);
            $table->unsignedInteger('span_id')->nullable();
            $table->string('connection', 64)->nullable();
            $table->string('table', 255);
            $table->string('operation', 8);                          // insert | update | delete
            $table->string('row_key', 255)->nullable();
            $table->longText('before')->nullable();
            $table->longText('after')->nullable();
            $table->string('transaction', 12)->nullable();           // none | open | committed | rolled_back
            $table->unsignedInteger('rows')->nullable();

            $table->index('trace_id');
        });

        $schema->create($this->table('problems'), function (Blueprint $table) {
            $table->id();
            $table->char('trace_id', 26);
            $table->unsignedInteger('span_id')->nullable();
            $table->string('kind', 24);
            $table->text('message');
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();

            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (['problems', 'writes', 'lines', 'spans', 'traces'] as $name) {
            $schema->dropIfExists($this->table($name));
        }
    }
};
