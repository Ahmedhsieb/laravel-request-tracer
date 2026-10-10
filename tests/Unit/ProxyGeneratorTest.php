<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use RequestTracer\Hot;
use RequestTracer\Proxy\ProxyGenerator;
use RequestTracer\Recording\Decision;
use RequestTracer\RecordingState;
use RequestTracer\Span;
use RequestTracer\SpanType;
use RequestTracer\Tests\Fixtures\Proxy\EdgeCases;
use RequestTracer\Tests\Fixtures\Proxy\MarkerAttribute;
use RequestTracer\Tests\Fixtures\Proxy\Suit;
use RequestTracer\Tests\Fixtures\Services\CouponService;
use RequestTracer\Tracer;

function proxyOf(string $class = EdgeCases::class, array $options = [], ?string $dir = null): object
{
    static $generator = null;
    $generator ??= new ProxyGenerator($dir ?? sys_get_temp_dir().'/rt-proxy-tests-'.getmypid());
    $result = $generator->proxyFor($class, $options);
    expect($result->proxyClass)->not->toBeNull();

    return new ($result->proxyClass);
}

/** `self` and the class it stands for are the same type. */
function normaliseType(string $type): string
{
    return str_replace('self', EdgeCases::class, $type);
}

function startTrace(): void
{
    $session = app(Tracer::class)->begin('request', new Decision(RecordingState::On, 'always'));
    $session->root = $session->recorder->begin(SpanType::Request, 'test');
}

/** @return list<Span> */
function endTrace(): array
{
    $tracer = app(Tracer::class);
    $spans = $tracer->session()->recorder->finish();
    $tracer->reset();

    return $spans;
}

afterEach(fn () => app(Tracer::class)->reset());

it('generates a subclass of the original', function () {
    $proxy = proxyOf();

    expect($proxy)->toBeInstanceOf(EdgeCases::class)
        ->and($proxy::class)->toBe('RequestTracer\Proxies\RequestTracer_Tests_Fixtures_Proxy_EdgeCases__Proxy')
        ->and($proxy->name)->toBe('edge');                       // constructor ran with its defaults
});

it('keeps every traced method signature identical to the original', function () {
    $proxy = proxyOf();
    $original = new ReflectionClass(EdgeCases::class);
    $overridden = new ReflectionClass($proxy);

    $compared = 0;
    foreach ($original->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->isStatic() || $m->isConstructor() || $m->isFinal() || $m->getName() === 'newInInitializer') {
            continue;
        }
        $p = $overridden->getMethod($m->getName());
        expect($p->getDeclaringClass()->getName())->toBe($overridden->getName(), $m->getName().' is overridden');

        expect(count($p->getParameters()))->toBe(count($m->getParameters()));
        foreach ($m->getParameters() as $i => $param) {
            $pp = $p->getParameters()[$i];
            expect($pp->getName())->toBe($param->getName())
                ->and($pp->isPassedByReference())->toBe($param->isPassedByReference(), $m->getName().'($'.$param->getName().') by reference')
                ->and($pp->isVariadic())->toBe($param->isVariadic())
                ->and($pp->isOptional())->toBe($param->isOptional())
                ->and(normaliseType((string) $pp->getType()))->toBe(normaliseType((string) $param->getType()));
            if ($param->isDefaultValueAvailable()) {
                expect($pp->getDefaultValue())->toEqual($param->getDefaultValue());
            }
        }
        expect(normaliseType((string) $p->getReturnType()))->toBe(normaliseType((string) $m->getReturnType()), $m->getName().' return type');
        $compared++;
    }
    expect($compared)->toBeGreaterThan(15);
});

it('does not override static, final, protected, private methods or the constructor', function () {
    $proxy = proxyOf();
    $r = new ReflectionClass($proxy);

    foreach (['staticMethod', 'finalMethod', 'protectedMethod', 'privateMethod', '__construct', 'newInInitializer'] as $name) {
        if ($r->hasMethod($name)) {
            expect($r->getMethod($name)->getDeclaringClass()->getName())->toBe(EdgeCases::class, $name.' must stay with the original');
        }
    }
});

