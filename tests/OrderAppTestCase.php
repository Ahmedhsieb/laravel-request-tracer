<?php

declare(strict_types=1);

namespace RequestTracer\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RequestTracer\Tests\Fixtures\Events\OrderPlaced;
use RequestTracer\Tests\Fixtures\Http\OrderController;
use RequestTracer\Tests\Fixtures\Listeners\SendOrderConfirmation;
use RequestTracer\Tests\Fixtures\Models\Product;
use RequestTracer\Tests\Fixtures\Models\User;

/** The example "Order flow" application used by the end-to-end tests: auth, validation, services, a caught exception, HTTP call, event. */
abstract class OrderAppTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);
        $app['config']->set('request-tracer.problems.n_plus_one_threshold', 3);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('/api/orders', [OrderController::class, 'store'])->middleware('auth:web')->name('orders.store');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(OrderPlaced::class, SendOrderConfirmation::class);
        Http::fake(['payments.test/*' => Http::response(['status' => 'approved', 'id' => 'ch_1'], 200)]);
    }

    public function makeUser(bool $vip = false): User
    {
        return User::create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.test', 'password' => bcrypt('secret-password'), 'api_token' => 'tok_user_secret', 'is_vip' => $vip]);
    }

    /** @return list<Product> */
    public function seedCatalog(): array
    {
        return [
            Product::create(['sku' => 'SKU-A', 'name' => 'Widget', 'price' => 10, 'stock' => 100]),
            Product::create(['sku' => 'SKU-B', 'name' => 'Gadget', 'price' => 25, 'stock' => 50]),
            Product::create(['sku' => 'SKU-C', 'name' => 'Gizmo', 'price' => 40, 'stock' => 5]),
        ];
    }

    /**
     * @param  list<Product>  $products
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function orderPayload(array $products, array $extra = []): array
    {
        return array_merge([
            'items' => [
                ['product_id' => $products[0]->id, 'qty' => 2],
                ['product_id' => $products[1]->id, 'qty' => 1],
                ['product_id' => $products[2]->id, 'qty' => 1],
            ],
            'coupon' => 'OLD2020',
            'payment_method' => 'card',
            'card_number' => '4111111111111111',
        ], $extra);
    }
}
