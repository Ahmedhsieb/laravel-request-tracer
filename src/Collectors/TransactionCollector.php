<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Collectors;

use Ahmedhsieb\RequestTracer\SpanFlag;
use Ahmedhsieb\RequestTracer\SpanType;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Ahmedhsieb\RequestTracer\Tracer;
use Ahmedhsieb\RequestTracer\TraceSession;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Follows database transactions so every recorded write knows whether it ran in a transaction and whether that
 * transaction committed or rolled back. (Writes start as `open` inside a transaction, `none` outside.)
 */
final class TransactionCollector implements Collector
{
    public static function key(): string
    {
        return 'transactions';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(TransactionBeginning::class, function (TransactionBeginning $e) use ($tracer): void {
            $this->guard($tracer, 'begin', $e->connection, $e->connectionName);
        });
        $events->listen(TransactionCommitted::class, function (TransactionCommitted $e) use ($tracer): void {
            $this->guard($tracer, 'commit', $e->connection, $e->connectionName);
        });
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $e) use ($tracer): void {
            $this->guard($tracer, 'rollback', $e->connection, $e->connectionName);
        });
    }

    private function guard(Tracer $tracer, string $what, ConnectionInterface $connection, string $name): void
    {
        if (! $tracer->on) {
            return;
        }
        try {
            $session = $tracer->session();
            if ($session !== null && $connection instanceof Connection) {
                $this->handle($session, $what, $connection, $name);
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'transaction collector');
        }
    }

    private function handle(TraceSession $session, string $what, Connection $connection, string $name): void
    {
        $state = &$session->bag['tx'][$name];
        $state ??= ['level' => 0, 'starts' => []];
        $level = $connection->transactionLevel();

        if ($what === 'begin') {
            $state['starts'][$level] = count($session->writes);
            $state['level'] = $level;
            $session->recorder->leaf(SpanType::Query, $level > 1 ? 'SAVEPOINT trans'.$level : 'BEGIN TRANSACTION', 0)->meta['op'] = 'transaction';

            return;
        }

        $from = $state['starts'][$level + 1] ?? ($level === 0 ? ($state['starts'][1] ?? 0) : count($session->writes));
        $to = $what === 'commit' ? ($level === 0 ? 'committed' : null) : 'rolled_back';
        if ($to !== null) {
            foreach ($session->writes as $i => $write) {
                if ($i >= $from && $write['connection'] === $name && $write['transaction'] === 'open') {
                    $session->writes[$i]['transaction'] = $to;
                }
            }
        }
        foreach (array_keys($state['starts']) as $l) {
            if ($l > $level) {
                unset($state['starts'][$l]);
            }
        }
        $state['level'] = $level;

        $label = $what === 'commit' ? ($level === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT') : ($level === 0 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT');
        $span = $session->recorder->leaf(SpanType::Query, $label, 0);
        $span->meta['op'] = 'transaction';
        if ($what === 'rollback') {
            $span->flag = SpanFlag::Failed;
        }
    }
}
