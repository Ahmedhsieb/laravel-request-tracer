<?php

declare(strict_types=1);

namespace RequestTracer\Proxy;

use RequestTracer\Attributes\NoTrace;
use RequestTracer\Attributes\Trace;
use RequestTracer\Support\Paths;

/**
 * Generates, caches and loads "traced" subclasses (layer 2).
 *
 * For a class `Foo` it writes `RequestTracer\Proxies\Foo__Proxy extends Foo` overriding every public, non-static, non-final
 * method. Each override costs one boolean check (`Hot::$on`) when nothing is recorded; when recording it opens a span with
 * the arguments, calls `parent::method()`, and closes the span with the return value or the exception.
 *
 * Everything is derived from reflection, so signatures are exact: by-reference and variadic parameters, nullable / union /
 * intersection types, `self` / `static` / `void` / `never` returns, constant and enum defaults, parameter and method
 * attributes. Constructors are never overridden, so the container builds the proxy exactly like the original.
 *
 * Generated files live in `storage/framework/request-tracer/proxies`, with a manifest recording the mtimes of every
 * source file the proxy was derived from (the class, its parents and traits); a changed file regenerates the proxy.
 */
final class ProxyGenerator
{
    public const VERSION = '3';

    private const NAMESPACE = 'RequestTracer\\Proxies';

    /** Methods that are never overridden (constructors, destructors, magic accessors, serialization hooks). */
    private const SKIP_METHODS = [
        '__construct' => 1, '__destruct' => 1, '__get' => 1, '__set' => 1, '__isset' => 1, '__unset' => 1, '__call' => 1,
        '__callstatic' => 1, '__clone' => 1, '__sleep' => 1, '__wakeup' => 1, '__serialize' => 1, '__unserialize' => 1,
        '__set_state' => 1, '__debuginfo' => 1, '__tostring' => 1,
    ];

    public function __construct(private readonly string $cachePath) {}

