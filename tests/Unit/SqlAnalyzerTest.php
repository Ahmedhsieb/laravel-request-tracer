<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Sql\BindingNamer;
use Ahmedhsieb\RequestTracer\Sql\SqlAnalyzer;
use Ahmedhsieb\RequestTracer\Sql\TableRef;

/** @return list<string> "operation table" pairs the statement writes, e.g. ["insert orders"] */
function writesOf(string $sql, string $driver = ''): array
{
    return array_map(
        static fn (TableRef $t): string => $t->operation.' '.$t->qualified(),
        SqlAnalyzer::analyze($sql, $driver)->writes,
    );
}

/** @return list<string> */
function readsOf(string $sql, string $driver = ''): array
{
    return array_map(static fn (TableRef $t): string => $t->qualified(), SqlAnalyzer::analyze($sql, $driver)->reads);
}

beforeEach(fn () => SqlAnalyzer::flush());

// ---------------------------------------------------------------------------------------------------------------------
// writes, per dialect
// ---------------------------------------------------------------------------------------------------------------------

it('extracts the written table for every statement shape', function (string $sql, string $driver, array $expected) {
    expect(writesOf($sql, $driver))->toBe($expected);
})->with([
    // --- MySQL / MariaDB
    'mysql insert' => ['insert into `orders` (`user_id`, `total`) values (?, ?)', 'mysql', ['insert orders']],
    'mysql insert ignore' => ['INSERT IGNORE INTO orders (a) VALUES (1)', 'mysql', ['insert orders']],
    'mysql insert low priority' => ['INSERT LOW_PRIORITY INTO `db`.`orders` SET a = 1', 'mysql', ['insert db.orders']],
    'mysql replace' => ['REPLACE INTO sessions (id) VALUES (?)', 'mysql', ['insert sessions']],
    'mysql upsert' => ['insert into `stock` (`sku`, `qty`) values (?, ?) on duplicate key update `qty` = values(`qty`)', 'mysql', ['insert stock']],
    'mysql insert select' => ['insert into archive (id) select id from orders where created_at < ?', 'mysql', ['insert archive']],
    'mysql update' => ['update `orders` set `status` = ?, `updated_at` = ? where `id` = ?', 'mysql', ['update orders']],
    'mysql update alias' => ['update orders o set o.status = ? where o.id = ?', 'mysql', ['update orders']],
    'mysql update join' => ['update `orders` inner join `users` on `users`.`id` = `orders`.`user_id` set `orders`.`status` = ? where `users`.`active` = 1', 'mysql', ['update orders']],
    'mysql delete' => ['delete from `orders` where `id` = ?', 'mysql', ['delete orders']],
    'mysql delete low priority' => ['DELETE LOW_PRIORITY QUICK FROM logs WHERE id < ?', 'mysql', ['delete logs']],
    'mysql delete multi' => ['delete o, i from orders o inner join order_items i on i.order_id = o.id where o.id = ?', 'mysql', ['delete orders', 'delete order_items']],
    'mysql truncate' => ['truncate table `cache`', 'mysql', ['delete cache']],

    // --- PostgreSQL
    'pg insert' => ['insert into "orders" ("user_id", "total") values ($1, $2) returning "id"', 'pgsql', ['insert orders']],
    'pg insert schema' => ['insert into "public"."orders" ("total") values ($1) returning "id"', 'pgsql', ['insert public.orders']],
    'pg upsert' => ['insert into "stock" ("sku", "qty") values ($1, $2) on conflict ("sku") do update set "qty" = excluded."qty"', 'pgsql', ['insert stock']],
    'pg insert default' => ['INSERT INTO orders DEFAULT VALUES RETURNING id', 'pgsql', ['insert orders']],
    'pg update' => ['update "orders" set "status" = $1 where "id" = $2', 'pgsql', ['update orders']],
    'pg update only' => ['UPDATE ONLY public.orders SET a = 1', 'pgsql', ['update public.orders']],
    'pg update from' => ['update orders set total = s.sum from (select order_id, sum(price) sum from items group by order_id) s where s.order_id = orders.id', 'pgsql', ['update orders']],
    'pg delete' => ['delete from "orders" where "id" = $1', 'pgsql', ['delete orders']],
    'pg delete using' => ['DELETE FROM ONLY orders USING users WHERE users.id = orders.user_id', 'pgsql', ['delete orders']],
    'pg cte data modifying' => ['with moved as (delete from queue where id = $1 returning *) insert into archive select * from moved', 'pgsql', ['delete queue', 'insert archive']],
    'pg cte select then update' => ['with big as (select id from orders where total > 100) update orders set flag = true where id in (select id from big)', 'pgsql', ['update orders']],
    'pg truncate' => ['TRUNCATE TABLE ONLY public.logs RESTART IDENTITY CASCADE', 'pgsql', ['delete public.logs']],

    // --- SQLite
    'sqlite insert' => ['insert into "orders" ("total") values (?)', 'sqlite', ['insert orders']],
    'sqlite or replace' => ['INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)', 'sqlite', ['insert settings']],
    'sqlite or ignore' => ['insert or ignore into tags (name) values (?)', 'sqlite', ['insert tags']],
    'sqlite update or' => ['UPDATE OR ROLLBACK orders SET a = 1', 'sqlite', ['update orders']],
    'sqlite delete' => ['delete from "orders" where "id" = ?', 'sqlite', ['delete orders']],
    'sqlite upsert' => ['insert into "stock" ("sku") values (?) on conflict ("sku") do update set "qty" = "qty" + 1', 'sqlite', ['insert stock']],

    // --- SQL Server
    'mssql insert' => ['insert into [orders] ([user_id], [total]) values (@p0, @p1)', 'sqlsrv', ['insert orders']],
    'mssql insert schema' => ['insert into [dbo].[orders] ([total]) output inserted.[id] values (?)', 'sqlsrv', ['insert dbo.orders']],
    'mssql update' => ['update [dbo].[orders] set [status] = ? where [id] = ?', 'sqlsrv', ['update dbo.orders']],
    'mssql update top' => ['UPDATE TOP (10) orders SET a = 1', 'sqlsrv', ['update orders']],
    'mssql update alias from' => ['update o set o.status = ? from orders o inner join users u on u.id = o.user_id where u.active = 1', 'sqlsrv', ['update orders']],
    'mssql update with hint' => ['UPDATE orders WITH (ROWLOCK) SET a = 1', 'sqlsrv', ['update orders']],
    'mssql delete' => ['delete from [orders] where [id] = ?', 'sqlsrv', ['delete orders']],
    'mssql delete top' => ['DELETE TOP (100) FROM dbo.logs WHERE a < ?', 'sqlsrv', ['delete dbo.logs']],
    'mssql delete alias' => ['delete o from orders o join users u on u.id = o.user_id where u.banned = 1', 'sqlsrv', ['delete orders']],
    'mssql merge' => ['merge into stock as t using (select ? as sku) s on t.sku = s.sku when matched then update set qty = qty + 1 when not matched then insert (sku) values (s.sku);', 'sqlsrv', ['update stock']],
    'mssql three part name' => ['insert into [shop].[dbo].[orders] ([total]) values (?)', 'sqlsrv', ['insert shop.dbo.orders']],
    'mssql db..table' => ['delete from shop..orders where id = 1', 'sqlsrv', ['delete shop.orders']],

    // --- quoting, comments, noise
    'comment before' => ['/* app: checkout */ insert into orders (a) values (1)', '', ['insert orders']],
    'line comment' => ["-- hi\nupdate orders set a = 1", '', ['update orders']],
    'string with keywords' => ["update logs set msg = 'delete from users; insert into x'", '', ['update logs']],
    'doubled quote in string' => ["update logs set msg = 'it''s update me' where id = 1", '', ['update logs']],
    'quoted with spaces' => ['insert into "my orders" (a) values (1)', 'pgsql', ['insert my orders']],
    'quoted escape' => ['insert into `we``ird` (a) values (1)', 'mysql', ['insert we`ird']],
    'leading paren' => ['(update orders set a = 1)', '', ['update orders']],
    'mixed case' => ['InSeRt InTo Orders (a) VaLuEs (1)', '', ['insert Orders']],
    'multiline' => ["INSERT INTO\n  orders\n  (a)\nVALUES\n  (1)", '', ['insert orders']],
    'cte name is not a table' => ['with x as (select 1) insert into real_table select * from x', '', ['insert real_table']],
]);

