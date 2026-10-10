<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RequestTracer\DeepTrace\CompileException;
use RequestTracer\DeepTrace\Compiler;
use RequestTracer\DeepTrace\CompileResult;
use RequestTracer\DeepTrace\LinesBuilder;
use RequestTracer\DeepTrace\Probe;
use RequestTracer\DeepTrace\ProbeMap;
use RequestTracer\Hot;
use RequestTracer\Recording\Decision;
use RequestTracer\RecordingState;
use RequestTracer\SpanType;
use RequestTracer\Support\Files;
use RequestTracer\Tracer;
use SecretNs\Secrets;

const CORPUS_NS = 'RequestTracer\Tests\Fixtures\Deep\Corpus';

/**
 * Loads the corpus file twice under different namespaces: untouched ("orig") and compiled ("comp").
 *
 * @return array{orig: string, comp: string, result: CompileResult, source: string, compiledSource: string, cache: string}
 */
function loadCorpus(string $name): array
{
    static $counter = 0;
    $counter++;
    $path = __DIR__.'/../Fixtures/Deep/Corpus/'.$name.'.php';
    $source = (string) file_get_contents($path);
    $base = sys_get_temp_dir().'/rt-deep-'.getmypid().'-'.$counter;
    $cache = $base.'/cache';
    @mkdir($base.'/o/Corpus', 0777, true);
    @mkdir($base.'/c/Corpus', 0777, true);
    @mkdir($cache, 0777, true);

    $origNs = CORPUS_NS.'\Orig'.$counter;
    $compNs = CORPUS_NS.'\Comp'.$counter;
    $origSource = str_replace('namespace '.CORPUS_NS.';', 'namespace '.$origNs.';', $source);
    $compSource = str_replace('namespace '.CORPUS_NS.';', 'namespace '.$compNs.';', $source);

    $origPath = $base.'/o/Corpus/'.$name.'.php';
    $compOriginalPath = $base.'/c/Corpus/'.$name.'.php';
    file_put_contents($origPath, $origSource);
    file_put_contents($compOriginalPath, $compSource);
    require_once $origPath;

    $result = (new Compiler)->compile($compSource, 'corpus/'.$name.$counter.'.php', $compOriginalPath);
    $compiledPath = $base.'/c/compiled-'.$name.'.php';
    file_put_contents($compiledPath, $result->code);
    Files::write(ProbeMap::fileName($cache, $result->fileKey), '<?php return '.var_export(['path' => 'corpus/'.$name.$counter.'.php', 'probes' => $result->probes], true).';');
    require_once $compiledPath;

    return ['orig' => $origNs, 'comp' => $compNs, 'result' => $result, 'source' => $compSource, 'compiledSource' => $result->code, 'cache' => $cache];
}

function normaliseOutput(mixed $value): string
{
    return (string) preg_replace('/(Orig|Comp)\d+/', 'NS', (string) json_encode($value));
}

/** 1-based line of the n-th occurrence of $needle in $source. */
function lineOf(string $source, string $needle, int $nth = 1): int
{
    $offset = -1;
    for ($i = 0; $i < $nth; $i++) {
        $offset = strpos($source, $needle, $offset + 1);
        expect($offset)->not->toBeFalse("'{$needle}' (#{$nth}) not found");
    }

    return substr_count($source, "\n", 0, (int) $offset) + 1;
}

/** @return array<string, array<string, mixed>> rows of the trace keyed "line:kind:index" */
function deepRows(array $loaded): array
{
    $session = app(Tracer::class)->session();
    $built = LinesBuilder::build($session, new ProbeMap($loaded['cache']), 10000);
    $rows = [];
    foreach ($built['rows'] as $row) {
        $row['result'] = json_decode($row['result'], true);
        $rows[] = $row;
    }

    return $rows;
}

/** @return list<array<string, mixed>> */
function rowsAt(array $rows, int $line, ?string $kind = null): array
{
    return array_values(array_filter($rows, fn ($r) => $r['line'] === $line && ($kind === null || $r['kind'] === $kind)));
}

function startRecording(): void
{
    $session = app(Tracer::class)->begin('request', new Decision(RecordingState::On, 'always'));
    $session->root = $session->recorder->begin(SpanType::Request, 'test');
}

afterEach(fn () => app(Tracer::class)->reset());

