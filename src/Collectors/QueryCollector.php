<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Collectors;

use Ahmedhsieb\RequestTracer\Dumper\MaskedValue;
use Ahmedhsieb\RequestTracer\SpanFlag;
use Ahmedhsieb\RequestTracer\SpanType;
use Ahmedhsieb\RequestTracer\Sql\BindingNamer;
use Ahmedhsieb\RequestTracer\Sql\SqlAnalyzer;
use Ahmedhsieb\RequestTracer\Support\CallSite;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;

/** One `query` span per executed statement, with masked bindings, the calling file:line, table reads and writes. */
final class QueryCollector implements Collector
{
    public static function key(): string
    {
        return 'queries';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            try {
                $this->handle($tracer, $event);
            } catch (\Throwable $e) {
                Failure::report($e, 'query collector');
            }
        });
    }

    private function handle(Tracer $tracer, QueryExecuted $event): void
    {
        $session = $tracer->session();
        if ($session === null) {
            return;
        }
        $session->queries++;

        $driver = $event->connection->getDriverName();
        $statement = SqlAnalyzer::analyze($event->sql, $driver);
        $durationUs = (int) round($event->time * 1000);
        [$file, $line] = $tracer->config('capture.query_source', true) ? CallSite::find() : [null, null];

        foreach ($statement->reads as $table) {
            $name = $table->name;
            $session->tablesRead[$name] ??= ['queries' => 0, 'rows' => 0];
            $session->tablesRead[$name]['queries']++;
        }

        $span = $session->recorder->leaf(SpanType::Query, $event->sql, $durationUs, $file, $line);
        $span->connection = $event->connectionName;
        $span->meta['op'] = $statement->operation;
        $tables = array_map(static fn ($t): string => $t->name, $statement->writes !== [] ? $statement->writes : $statement->reads);
        $span->meta['summary'] = strtoupper($statement->operation).($tables === [] ? '' : ' '.implode(', ', array_slice($tables, 0, 3))).' · '.$event->connectionName;

        $names = BindingNamer::names($event->sql, $driver);
        $bindings = array_values($event->bindings);
        $masker = $tracer->masker();
        $masked = $bindings;
        if (count($names) === count($bindings)) {
            foreach ($names as $i => $name) {
                if (($name !== null && $masker->isSensitiveKey($name)) || self::looksSecret($bindings[$i])) {
                    $masked[$i] = new MaskedValue;
                }
            }
        } else {
            foreach ($bindings as $i => $value) {
                if (self::looksSecret($value)) {
                    $masked[$i] = new MaskedValue;
                }
            }
        }

        $session->queryStats()->record(
            $statement->fingerprint,
            $event->sql,
            $statement->operation,
            $bindings,
            $file,
            $line,
            $span->id,
            $durationUs,
            (float) $tracer->config('problems.slow_query_ms', 100),
        );

        if ($statement->writes !== []) {
            $level = $event->connection->transactionLevel();
            foreach ($statement->writes as $table) {
                $session->writeTracker()->fromQuery(
                    $event->connectionName,
                    (string) $table->operation,
                    $table,
                    $span->id,
                    $names,
                    $bindings,
                    $level,
                    $event->connection->getTablePrefix(),
                    $statement->upsert,
                );
            }
        }

        if ($span->ghost) {
            return;
        }

        if ($durationUs / 1000 >= (float) $tracer->config('problems.slow_query_ms', 100)) {
            $span->flag = SpanFlag::Slow;
        }
        if ($tracer->config('capture.query_bindings', true)) {
            $span->input = $session->dump(['sql' => $event->sql, 'bindings' => $masked, 'connection' => $event->connectionName, 'time_ms' => round($event->time, 2)]);
        } else {
            $span->input = $session->dump(['sql' => $event->sql, 'connection' => $event->connectionName, 'time_ms' => round($event->time, 2)]);
        }
    }

    /** Password hashes and bearer tokens must not leak through a binding whose column we could not name. */
    private static function looksSecret(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) < 20) {
            return false;
        }

        return preg_match('/^(\$2[aby]\$|\$argon2|\$pbkdf2|Bearer\s)/i', $value) === 1;
    }
}
