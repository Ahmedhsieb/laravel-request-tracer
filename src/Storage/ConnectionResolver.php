<?php

declare(strict_types=1);

namespace RequestTracer\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use RequestTracer\Support\App;

/**
 * Resolves the connection traces are written to.
 *
 * With `separate_connection` (default) a dedicated connection is opened from the same config, so the writes never
 * join (and never get rolled back with) a transaction the application still has open. In-memory SQLite cannot be
 * duplicated, so it falls back to the shared connection.
 */
final class ConnectionResolver
{
    /** @var array<string, Connection> */
    private array $writers = [];

    public function resolve(): Connection
    {
        /** @var DatabaseManager $db */
        $db = App::container()->make('db');
        $config = (array) App::config()->get('request-tracer.storage', []);
        $name = $config['connection'] ?? null;
        $base = is_string($name) && $name !== '' ? $name : $db->getDefaultConnection();

        if (! ($config['separate_connection'] ?? true)) {
            return $this->shared($db, $base);
        }

        $connectionConfig = App::config()->get('database.connections.'.$base);
        if (! is_array($connectionConfig) || $this->isInMemorySqlite($connectionConfig)) {
            return $this->shared($db, $base);
        }

        $writerName = 'request-tracer-writer:'.$base;
        $signature = md5(serialize($connectionConfig));
        $key = $writerName.':'.$signature;
        if (isset($this->writers[$key])) {
            return $this->writers[$key];
        }

        if (method_exists($db, 'connectUsing')) {
            $writer = $db->connectUsing($writerName.':'.$signature, $connectionConfig, true);
            if (! $writer instanceof Connection) {
                return $this->shared($db, $base);
            }
        } else {
            /** @var ConnectionFactory $factory */
            $factory = App::container()->make('db.factory');
            $writer = $factory->make($connectionConfig, $writerName);
            $writer->setReconnector(fn (Connection $c) => $c->setPdo($factory->make($connectionConfig, $writerName)->getRawPdo()));
        }

        // The tracer's own writes must not show up in query listeners (Telescope, Debugbar, our collectors).
        $writer->unsetEventDispatcher();

        return $this->writers[$key] = $writer;
    }

    private function shared(DatabaseManager $db, string $name): Connection
    {
        $connection = $db->connection($name);
        if (! $connection instanceof Connection) {
            throw new \RuntimeException('The request-tracer storage connection ['.$name.'] must be an Illuminate\Database\Connection.');
        }

        return $connection;
    }

    public function forget(): void
    {
        $this->writers = [];
    }

    /** @param  array<string, mixed>  $config */
    private function isInMemorySqlite(array $config): bool
    {
        if (($config['driver'] ?? null) !== 'sqlite') {
            return false;
        }
        $database = (string) ($config['database'] ?? '');

        return $database === ':memory:' || str_contains($database, 'mode=memory');
    }
}