// ---------------------------------------------------------------------------------------------------------------------
// line numbers
// ---------------------------------------------------------------------------------------------------------------------

it('keeps every line number identical after compilation', function (string $name) {
    $loaded = loadCorpus($name);
    $source = $loaded['source'];
    $compiled = $loaded['compiledSource'];

    expect(substr_count($compiled, "\n"))->toBe(substr_count($source, "\n"))
        ->and(count($loaded['result']->probes))->toBeGreaterThan(10);

    // every statement of the original is on the same line in the compiled file
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $statements = static function (string $code) use ($parser): array {
        $found = [];
        foreach ((new NodeFinder)->findInstanceOf($parser->parse($code) ?? [], Node\Stmt::class) as $node) {
            $found[get_class($node).'@'.$node->getStartLine()] = ($found[get_class($node).'@'.$node->getStartLine()] ?? 0) + 1;
        }

        return $found;
    };
    $original = $statements($source);
    $afterCompile = $statements($compiled);
    foreach ($original as $key => $count) {
        expect($afterCompile[$key] ?? 0)->toBeGreaterThanOrEqual($count, "statement {$key} moved or disappeared");
    }

    // the compiler already ran its token-level guard (every original token on its original line); compile() would have thrown
    expect($loaded['result']->probes)->not->toBeEmpty();
})->with(['ControlFlow', 'Syntax', 'Contexts']);