it('behaves exactly like the original while nothing is recorded', function () {
    $proxy = proxyOf();
    $plain = new EdgeCases;

    expect(Hot::$on)->toBeFalse();
    expect($proxy->plain(3))->toBe($plain->plain(3))
        ->and($proxy->plain(3, 'y'))->toBe('3yedge')
        ->and($proxy->variadic('-', 1, 2, 3))->toBe('1-2-3')
        ->and($proxy->nullable(null))->toBe($plain->nullable(null))
        ->and($proxy->nullable(4))->toBe(8)
        ->and($proxy->union('a'))->toBe('a!')
        ->and($proxy->union(1))->toBe(2)
        ->and($proxy->mixedReturn())->toBe(42)
        ->and($proxy->untyped(1))->toBe([1, null])
        ->and($proxy->enumDefault())->toBe('H')
        ->and($proxy->enumDefault(Suit::Spades))->toBe('S')
        ->and($proxy->constDefaults())->toBe($plain->constDefaults())
        ->and($proxy(2))->toBe(6)
        ->and($proxy->attributed('abc'))->toBe('cba')
        ->and($proxy->callsPrivate())->toBe('privateprotected')
        ->and(iterator_to_array($proxy->generator()))->toBe([1, 2]);
});

it('passes by-reference arguments through, with and without recording', function () {
    $proxy = proxyOf();

    $items = ['a'];
    expect($proxy->byRef($items))->toBe(2)->and($items)->toBe(['a', 5]);

    $out = [];
    $proxy->voidMethod($out);
    expect($out)->toBe([1]);

    $x = 1;
    $y = null;
    $proxy->variadicByRef($x, $y);
    expect($x)->toBe(2)->and($y)->toBe(1);

    startTrace();
    $items = ['a'];
    $proxy->byRef($items, 9);
    $out = [];
    $proxy->voidMethod($out);
    $x = 10;
    $proxy->variadicByRef($x);
    endTrace();

    expect($items)->toBe(['a', 9])->and($out)->toBe([1])->and($x)->toBe(11);
});

it('returns the proxy itself from self and static returning methods', function () {
    $proxy = proxyOf();

    expect($proxy->returnsSelf())->toBe($proxy)->and($proxy->returnsStatic())->toBe($proxy);

    startTrace();
    expect($proxy->returnsSelf())->toBe($proxy)->and($proxy->returnsStatic())->toBe($proxy);
    endTrace();
});

it('handles intersection types', function () {
    $proxy = proxyOf();

    expect($proxy->intersection(new ArrayObject([1, 2, 3])))->toBe(3);
});

it('records a span with arguments in and return value out while recording', function () {
    $proxy = proxyOf();

    startTrace();
    $result = $proxy->plain(7, 'z');
    $spans = endTrace();

    $span = $spans[1];
    expect($result)->toBe('7zedge')
        ->and($span->type)->toBe(SpanType::Service)
        ->and($span->label)->toBe('EdgeCases::plain')
        ->and($span->parentId)->toBe($spans[0]->id)
        ->and($span->file)->toEndWith('tests/Fixtures/Proxy/EdgeCases.php')
        ->and($span->line)->toBeInt()
        ->and($span->inputValue()['i'])->toBe([['a', 7], ['b', 'z']])
        ->and($span->outputValue())->toBe('7zedge')
        ->and($span->open)->toBeFalse();
});

it('records the exception of a throwing method, re-throws it unchanged, and does not capture void output', function () {
    $proxy = proxyOf();

    startTrace();
    $caught = null;
    try {
        $proxy->throws('kaboom');
    } catch (RuntimeException $e) {
        $caught = $e;
    }
    $spans = endTrace();

    expect($caught)->toBeInstanceOf(RuntimeException::class)->and($caught->getMessage())->toBe('kaboom');
    $service = $spans[1];
    expect($service->label)->toBe('EdgeCases::throws')
        ->and($service->flag?->value)->toBe('failed')
        ->and($service->outputValue()['kind'])->toBe('exception');
    $exception = array_values(array_filter($spans, fn ($s) => $s->type === SpanType::Exception));
    expect($exception)->toHaveCount(1)->and($exception[0]->parentId)->toBe($service->id);
});

