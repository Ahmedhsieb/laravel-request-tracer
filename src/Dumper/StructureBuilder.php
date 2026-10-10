<?php

declare(strict_types=1);

namespace RequestTracer\Dumper;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\VarDumper\Cloner\Cursor;
use Symfony\Component\VarDumper\Cloner\DumperInterface;

/**
 * Turns a Symfony `Data` walk into the JSON-able structure the dashboard renders (see docs/05-how-capture-works.md).
 *
 * Node shapes (`$` is the node type; plain JSON scalars stand for themselves):
 *   {"$":"arr","n":3,"list":true,"i":[node,...],"cut":0}         list      -> i is [node,...]
 *   {"$":"arr","n":3,"i":[[key,node],...]}                         map
 *   {"$":"obj","c":"Class","kind":"model","i":[[name,vis,node]]}  object ("vis" = public|protected|private|virtual|dynamic)
 *   {"$":"str","v":"abc…","cut":120}                               truncated string
 *   {"$":"mask"}  {"$":"type","t":"Closure"}  {"$":"bin","len":N}  {"$":"float","v":"INF"}  {"$":"int","v":"…"}
 *
 * Secrets: a child whose key matches the Masker is replaced by {"$":"mask"} and its subtree is never visited.
 */
final class StructureBuilder implements DumperInterface
{
    private const TYPE_ONLY = [
        \Closure::class => true,
        'Closure()' => true,
        \PDO::class => true,
        \PDOStatement::class => true,
        \Generator::class => true,
        Container::class => true,
        Application::class => true,
        Connection::class => true,
        LazyCollection::class => true,
        \ReflectionClass::class => true,
        \Illuminate\Contracts\Container\Container::class => true,
    ];

    /** @var list<array<string, mixed>> */
    private array $stack = [];

    private mixed $result = null;

    /** @var array<string, string|null> class => kind */
    private array $kinds = [];

    public int $bytes = 0;

    public bool $masked = false;

    public bool $truncated = false;

    public function __construct(
        private readonly Masker $masker,
        private readonly int $maxItems,
    ) {}

    public function result(): mixed
    {
        return $this->result;
    }

    public function dumpScalar(Cursor $cursor, string $type, string|int|float|bool|null $value): void
    {
        if ($this->capacityExceeded()) {
            return;
        }
        if ($this->keyIsSecret($cursor)) {
            $this->attach($cursor, $this->maskNode());

            return;
        }

        $this->attach($cursor, $this->scalar($value));
    }

    public function dumpString(Cursor $cursor, string $str, bool $bin, int $cut): void
    {
        if ($this->capacityExceeded()) {
            return;
        }
        if ($this->keyIsSecret($cursor)) {
            $this->attach($cursor, $this->maskNode());

            return;
        }
        if ($bin || ! preg_match('//u', $str)) {
            $this->attach($cursor, ['$' => 'bin', 'len' => strlen($str)]);

            return;
        }

        $str = $this->masker->maskString($str);
        $this->bytes += strlen($str) + 4;
        if ($cut > 0) {
            $this->truncated = true;
            $this->attach($cursor, ['$' => 'str', 'v' => $str, 'cut' => $cut]);

            return;
        }
        $this->attach($cursor, $str);
    }

    public function enterHash(Cursor $cursor, int $type, string|int|null $class, bool $hasChild): void
    {
        $mode = 'normal';
        if ($this->stack !== []) {
            if ($this->capacityExceeded()) {
                $mode = 'skip';
            } elseif ($this->keyIsSecret($cursor)) {
                $mode = 'mask';
            }
        }
        if ($mode === 'normal' && ($type === Cursor::HASH_RESOURCE || ($type === Cursor::HASH_OBJECT && isset(self::TYPE_ONLY[(string) $class]))
            || ($type === Cursor::HASH_OBJECT && $class === MaskedValue::class))) {
            $mode = $class === MaskedValue::class ? 'mask' : 'type';
        }
        if ($mode !== 'normal') {
            $cursor->skipChildren = true;
        }

        [$name, $vis] = $this->splitKey($cursor->hashKey);
        $this->stack[] = [
            'type' => $type,
            'class' => $class,
            'mode' => $mode,
            'items' => [],
            'list' => true,
            'next' => 0,
            'added' => 0,
            'cut' => 0,
            'key' => $cursor->hashKey,
            'name' => $name,
            'vis' => $vis,
        ];
    }