    /**
     * @param  array{only?: list<string>|null, middleware?: bool, vendor_methods?: bool}  $options
     *                                                                                              only: restrict to these method names (#[Trace] on single methods); middleware: label as middleware and only trace handle();
     *                                                                                              vendor_methods: also trace public methods inherited from vendor classes
     */
    public function proxyFor(string $class, array $options = []): ProxyResult
    {
        if (! class_exists($class)) {
            return new ProxyResult(null);
        }
        $ref = new \ReflectionClass($class);

        $notes = [];
        if ($ref->isInterface() || $ref->isTrait() || $ref->isEnum() || $ref->isAbstract() || $ref->isAnonymous() || $ref->isInternal() || ! $ref->isInstantiable()) {
            return new ProxyResult(null);
        }
        if ($ref->isFinal()) {
            return new ProxyResult(null, [$class.' — not traceable: final class']);
        }
        if ($ref->getAttributes(NoTrace::class) !== []) {
            return new ProxyResult(null);
        }

        $proxyClass = self::NAMESPACE.'\\'.self::proxyName($class);
        $key = hash('sha1', $class.'|'.self::VERSION.'|'.json_encode($options));
        $file = rtrim($this->cachePath, '/\\').DIRECTORY_SEPARATOR.$key.'.php';
        $meta = rtrim($this->cachePath, '/\\').DIRECTORY_SEPARATOR.$key.'.meta.php';

        $cached = $this->loadFromCache($proxyClass, $file, $meta);
        if ($cached !== null) {
            return $cached;
        }

        $methods = [];
        $count = 0;
        $only = isset($options['only']) ? array_flip($options['only']) : null;
        $middleware = (bool) ($options['middleware'] ?? false);
        $vendorMethods = (bool) ($options['vendor_methods'] ?? false);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor() || isset(self::SKIP_METHODS[strtolower($name)])) {
                continue;
            }
            if ($method->getDeclaringClass()->isInternal()) {
                continue;
            }
            if ($middleware && $name !== 'handle') {
                continue;
            }
            if ($only !== null && ! isset($only[$name])) {
                continue;
            }
            if (! $middleware && ! $vendorMethods && Paths::isInternal($method->getFileName() ?: null) && $method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->getAttributes(NoTrace::class) !== []) {
                continue;
            }
            if ($method->isFinal()) {
                $notes[] = $class.'::'.$name.' — not traceable: final method';

                continue;
            }
            if ($method->returnsReference()) {
                $notes[] = $class.'::'.$name.' — not traceable: returns by reference';

                continue;
            }
            $source = $this->emitMethod($ref, $method, $middleware, $notes);
            if ($source === null) {
                continue;
            }
            $methods[] = $source;
            $count++;
        }

        if ($count === 0) {
            return new ProxyResult(null, $notes);
        }

        $code = $this->emitClass($ref, $proxyClass, $methods);
        $this->write($file, $code);
        $this->write($meta, '<?php return '.var_export([
            'class' => $proxyClass,
            'notes' => $notes,
            'methods' => $count,
            'deps' => $this->dependencies($ref),
        ], true).';'.PHP_EOL);

        return $this->load($proxyClass, $file, $notes, $count);
    }

    public static function proxyName(string $class): string
    {
        return str_replace('\\', '_', ltrim($class, '\\')).'__Proxy';
    }

    // ------------------------------------------------------------------------------------------------
    // cache
    // ------------------------------------------------------------------------------------------------

    private function loadFromCache(string $proxyClass, string $file, string $meta): ?ProxyResult
    {
        if (! is_file($file) || ! is_file($meta)) {
            return null;
        }
        try {
            /** @var array{class: string, notes: list<string>, methods: int, deps: array<string, int>} $manifest */
            $manifest = include $meta;
            foreach ($manifest['deps'] as $path => $mtime) {
                if (@filemtime($path) !== $mtime) {
                    return null;
                }
            }

            return $this->load($proxyClass, $file, $manifest['notes'], $manifest['methods']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  list<string>  $notes */
    private function load(string $proxyClass, string $file, array $notes, int $count): ProxyResult
    {
        if (! class_exists($proxyClass, false)) {
            require_once $file;
            if (! class_exists($proxyClass, false)) {
                throw new \RuntimeException("The generated file {$file} does not declare {$proxyClass}.");
            }
        }

        return new ProxyResult($proxyClass, $notes, $count);
    }

    /**
     * @param  \ReflectionClass<object>  $ref
     * @return array<string, int> source file => mtime
     */
    private function dependencies(\ReflectionClass $ref): array
    {
        $deps = [__FILE__ => (int) filemtime(__FILE__)];       // a changed generator invalidates every proxy
        $queue = [$ref];
        while ($queue !== []) {
            $current = array_shift($queue);
            $file = $current->getFileName();
            if ($file !== false && is_file($file)) {
                $deps[$file] = (int) filemtime($file);
            }
            if ($parent = $current->getParentClass()) {
                $queue[] = $parent;
            }
            foreach ($current->getTraits() as $trait) {
                $queue[] = $trait;
            }
        }

        return $deps;
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $contents);
        if (! @rename($tmp, $path)) {                        // Windows cannot rename over an open/opcached file
            @unlink($path);
            @rename($tmp, $path);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    // ------------------------------------------------------------------------------------------------
    // emitting code
    // ------------------------------------------------------------------------------------------------

    /**
     * @param  \ReflectionClass<object>  $ref
     * @param  list<string>  $methods
     */
    private function emitClass(\ReflectionClass $ref, string $proxyClass, array $methods): string
    {
        $short = substr($proxyClass, strlen(self::NAMESPACE) + 1);
        $ignored = [];
        $attributes = $this->emitAttributes($ref->getAttributes(), $ignored);

        return "<?php\n\n"
            .'// Generated by request-tracer (proxy generator v'.self::VERSION."). Do not edit: it is rewritten when the source changes.\n"
            ."// Source: {$ref->getName()}\n\n"
            ."declare(strict_types=1);\n\n"
            .'namespace '.self::NAMESPACE.";\n\n"
            .$attributes
            ."class {$short} extends \\{$ref->getName()}\n{\n"
            .implode("\n", $methods)
            ."}\n";
    }

    /**
     * @param  \ReflectionClass<object>  $class
     * @param  list<string>  $notes
     */
    private function emitMethod(\ReflectionClass $class, \ReflectionMethod $method, bool $middleware, array &$notes): ?string
    {
        $name = $method->getName();
        $declaring = $method->getDeclaringClass();

        $params = [];
        $callArgs = [];
        $argNames = [];
        foreach ($method->getParameters() as $p) {
            $emitted = $this->emitParameter($p, $declaring);
            if ($emitted === null) {
                $notes[] = $class->getName().'::'.$name.' — not traceable: parameter $'.$p->getName().' has a default value that cannot be reproduced';

                return null;
            }
            $params[] = $emitted;
            $callArgs[] = ($p->isVariadic() ? '...' : '').'$'.$p->getName();
            $argNames[] = var_export($p->getName(), true).' => $'.$p->getName();
        }

        $returnType = $method->hasReturnType() ? $this->emitType($method->getReturnType(), $declaring) : null;
        $return = $returnType !== null ? ': '.$returnType : '';
        $isVoid = $returnType === 'void';
        $isNever = $returnType === 'never';

        $attributes = $this->emitAttributes($method->getAttributes(), $notes);
        $label = var_export($middleware ? self::shortName($class->getName()) : self::label($class->getName(), $name, $method), true);
        $file = var_export(Paths::relative($method->getFileName() ?: null), true);
        $line = $method->getStartLine() !== false ? (string) $method->getStartLine() : 'null';
        $kind = $middleware ? '\\RequestTracer\\Proxy\\Runtime::MIDDLEWARE' : '\\RequestTracer\\Proxy\\Runtime::SERVICE';
        $args = implode(', ', $callArgs);
        $argArray = $middleware ? '[]' : '['.implode(', ', $argNames).']';
        $signature = '    '.str_replace("\n", "\n    ", rtrim($attributes)).($attributes !== '' ? "\n    " : '')
            .'public function '.$name.'('.implode(', ', $params).')'.$return;

        $passThrough = $isVoid ? "parent::{$name}({$args});\n            return;" : ($isNever ? "parent::{$name}({$args});" : "return parent::{$name}({$args});");

        $body = "        if (! \\RequestTracer\\Hot::\$on) {\n            {$passThrough}\n        }\n\n"
            ."        \$__rt = \\RequestTracer\\Proxy\\Runtime::enter({$label}, {$file}, {$line}, {$kind}, {$argArray});\n"
            ."        try {\n";
        if ($isVoid || $isNever) {
            $body .= "            parent::{$name}({$args});\n";
        } else {
            $body .= "            \$__ret = parent::{$name}({$args});\n";
        }
        $body .= "        } catch (\\Throwable \$__e) {\n"
            ."            \\RequestTracer\\Proxy\\Runtime::fail(\$__rt, \$__e);\n\n"
            ."            throw \$__e;\n"
            ."        }\n";
        if ($isVoid) {
            $body .= "        \\RequestTracer\\Proxy\\Runtime::leave(\$__rt, null, true);\n";
        } elseif (! $isNever) {
            $body .= "        \\RequestTracer\\Proxy\\Runtime::leave(\$__rt, \$__ret);\n\n        return \$__ret;\n";
        }

        return $signature."\n    {\n".$body."    }\n";
    }

    private static function label(string $class, string $method, \ReflectionMethod $m): string
    {
        $attribute = $m->getAttributes(Trace::class)[0] ?? null;
        $custom = $attribute?->getArguments()['label'] ?? ($attribute?->getArguments()[0] ?? null);
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        return self::shortName($class).'::'.$method;
    }

    private static function shortName(string $class): string
    {
        return ($pos = strrpos($class, '\\')) === false ? $class : substr($class, $pos + 1);
    }

    /** @param  \ReflectionClass<object>  $declaring */
    private function emitParameter(\ReflectionParameter $p, \ReflectionClass $declaring): ?string
    {
        $out = '';
        $unused = [];
        $attributes = $this->emitAttributes($p->getAttributes(), $unused, inline: true);
        $out .= $attributes;
        if ($p->hasType()) {
            $out .= $this->emitType($p->getType(), $declaring).' ';
        }
        if ($p->isPassedByReference()) {
            $out .= '&';
        }
        if ($p->isVariadic()) {
            $out .= '...';
        }
        $out .= '$'.$p->getName();

        if ($p->isOptional() && ! $p->isVariadic() && $p->isDefaultValueAvailable()) {
            $default = $this->exportDefault($p, $declaring);
            if ($default === null) {
                return null;
            }
            $out .= ' = '.$default;
        }

        return $out;
    }

    /** @param  \ReflectionClass<object>  $declaring */
    private function emitType(?\ReflectionType $type, \ReflectionClass $declaring): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            $lower = strtolower($name);
            if ($lower === 'self') {
                $name = '\\'.$declaring->getName();
            } elseif ($type->isBuiltin() || $lower === 'static' || $lower === 'parent') {
                $name = $lower === 'parent' ? '\\'.($declaring->getParentClass() ? $declaring->getParentClass()->getName() : 'object') : $lower;
            } else {
                $name = '\\'.ltrim($name, '\\');
            }
            if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
                return '?'.$name;
            }

            return $name;
        }
        if ($type instanceof \ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $t) {
                $parts[] = $t instanceof \ReflectionIntersectionType
                    ? '('.implode('&', array_map(fn ($x) => $this->emitType($x, $declaring), $t->getTypes())).')'
                    : ltrim($this->emitType($t, $declaring), '?');
            }

            return implode('|', $parts);
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn ($x) => ltrim($this->emitType($x, $declaring), '?'), $type->getTypes()));
        }

        return 'mixed';
    }

    /** @param  \ReflectionClass<object>  $declaring */
    private function exportDefault(\ReflectionParameter $p, \ReflectionClass $declaring): ?string
    {
        try {
            if ($p->isDefaultValueConstant()) {
                $constant = (string) $p->getDefaultValueConstantName();
                if (str_starts_with($constant, 'self::')) {
                    return '\\'.$declaring->getName().substr($constant, 4);
                }
                if (str_starts_with($constant, 'static::')) {
                    return null;
                }
                if (! str_contains($constant, '::') && ! defined($constant) && str_contains($constant, '\\')) {
                    // an unqualified global constant (PHP_INT_MAX) is reported with the declaring file's namespace in front
                    $constant = substr($constant, (int) strrpos($constant, '\\') + 1);
                }

                return '\\'.ltrim($constant, '\\');
            }

            return $this->exportValue($p->getDefaultValue());
        } catch (\Throwable) {
            return null;
        }
    }

    /** PHP source for a value; throws for anything that cannot be written as a constant expression. */
    private function exportValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return $value === PHP_INT_MIN ? '(-9223372036854775807 - 1)' : (string) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? var_export($value, true) : (is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF'));
        }
        if (is_string($value)) {
            return var_export($value, true);
        }
        if (is_array($value)) {
            $items = [];
            $list = array_is_list($value);
            foreach ($value as $k => $v) {
                $items[] = ($list ? '' : var_export($k, true).' => ').$this->exportValue($v);
            }

            return '['.implode(', ', $items).']';
        }
        if ($value instanceof \UnitEnum) {
            return '\\'.$value::class.'::'.$value->name;
        }

        throw new \RuntimeException('Cannot export '.get_debug_type($value));
    }

    /**
     * @param  array<int, \ReflectionAttribute<object>>  $attributes
     * @param  list<string>  $notes
     */
    private function emitAttributes(array $attributes, array &$notes, bool $inline = false): string
    {
        $out = [];
        foreach ($attributes as $attribute) {
            $name = $attribute->getName();
            if (in_array($name, [Trace::class, NoTrace::class, \Attribute::class], true)) {
                continue;
            }
            try {
                $args = [];
                foreach ($attribute->getArguments() as $k => $v) {
                    $args[] = (is_string($k) ? $k.': ' : '').$this->exportValue($v);
                }
                $out[] = '\\'.ltrim($name, '\\').($args === [] ? '' : '('.implode(', ', $args).')');
            } catch (\Throwable) {
                $notes[] = 'attribute '.$name.' could not be copied to the proxy (non-constant arguments)';
            }
        }
        if ($out === []) {
            return '';
        }

        return $inline ? '#['.implode(', ', $out).'] ' : '#['.implode(', ', $out)."]\n";
    }
}