it('re-throws from a never-returning method', function () {
    $proxy = proxyOf();

    expect(fn () => $proxy->neverReturns())->toThrow(LogicException::class, 'never returns');

    startTrace();
    expect(fn () => $proxy->neverReturns())->toThrow(LogicException::class);
    $spans = endTrace();
    expect($spans[1]->label)->toBe('EdgeCases::neverReturns');
});

it('copies class, method and parameter attributes to the proxy', function () {
    $proxy = proxyOf();
    $r = new ReflectionClass($proxy);

    $class = $r->getAttributes(MarkerAttribute::class)[0]->newInstance();
    expect($class->name)->toBe('class-level')->and($class->flag)->toBeTrue()->and($class->list)->toBe(['a', 1]);

    $method = $r->getMethod('attributed');
    expect($method->getAttributes(MarkerAttribute::class)[0]->getArguments())->toBe(['method', 'flag' => false]);
    expect($method->getParameters()[0]->getAttributes(MarkerAttribute::class)[0]->getArguments())->toBe(['param']);
});

it('skips methods it cannot reproduce faithfully and says why', function () {
    $generator = new ProxyGenerator(sys_get_temp_dir().'/rt-proxy-notes-'.uniqid());
    $result = $generator->proxyFor(EdgeCases::class);

    expect($result->notes)->toContain(EdgeCases::class.'::finalMethod — not traceable: final method')
        ->and(implode("\n", $result->notes))->toContain('newInInitializer');
});

it('refuses final classes and reports them', function () {
    $generator = new ProxyGenerator(sys_get_temp_dir().'/rt-proxy-final-'.uniqid());
    $result = $generator->proxyFor(Span::class);

    expect($result->proxyClass)->toBeNull()->and($result->notes[0])->toContain('not traceable: final class');
});

it('ignores interfaces, abstract classes, enums and internal classes', function () {
    $generator = new ProxyGenerator(sys_get_temp_dir().'/rt-proxy-skip-'.uniqid());

    foreach ([Countable::class, Suit::class, ArrayObject::class, ServiceProvider::class] as $class) {
        expect($generator->proxyFor($class)->proxyClass)->toBeNull($class);
    }
});

it('restricts the proxy to chosen methods', function () {
    $generator = new ProxyGenerator(sys_get_temp_dir().'/rt-proxy-only-'.uniqid());
    $class = CouponService::class;
    $result = $generator->proxyFor($class, ['only' => []]);
    expect($result->proxyClass)->toBeNull();
});

it('regenerates the proxy when the source file changes', function () {
    $dir = sys_get_temp_dir().'/rt-proxy-mtime-'.uniqid();
    $source = sys_get_temp_dir().'/rt-mtime-'.uniqid().'.php';
    $name = 'MtimeSample'.uniqid();
    file_put_contents($source, "<?php class {$name} { public function a(): int { return 1; } }");
    require $source;

    $generator = new ProxyGenerator($dir);
    $first = $generator->proxyFor($name);
    $generated = glob($dir.'/*.php');
    $mtimeBefore = filemtime($generated[0]);

    sleep(1);
    $second = $generator->proxyFor($name);                       // unchanged: served from cache
    expect(filemtime($generated[0]))->toBe($mtimeBefore)->and($second->proxyClass)->toBe($first->proxyClass);

    touch($source, time() + 10);
    clearstatcache();
    $generator->proxyFor($name);                                 // changed: rewritten
    expect(filemtime($generated[0]))->toBeGreaterThan($mtimeBefore);

    @unlink($source);
});

it('is safe when the tracer itself fails during a call', function () {
    $proxy = proxyOf();

    startTrace();
    // an unserialisable argument must not break the call
    $result = $proxy->untyped(fopen('php://memory', 'r'), new class
    {
        public $self;

        public function __construct()
        {
            $this->self = $this;
        }
    });
    endTrace();

    expect($result)->toBeArray();
});