    public function leaveHash(Cursor $cursor, int $type, string|int|null $class, bool $hasChild, int $cut): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return;
        }

        $mode = $frame['mode'];
        if ($mode === 'skip') {
            return; // parent already counted it as cut
        }
        if ($mode === 'mask') {
            $this->attachWithKey($frame['key'], $this->maskNode());

            return;
        }
        if ($mode === 'type') {
            $label = $type === Cursor::HASH_RESOURCE ? 'resource ('.$class.')' : rtrim((string) $class, '()');
            if ($label === 'Closure') {
                $label = 'Closure';
            }
            $this->attachWithKey($frame['key'], ['$' => 'type', 't' => $label]);

            return;
        }

        $totalCut = $frame['cut'] + ($cut > 0 ? $cut : 0);
        if ($totalCut > 0) {
            $this->truncated = true;
        }
        $this->bytes += 16;

        if ($type === Cursor::HASH_OBJECT) {
            $node = ['$' => 'obj', 'c' => (string) $class];
            $kind = $this->kindOf((string) $class) ?? $this->kindFromItems($frame['items']);
            if ($kind !== null) {
                $node['kind'] = $kind;
            }
            if ($frame['items'] !== []) {
                $node['i'] = $frame['items'];
            }
        } else {
            $node = ['$' => 'arr', 'n' => (int) $class];
            if ($frame['list'] && $frame['items'] !== []) {
                $node['list'] = true;
            }
            if ($frame['items'] !== []) {
                $node['i'] = $frame['items'];
            }
        }
        if ($totalCut > 0) {
            $node['cut'] = $totalCut;
        }

        $this->attachWithKey($frame['key'], $node);
    }

    private function capacityExceeded(): bool
    {
        if ($this->stack === []) {
            return false;
        }
        $top = &$this->stack[array_key_last($this->stack)];
        if ($top['added'] >= $this->maxItems) {
            $top['cut']++;

            return true;
        }

        return false;
    }

    private function keyIsSecret(Cursor $cursor): bool
    {
        if ($this->stack === []) {
            return false;
        }
        $top = $this->stack[array_key_last($this->stack)];
        if ($top['type'] === Cursor::HASH_INDEXED || $cursor->hashKey === null) {
            return false;
        }
        [$name] = $this->splitKey($cursor->hashKey);
        $secret = $this->masker->isSensitiveKey($name);
        if ($secret) {
            $this->masked = true;
        }

        return $secret;
    }

    /** @param  array<string, mixed>|string|int|float|bool|null  $node */
    private function attach(Cursor $cursor, mixed $node): void
    {
        $this->attachWithKey($cursor->hashKey, $node);
    }

    /** @param  array<string, mixed>|string|int|float|bool|null  $node */
    private function attachWithKey(string|int|null $key, mixed $node): void
    {
        if ($this->stack === []) {
            $this->result = $node;

            return;
        }

        $idx = array_key_last($this->stack);
        $frame = &$this->stack[$idx];
        if ($frame['added'] >= $this->maxItems) {
            $frame['cut']++;

            return;
        }
        $frame['added']++;
        $this->bytes += 8;

        if ($frame['type'] === Cursor::HASH_OBJECT) {
            [$name, $vis] = $this->splitKey($key);
            $frame['items'][] = [$name, $vis, $node];

            return;
        }

        if ($frame['type'] === Cursor::HASH_RESOURCE) {
            return;
        }

        if ($frame['list'] && $key === $frame['next']) {
            $frame['items'][] = $node;
            $frame['next']++;

            return;
        }
        if ($frame['list']) {
            // not a list after all: convert what we have to [key, node] pairs
            $pairs = [];
            foreach ($frame['items'] as $i => $item) {
                $pairs[] = [$i, $item];
            }
            $frame['items'] = $pairs;
            $frame['list'] = false;
        }
        $frame['items'][] = [$this->plainKey($key), $node];
    }

    /** @return array{0: string, 1: string} */
    private function splitKey(string|int|null $key): array
    {
        if ($key === null) {
            return ['', 'public'];
        }
        if (is_int($key)) {
            return [(string) $key, 'public'];
        }
        if ($key !== '' && $key[0] === "\0") {
            $end = strpos($key, "\0", 1);
            if ($end !== false) {
                $prefix = substr($key, 1, $end - 1);
                $name = substr($key, $end + 1);
                $vis = match ($prefix) {
                    '*' => 'protected',
                    '~' => 'virtual',
                    '+' => 'dynamic',
                    default => 'private',
                };

                return [$name, $vis];
            }
        }

        return [$key, 'public'];
    }

    private function plainKey(string|int|null $key): string|int
    {
        if ($key === null) {
            return '';
        }
        if (is_int($key)) {
            return $key;
        }
        if (! preg_match('//u', $key)) {
            return '[binary key]';
        }

        return $key;
    }

    /** @return array<string, string> */
    private function maskNode(): array
    {
        $this->masked = true;

        return ['$' => 'mask'];
    }

    /** @return array<string, string>|string|int|float|bool|null */
    private function scalar(string|int|float|bool|null $value): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            return ['$' => 'float', 'v' => is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')];
        }
        if (is_int($value) && ($value > 9007199254740991 || $value < -9007199254740991)) {
            return ['$' => 'int', 'v' => (string) $value];
        }
        if (is_string($value)) {
            return $this->masker->maskString($value);
        }
        $this->bytes += 6;

        return $value;
    }

    /**
     * Class-agnostic detection (anonymous and proxied classes lose their parent in Symfony's display name):
     * casters emit well-known virtual keys, so the keys identify the kind.
     *
     * @param  list<array{0: string, 1: string, 2: mixed}>  $items
     */
    private function kindFromItems(array $items): ?string
    {
        $virtual = [];
        foreach ($items as $item) {
            if ($item[1] === 'virtual') {
                $virtual[$item[0]] = true;
            }
        }
        if (isset($virtual['attributes'], $virtual['relations'])) {
            return 'model';
        }
        if (isset($virtual['count'], $virtual['items'])) {
            return 'collection';
        }
        if (isset($virtual['message'], $virtual['file'], $virtual['line'])) {
            return 'exception';
        }
        if (isset($virtual['method'], $virtual['path'], $virtual['headers'])) {
            return 'request';
        }
        if (isset($virtual['status'], $virtual['headers'])) {
            return 'response';
        }

        return null;
    }

    private function kindOf(string $class): ?string
    {
        if (array_key_exists($class, $this->kinds)) {
            return $this->kinds[$class];
        }

        $kind = null;
        if (is_a($class, Model::class, true)) {
            $kind = 'model';
        } elseif (is_a($class, Collection::class, true)) {
            $kind = 'collection';
        } elseif (is_a($class, \Throwable::class, true)) {
            $kind = 'exception';
        } elseif (is_a($class, Request::class, true)) {
            $kind = 'request';
        } elseif (is_a($class, Response::class, true)) {
            $kind = 'response';
        } elseif (is_a($class, \UnitEnum::class, true)) {
            $kind = 'enum';
        }

        return $this->kinds[$class] = $kind;
    }
}
