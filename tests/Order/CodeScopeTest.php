<?php

declare(strict_types=1);

/**
 * "Add a folder and everything in it is traced": no namespace, class or file is listed, and what is not named in `except` is looked into.
 */
// (deep_trace.namespaces is left as the Order tests set it: a class is compiled when it is first loaded in the process, and other tests need OrderService compiled)
beforeEach(function () {
    $services = str_replace('\\', '/', dirname(__DIR__)).'/Fixtures/Services';
    config([
        'request-tracer.proxies.namespaces' => [],
        'request-tracer.code.paths' => [$services],
        'request-tracer.code.except' => [],
    ]);
    $this->services = $services;
});

function stepLabels(object $test): array
{
    return array_map(fn (array $s) => $s['type'].':'.$s['label'], $test->traceSpans());
}

it('gives every service in the folder a step with its input and output, without a single class being listed', function () {
    $products = $this->seedCatalog();
    $this->actingAs($this->makeUser())->postJson('/api/orders', $this->orderPayload($products))->assertCreated();

    expect(stepLabels($this))->toContain('service:OrderService::create', 'service:PricingService::subtotal', 'service:PaymentService::charge', 'service:InventoryService::reserve');
});

it('leaves out a file named in code.except, and a class pattern', function () {
    config(['request-tracer.code.except' => [$this->services.'/PricingService.php', 'Ahmedhsieb\\RequestTracer\\Tests\\Fixtures\\Services\\Payment*']]);
    $products = $this->seedCatalog();
    $this->actingAs($this->makeUser())->postJson('/api/orders', $this->orderPayload($products))->assertCreated();

    $labels = stepLabels($this);
    expect($labels)->toContain('service:OrderService::create')
        ->and($labels)->not->toContain('service:PricingService::subtotal')
        ->and($labels)->not->toContain('service:PaymentService::charge');
});

it('traces nothing of the project when the folder is not in code.paths', function () {
    config(['request-tracer.code.paths' => [dirname($this->services).'/Events']]);
    $products = $this->seedCatalog();
    $this->actingAs($this->makeUser())->postJson('/api/orders', $this->orderPayload($products))->assertCreated();

    expect(array_filter(stepLabels($this), fn (string $label) => str_starts_with($label, 'service:') && str_contains($label, '::')))->toBe([]);
});

it('does not wrap a model in a proxy even when its folder is in code.paths: the framework builds models itself', function () {
    config(['request-tracer.code.paths' => [dirname($this->services)]]);                  // the whole fixtures folder, models included
    $products = $this->seedCatalog();
    $this->actingAs($this->makeUser())->postJson('/api/orders', $this->orderPayload($products))->assertCreated();

    $labels = stepLabels($this);
    expect($labels)->toContain('service:OrderService::create')
        ->and($labels)->not->toContain('service:User::save');
});