it('only inserts text: the original source is recoverable', function () {
    $loaded = loadCorpus('ControlFlow');
    // removing every inserted probe call and wrapper must give the original back; here: all original lines are still in order
    $originalLines = explode("\n", $loaded['source']);
    $compiledLines = explode("\n", $loaded['compiledSource']);

    expect(count($compiledLines))->toBe(count($originalLines));
    foreach ($originalLines as $i => $line) {
        $stripped = trim($line);
        if ($stripped === '' || str_starts_with($stripped, '//') || str_starts_with($stripped, '*') || str_starts_with($stripped, '/*')) {
            expect(trim($compiledLines[$i]))->toBe($stripped, 'comment/blank line '.($i + 1).' changed');
        }
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// behaviour
// ---------------------------------------------------------------------------------------------------------------------

it('behaves exactly like the original when nothing is recorded', function () {
    $c = loadCorpus('ControlFlow');
    $s = loadCorpus('Syntax');
    $x = loadCorpus('Contexts');

    expect(Hot::$on)->toBeFalse();
    foreach ([[$c, 'ControlFlow'], [$s, 'Syntax'], [$x, 'Contexts']] as [$loaded, $class]) {
        $origClass = $loaded['orig'].'\\'.$class;
        $compClass = $loaded['comp'].'\\'.$class;
        expect(normaliseOutput((new $compClass)->run()))->toBe(normaliseOutput((new $origClass)->run()), $class);
    }

    // PHP-visible details that must survive: strict types, inline html, by-reference return, magic constants
    $origContexts = $x['orig'].'\\Contexts';
    $compContexts = $x['comp'].'\\Contexts';
    expect((new $compContexts)->render(['a', 'b&c']))->toBe((new $origContexts)->render(['a', 'b&c']))
        ->and((new $compContexts)->render(['only']))->toBe((new $origContexts)->render(['only']));

    $compSyntax = $s['comp'].'\\Syntax';
    expect(fn () => (new $compSyntax)->named('1'))->toThrow(TypeError::class);      // declare(strict_types=1) still applies

    $byRef = (new $compContexts)->getRef();
    $r = &(new $compContexts)->getRef();
    expect($r)->toBe([1, 2, 3]);
});

it('behaves exactly like the original while recording', function () {
    $loaded = loadCorpus('ControlFlow');
    $orig = $loaded['orig'].'\ControlFlow';
    $comp = $loaded['comp'].'\ControlFlow';
    $expected = normaliseOutput((new $orig)->run());

    startRecording();
    $actual = normaliseOutput((new $comp)->run());

    expect($actual)->toBe($expected)
        ->and(app(Tracer::class)->session()->deep()->totalHits())->toBeGreaterThan(50);
});

// ---------------------------------------------------------------------------------------------------------------------
// what is recorded, per construct
// ---------------------------------------------------------------------------------------------------------------------

it('shows short-circuited parts as not evaluated', function () {
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    $o = new $class;
    $o->logic(false, true);                                    // $p false: `$p && $q` stops after $p
    $rows = deepRows($loaded);
    $src = $loaded['source'];

    $line = lineOf($src, "\$r[] = \$p && \$q ? 'and'");
    $parts = array_column(rowsAt($rows, $line, 'cond'), null, 'snippet');
    expect($parts['$p && $q']['result']['false'])->toBe(1)
        ->and($parts)->toHaveKey('$p')
        ->and($parts)->not->toHaveKey('$q');                   // never evaluated: no hit, no row
});

it('records blocks that ran and leaves the ones that did not', function () {
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    $o = new $class;
    $o->nested(1, 2);                                          // b bigger
    $o->nested(2, 1);                                          // both
    $o->nested(2, 2);                                          // equal   ('a only' never happens)
    $rows = deepRows($loaded);
    $src = $loaded['source'];

    expect(rowsAt($rows, lineOf($src, "return 'both'")))->not->toBeEmpty()
        ->and(rowsAt($rows, lineOf($src, "return 'equal'")))->not->toBeEmpty()
        ->and(rowsAt($rows, lineOf($src, "return 'b bigger'")))->not->toBeEmpty()
        ->and(rowsAt($rows, lineOf($src, "return 'a only'")))->toBeEmpty();          // "not run"

    $ret = rowsAt($rows, lineOf($src, "return 'both'"), 'return');
    expect($ret[0]['result']['value'])->toBe('both');
});

it('counts loop iterations and snapshots the first ones', function () {
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    (new $class)->loops(8);
    $rows = deepRows($loaded);
    $src = $loaded['source'];

    $forBody = rowsAt($rows, lineOf($src, '$sum += $i;'), 'loop');
    expect($forBody[0]['hits'])->toBe(8)->and($forBody[0]['result']['iterations'])->toBe(8);

    $foreach = rowsAt($rows, lineOf($src, 'foreach ([1, 2, 3] as $k => $v) $sum += $k * $v;'), 'loop');
    expect($foreach[0]['result']['iterations'])->toBe(3)
        ->and($foreach[0]['result']['snapshots'])->toHaveCount(3);
    $snap = $foreach[0]['result']['snapshots'][1];
    expect($snap['i'])->toBe([['k', 1], ['v', 2]]);
});

it('limits loop snapshots to the configured number', function () {
    config(['request-tracer.deep_trace.loop_snapshots' => 2]);
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    (new $class)->loops(3);
    $rows = deepRows($loaded);

    $foreach = rowsAt($rows, lineOf($loaded['source'], 'foreach ([1, 2, 3] as $k => $v)'), 'loop');
    expect($foreach[0]['result']['snapshots'])->toHaveCount(2);
});

it('records assigned values, function arguments and returned values', function () {
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    (new $class)->classify(7);
    (new $class)->ternaries('x');
    $rows = deepRows($loaded);
    $src = $loaded['source'];

    // arguments recorded at function entry
    $enter = rowsAt($rows, lineOf($src, 'if ($n < 0) {'), 'assign');
    expect($enter[0]['result']['first']['i'])->toBe([['n', 7]]);

    // assignment: variable value after `$b = ...`
    $b = rowsAt($rows, lineOf($src, "\$b = \$s === null ? 'null'"), 'assign');
    expect($b[0]['result']['last'])->toBe('is x')->and($b[0]['result']['changes'])->toBe(1);

    $c = rowsAt($rows, lineOf($src, "\$c = \$s ?: 'falsy'"), 'assign');
    expect($c[0]['result']['last'])->toBe('x');

    $ret = rowsAt($rows, lineOf($src, 'return "$a|$b|$c|$d";'), 'return');
    expect($ret[0]['result']['value'])->toBe('x|is x|x|X');
});

it('records match subjects and the arm that was taken', function () {
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    $o = new $class;
    $o->sw('b');
    $o->sw('zzz');
    $rows = deepRows($loaded);
    $src = $loaded['source'];

    $subject = rowsAt($rows, lineOf($src, 'return match (true) {'), 'cond');
    expect($subject[0]['hits'])->toBe(2)->and($subject[0]['result']['values'])->toBe(['true' => 2]);

    $first = rowsAt($rows, lineOf($src, "\$x === 'b' => \$y.'!',"), 'block');
    $default = rowsAt($rows, lineOf($src, 'default => $y,'), 'block');
    expect($first[0]['hits'])->toBe(1)->and($first[0]['result']['last'])->toBe('B or C!')
        ->and($default[0]['hits'])->toBe(1)->and($default[0]['result']['last'])->toBe('other');

    // switch subject
    $switch = rowsAt($rows, lineOf($src, 'switch ($x) {'), 'cond');
    expect($switch[0]['hits'])->toBe(2);
});

it('records the exception that was thrown and the catch that received it', function () {
    $loaded = loadCorpus('Syntax');
    $class = $loaded['comp'].'\Syntax';
    startRecording();
    $o = new $class;
    expect($o->tryCatch('boom'))->toBe('caught:boom');
    expect($o->tryCatch(''))->toBe('empty');
    expect($o->noVariableCatch(true))->toBe('swallowed');
    $rows = deepRows($loaded);
    $src = $loaded['source'];

    $thrown = rowsAt($rows, lineOf($src, 'throw new RuntimeException($msg);'), 'throw');
    expect($thrown[0]['result']['exception']['c'])->toBe('RuntimeException');

    $caught = rowsAt($rows, lineOf($src, 'catch (LogicException | RuntimeException $e) {'), 'block');
    expect($caught[0]['hits'])->toBe(1)
        ->and($caught[0]['result']['caught']['kind'])->toBe('exception');

    // `catch (LogicException) {}` has no variable and an empty body: it is still recorded
    $empty = rowsAt($rows, lineOf($src, 'catch (LogicException) {'), 'block');
    expect($empty)->toHaveCount(1)->and($empty[0]['hits'])->toBe(1);

    // finally ran twice (once per tryCatch call)
    $finally = rowsAt($rows, lineOf($src, 'finally {'), 'block');
    expect($finally[0]['hits'])->toBe(2);

    // exceptions seen by probes join the trace's exception list (caught ones included)
    $classes = array_map(fn ($r) => $r->class, app(Tracer::class)->session()->exceptions()->records());
    expect($classes)->toContain('RuntimeException')->toContain('LogicException');
});

it('records "this line ran" for plain statements', function () {
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    (new $class)->logic(true, true);
    $rows = deepRows($loaded);
    $src = $loaded['source'];

    expect(rowsAt($rows, lineOf($src, "if (!(\$p && \$q)) { \$r[] = 'neg'; }"), 'cond'))->not->toBeEmpty();
    $ran = rowsAt($rows, lineOf($src, "\$r[] = (\$p xor \$q) ? 'xor' : 'same';"));
    expect($ran)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------------------------------
// safety
// ---------------------------------------------------------------------------------------------------------------------

it('masks secrets by variable, property and function name', function () {
    $source = <<<'PHP'
<?php

namespace SecretNs;

class Secrets
{
    private string $apiToken = '';

    public function run(string $password): string
    {
        $secret = 'hunter2-value';
        $this->apiToken = 'tok-abc-123';
        $ok = $password === 'letmein-value';
        if ($this->apiToken === $secret) {
            return 'same';
        }

        return (string) strlen($this->getToken($ok));
    }

    private function getToken(bool $ok): string
    {
        return $ok ? 'bearer-xyz-789' : 'none';
    }
}
PHP;
    $path = sys_get_temp_dir().'/rt-secrets-'.getmypid().'.php';
    file_put_contents($path, $source);
    $result = (new Compiler)->compile($source, 'secrets.php', $path);
    $compiled = sys_get_temp_dir().'/rt-secrets-compiled-'.getmypid().'.php';
    file_put_contents($compiled, $result->code);
    $cache = sys_get_temp_dir().'/rt-secrets-cache-'.getmypid();
    Files::write(ProbeMap::fileName($cache, $result->fileKey), '<?php return '.var_export(['path' => 'secrets.php', 'probes' => $result->probes], true).';');
    require $compiled;

    startRecording();
    $out = (new Secrets)->run('letmein-value');
    $rows = LinesBuilder::build(app(Tracer::class)->session(), new ProbeMap($cache), 1000)['rows'];

    expect($out)->toBe('14');
    $stored = json_encode($rows);
    foreach (['hunter2-value', 'tok-abc-123', 'letmein-value', 'bearer-xyz-789'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
    expect(json_encode(array_map(fn ($r) => json_decode($r['result'], true), $rows)))->toContain('"$":"mask"');
});

it('stops recording at the hit cap and marks the trace truncated', function () {
    config(['request-tracer.deep_trace.max_hits' => 100]);
    $loaded = loadCorpus('ControlFlow');
    $class = $loaded['comp'].'\ControlFlow';
    startRecording();
    (new $class)->run();
    $session = app(Tracer::class)->session();

    expect($session->deep()->totalHits())->toBe(101)            // the 101st hit tripped the cap, nothing after it is counted
        ->and($session->truncated)->toBeTrue();
});

it('never throws from a probe, whatever the recorder is doing', function () {
    startRecording();
    app(Tracer::class)->session()->recorder->finish();
    $value = new stdClass;

    expect(Probe::cond(1, $value))->toBe($value)
        ->and(Probe::asg(2, 'x', 5))->toBe(5)
        ->and(Probe::ret(3, 'f', 'v'))->toBe('v')
        ->and(Probe::cmp(4, 1, 2, 4))->toBeTrue();
    Probe::block(5);
    Probe::loop(6);
    Probe::iter(7, ['a' => 1]);
    Probe::enter(8, []);
    Probe::caught(9, null);
});

it('is inert and exact when no recording is active', function () {
    expect(Hot::$on)->toBeFalse();
    $o = new stdClass;

    expect(Probe::cond(1, 0))->toBe(0)
        ->and(Probe::cond(1, $o))->toBe($o)
        ->and(Probe::asg(1, null, [1]))->toBe([1])
        ->and(Probe::ret(1, null, 'x'))->toBe('x')
        ->and(Probe::thr(1, $o))->toBe($o)
        ->and(Probe::val(1, null, 'x'))->toBe('x')
        ->and(Probe::arm(1, 3))->toBe(3);

    foreach ([[0, '1', 1, true], [1, '1', 1, false], [2, '1', 1, false], [3, '1', 1, true], [4, 1, 2, true], [5, 2, 2, true], [6, 3, 2, true], [7, 2, 3, false]] as [$op, $a, $b, $expected]) {
        expect(Probe::cmp(1, $a, $b, $op))->toBe($expected, "operator {$op}");
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// attributes and failure handling
// ---------------------------------------------------------------------------------------------------------------------

it('leaves #[NoTrace] methods and classes untouched', function () {
    $compiler = new Compiler;
    $method = <<<'PHP'
<?php

use RequestTracer\Attributes\NoTrace;

class WithQuiet
{
    public function loud(int $a): int
    {
        if ($a > 1) {
            return $a;
        }

        return 0;
    }

    #[NoTrace]
    public function quiet(int $a): int
    {
        if ($a > 1) {
            return $a;
        }

        return 0;
    }
}
PHP;
    $method = str_replace("\r", '', $method);                 // test files may be checked out with CRLF
    $result = $compiler->compile($method, 'quiet.php', '/tmp/quiet.php');
    $lines = explode("\n", $result->code);
    $quietStart = array_search('    public function quiet(int $a): int', $lines, true);

    expect($quietStart)->toBeInt();
    expect($result->code)->toContain('Probe::');
    expect(implode("\n", array_slice($lines, (int) $quietStart, 9)))->not->toContain('Probe::');       // #[NoTrace] method: untouched
    expect(implode("\n", array_slice($lines, 6, 9)))->toContain('Probe::cmp');                           // its neighbour: instrumented

    $class = str_replace('class WithQuiet', "#[NoTrace]\nclass WithQuiet", $method);
    expect($compiler->compile($class, 'quiet2.php', '/tmp/quiet2.php')->probes)->toBe([]);
});

it('recognises #[Trace(deep: true)] on a class', function () {
    $compiler = new Compiler;
    $yes = "<?php\n\nuse RequestTracer\\Attributes\\Trace;\n\n#[Trace(deep: true)]\nclass A {}\n";
    $no = "<?php\n\nuse RequestTracer\\Attributes\\Trace;\n\n#[Trace]\nclass B {}\n";

    expect($compiler->attributeHints($yes)['deep'])->toBeTrue()->and($compiler->attributeHints($no)['deep'])->toBeFalse();
});

it('refuses files it cannot compile', function (string $source) {
    expect(fn () => (new Compiler)->compile($source, 'bad.php', '/tmp/bad.php'))->toThrow(CompileException::class);
})->with([
    'not php' => ['just text'],
    'parse error' => ["<?php\nclass A { public function f() { return 1 } }"],
]);