it('reports no writes for read-only statements', function (string $sql) {
    expect(SqlAnalyzer::analyze($sql)->writes)->toBe([]);
})->with([
    'select' => 'select * from orders where id = 1',
    'select for update' => 'select * from orders where id = 1 for update',
    'select string' => "select 'insert into orders' as x",
    'with select' => 'with a as (select 1) select * from a',
    'show' => 'show tables',
    'pragma' => 'PRAGMA foreign_keys = ON',
    'set' => 'set names utf8mb4',
    'ddl' => 'create table foo (id int)',
    'transaction' => 'begin',
    'empty' => '',
]);

it('classifies the statement kind', function (string $sql, string $kind) {
    expect(SqlAnalyzer::analyze($sql)->operation)->toBe($kind);
})->with([
    ['select 1', 'select'], ['insert into a values (1)', 'insert'], ['REPLACE INTO a VALUES (1)', 'insert'], ['update a set b = 1', 'update'],
    ['delete from a', 'delete'], ['truncate a', 'truncate'], ['merge into a using b on 1=1 when matched then delete;', 'merge'],
    ['create index x on a (b)', 'ddl'], ['drop table a', 'ddl'], ['commit', 'transaction'], ['savepoint trans2', 'transaction'], ['show tables', 'other'],
]);

