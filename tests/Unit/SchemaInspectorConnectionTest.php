<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use LaraMint\LaravelBrain\Analysis\SchemaInspector;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Analysis\SchemaInspector
 */

/**
 * A container with just enough of Laravel to resolve a database connection: a config repository
 * and a manager bound to `db`, which is what the `DB` facade reaches for.
 */
function schemaContainer(array $connections, string $default = 'primary'): Container
{
    $app = new Container;
    $app->instance('config', new Repository([
        'database' => ['default' => $default, 'connections' => $connections],
    ]));
    $app->singleton('db', fn ($app) => new DatabaseManager($app, new ConnectionFactory($app)));

    Container::setInstance($app);
    DB::clearResolvedInstances();
    DB::setFacadeApplication($app);

    return $app;
}

afterEach(function () {
    DB::clearResolvedInstances();
    Container::setInstance(null);
});

it('applies the connect timeout to a copy, leaving the real connection alone', function () {
    $app = schemaContainer(['primary' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    expect(SchemaInspector::forConnection(null, 2))->not->toBeNull();

    $derived = $app['config']->get('database.connections.laravel-brain-schema');
    $original = $app['config']->get('database.connections.primary');

    // The bound lands on the copy the scan opens...
    expect($derived['options'][PDO::ATTR_TIMEOUT] ?? null)->toBe(2)
        // ...and never on the connection the rest of the application uses.
        ->and($original['options'] ?? [])->toBe([]);
});

it('leaves the driver default in place when no timeout is configured', function () {
    $app = schemaContainer(['primary' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    expect(SchemaInspector::forConnection(null, null))->not->toBeNull()
        ->and($app['config']->get('database.connections.laravel-brain-schema'))->toBeNull();
});

it('reads no schema rather than failing when the connection cannot be opened', function () {
    // The point of opening the connection eagerly: an unreachable database turns the feature
    // off instead of carrying a dead connection into the middle of the scan.
    schemaContainer(['primary' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite']]);

    expect(SchemaInspector::forConnection(null, 2))->toBeNull();
});
