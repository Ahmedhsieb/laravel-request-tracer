<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Replay;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Wraps every database connection in a transaction for the length of a replay, and undoes all of them.
 * Connections opened while the request runs are caught by the framework's "connection established" event.
 */
final class ReplayTransactions
{
    /** @var array<int, Connection> */
    private array $open = [];

    public function begin(): void
    {
        foreach (DB::getConnections() as $connection) {
            $this->wrap($connection);
        }
        $this->wrap(DB::connection());

        if (class_exists(ConnectionEstablished::class)) {
            Event::listen(ConnectionEstablished::class, function ($event): void {
                if ($event->connection instanceof Connection) {
                    $this->wrap($event->connection);
                }
            });
        }
    }

    public function rollBack(): void
    {
        foreach (array_reverse($this->open, true) as $id => $connection) {
            try {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                // nothing more can be done for a connection that went away
            }
            unset($this->open[$id]);
        }
    }

    private function wrap(Connection $connection): void
    {
        $id = spl_object_id($connection);
        if (isset($this->open[$id]) || Str::startsWith((string) $connection->getName(), 'request-tracer-writer')) {
            return;                                         // the tracer's own writer must keep what it stores
        }
        try {
            $connection->beginTransaction();
            $this->open[$id] = $connection;
        } catch (\Throwable) {
            // a connection that cannot start a transaction (unreachable, no transactions) cannot be protected
        }
    }
}