it('flags upserts', function (string $sql, bool $upsert) {
    expect(SqlAnalyzer::analyze($sql)->upsert)->toBe($upsert);
})->with([
    ['insert into a (x) values (1)', false],
    ['insert into a (x) values (1) on duplicate key update x = 2', true],
    ['insert into a (x) values (1) on conflict do nothing', true],
    ['replace into a (x) values (1)', true],
    ['insert or replace into a (x) values (1)', true],
]);

it('counts rows of a multi-row insert and lists the written columns', function () {
    $w = SqlAnalyzer::analyze('insert into `items` (`order_id`, `sku`, `qty`) values (?, ?, ?), (?, ?, ?), (?, ?, ?)')->writes[0];

    expect($w->rows)->toBe(3)->and($w->columns)->toBe(['order_id', 'sku', 'qty']);
});

it('lists the columns of an UPDATE', function () {
    $w = SqlAnalyzer::analyze('update `orders` set `status` = ?, `total` = coalesce(?, `total`), `updated_at` = now() where `id` = ?')->writes[0];

    expect($w->columns)->toBe(['status', 'total', 'updated_at']);
});

// ---------------------------------------------------------------------------------------------------------------------
// reads
// ---------------------------------------------------------------------------------------------------------------------

it('finds the tables a statement reads', function (string $sql, array $expected) {
    expect(readsOf($sql))->toBe($expected);
})->with([
    'simple' => ['select * from orders', ['orders']],
    'join' => ['select * from orders o join users u on u.id = o.user_id left join `items` i on i.order_id = o.id', ['orders', 'users', 'items']],
    'comma join' => ['select * from orders o, users u where u.id = o.user_id', ['orders', 'users']],
    'subquery' => ['select * from (select * from inner_t) x join other on 1 = 1', ['inner_t', 'other']],
    'where in subquery' => ['select * from orders where id in (select order_id from items)', ['orders', 'items']],
    'schema' => ['select * from "public"."orders"', ['public.orders']],
    'extract is not a table' => ['select extract(year from created_at) y from orders', ['orders']],
    'trim from is not a table' => ["select trim(both ' ' from name) from users", ['users']],
    'insert select' => ['insert into archive select * from orders', ['orders']],
    'cte' => ['with a as (select * from base) select * from a', ['base']],
    'update from' => ['update orders set a = 1 from users where users.id = orders.user_id', ['users']],
    'mssql nolock' => ['select * from orders with (nolock)', ['orders']],
    'function table' => ['select * from generate_series(1, 3)', []],
    'delete target is not a read' => ['delete from orders where id in (select order_id from items)', ['items']],
]);

// ---------------------------------------------------------------------------------------------------------------------
// fingerprints (used to detect N+1 and repeated statements)
// ---------------------------------------------------------------------------------------------------------------------

it('gives statements of the same shape the same fingerprint', function () {
    $a = SqlAnalyzer::analyze('select * from `items` where `order_id` = ? and `sku` in (?, ?, ?)')->fingerprint;
    $b = SqlAnalyzer::analyze('SELECT * FROM `items` WHERE `order_id` = 99 AND `sku` IN (?)')->fingerprint;
    $c = SqlAnalyzer::analyze('select * from `items` where `order_id` = ?')->fingerprint;

    expect($a)->toBe($b)->and($a)->not->toBe($c);
    expect(SqlAnalyzer::analyze('insert into t (a) values (?), (?), (?)')->fingerprint)->toBe(SqlAnalyzer::analyze('insert into t (a) values (?)')->fingerprint);
});

// ---------------------------------------------------------------------------------------------------------------------
// binding names (masking by column)
// ---------------------------------------------------------------------------------------------------------------------

it('names the column of each placeholder', function (string $sql, array $expected) {
    expect(BindingNamer::names($sql))->toBe($expected);
})->with([
    'where' => ['select * from users where email = ? and password = ?', ['email', 'password']],
    'operators' => ['select * from t where a <> ? and b >= ? and c like ? and d is not ?', ['a', 'b', 'c', 'd']],
    'in list' => ['select * from t where token in (?, ?, ?) and id = ?', ['token', 'token', 'token', 'id']],
    'between' => ['select * from t where created_at between ? and ?', ['created_at', 'created_at']],
    'qualified' => ['select * from `users` where `users`.`api_key` = ?', ['api_key']],
    'function' => ['select * from users where lower(email) = ?', ['email']],
    'insert' => ['insert into `users` (`name`, `email`, `password`) values (?, ?, ?)', ['name', 'email', 'password']],
    'insert multi row' => ['insert into t (a, b) values (?, ?), (?, ?)', ['a', 'b', 'a', 'b']],
    'insert with literal' => ["insert into t (a, b, c) values (?, 'x', ?)", ['a', 'c']],
    'update' => ['update `users` set `name` = ?, `remember_token` = ?, `updated_at` = ? where `id` = ?', ['name', 'remember_token', 'updated_at', 'id']],
    'update expression' => ['update t set a = coalesce(?, a), b = ? where c = ?', ['a', 'b', 'c']],
    'unknown' => ['select ? as x', [null]],
]);
