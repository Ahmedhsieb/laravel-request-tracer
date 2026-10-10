<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use RequestTracer\Dumper\MaskedValue;
use RequestTracer\Hot;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;
use RequestTracer\TraceSession;

/**
 * The runtime of Deep trace: the compiled copy of a file calls these static methods.
 *
 * Contract of every method: with no recording active it returns at once (value-returning probes hand their argument back
 * untouched), and with recording active it never throws and never alters a value, so the compiled code behaves exactly like
 * the original. Probe ids map to {file, line, kind, snippet} through the map written next to the compiled file.
 */
final class Probe
{
    private const OPS = [0 => '==', 1 => '!=', 2 => '===', 3 => '!==', 4 => '<', 5 => '<=', 6 => '>', 7 => '>='];

    /** `if ($a > $b)`: compares exactly like the operator, records both operands and the result. */
    public static function cmp(int $id, mixed $a, mixed $b, int $op, ?string $nameA = null, ?string $nameB = null): bool
    {
        $result = match ($op) {
            0 => $a == $b,
            1 => $a != $b,
            2 => $a === $b,
            3 => $a !== $b,
            4 => $a < $b,
            5 => $a <= $b,
            6 => $a > $b,
            default => $a >= $b,
        };
        if (! Hot::$on) {
            return $result;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::COND);
                if ($row !== null) {
                    $row[$result ? 'true' : 'false'] = ($row[$result ? 'true' : 'false'] ?? 0) + 1;
                    if (count($row['samples'] ?? []) < $store->snapshotLimit()) {
                        $row['samples'][] = [
                            'a' => self::dump($nameA, $a),
                            'b' => self::dump($nameB, $b),
                            'op' => self::OPS[$op] ?? '?',
                            'result' => $result,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe cmp');
        }

        return $result;
    }

    /** Any other condition, or a short-circuit part of `&&` / `||`. Returns $value untouched. */
    public static function cond(int $id, mixed $value): mixed
    {
        if (! Hot::$on) {
            return $value;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::COND);
                if ($row !== null) {
                    $key = $value ? 'true' : 'false';
                    $row[$key] = ($row[$key] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe cond');
        }

        return $value;
    }

    /** First statement of an if / else / catch / finally / case body, or any plain statement ("this line ran"). */
    public static function block(int $id): void
    {
        if (! Hot::$on) {
            return;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::BLOCK);
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe block');
        }
    }

    /** Body of a while / for / do loop: one hit per iteration. */
    public static function loop(int $id): void
    {
        if (! Hot::$on) {
            return;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::LOOP);
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe loop');
        }
    }

    /**
     * Body of a foreach: one hit per iteration, with a snapshot of the loop variables for the first iterations.
     *
     * @param  array<string, mixed>  $vars  loop variable name => value
     */
    public static function iter(int $id, array $vars): void
    {
        if (! Hot::$on) {
            return;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::LOOP);
                if ($row !== null && count($row['snapshots'] ?? []) < $store->snapshotLimit()) {
                    $row['snapshots'][] = self::session()?->dump($vars);
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe iter');
        }
    }

    /** `$x = <expr>`: records the assigned value, returns it. */
    public static function asg(int $id, ?string $name, mixed $value): mixed
    {
        if (! Hot::$on) {
            return $value;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::ASSIGN);
                if ($row !== null) {
                    $row['changes'] = ($row['changes'] ?? 0) + 1;
                    if ($row['changes'] <= $store->sampleLimit()) {
                        $json = self::dump($name, $value);
                        $row['first'] ??= $json;
                        $row['last'] = $json;
                    }
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe asg');
        }

        return $value;
    }

    /**
     * Function entry: the arguments the function was called with.
     *
     * @param  array<string, mixed>  $vars
     */
    public static function enter(int $id, array $vars): void
    {
        if (! Hot::$on) {
            return;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::ASSIGN);
                if ($row !== null) {
                    $row['changes'] = ($row['changes'] ?? 0) + 1;
                    if ($row['changes'] <= $store->sampleLimit()) {
                        $json = self::session()?->dump($vars);
                        $row['first'] ??= $json;
                        $row['last'] = $json;
                    }
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe enter');
        }
    }

    /** `return <expr>`: records the returned value, returns it. */
    public static function ret(int $id, ?string $function, mixed $value): mixed
    {
        if (! Hot::$on) {
            return $value;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::RETURN);
                if ($row !== null && $row['hits'] <= $store->sampleLimit()) {
                    $row['last'] = self::dump($function, $value);
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe ret');
        }

        return $value;
    }

    /** `throw <expr>`: records the exception being thrown, returns it. */
    public static function thr(int $id, mixed $exception): mixed
    {
        if (! Hot::$on) {
            return $exception;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::THROW);
                if ($row !== null) {
                    $row['last'] = self::session()?->dump($exception);
                    if ($exception instanceof \Throwable) {
                        self::session()?->exceptions()->record($exception);
                    }
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe thr');
        }

        return $exception;
    }

    /** First statement of a `catch` block: the exception that arrived (and where it was thrown). */
    public static function caught(int $id, ?\Throwable $exception): void
    {
        if (! Hot::$on) {
            return;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::BLOCK);
                if ($row !== null && $exception !== null) {
                    $row['caught'] = self::session()?->dump($exception);
                    self::session()?->exceptions()->record($exception);
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe caught');
        }
    }

    /** `match` arm that was selected: its value. Returns it. */
    public static function arm(int $id, mixed $value): mixed
    {
        if (! Hot::$on) {
            return $value;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::BLOCK);
                if ($row !== null && $row['hits'] <= $store->sampleLimit()) {
                    $row['last'] = self::session()?->dump($value);
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe arm');
        }

        return $value;
    }

    /** The subject of a `match` / `switch`. Returns it. */
    public static function val(int $id, ?string $name, mixed $value): mixed
    {
        if (! Hot::$on) {
            return $value;
        }

        try {
            $store = self::store();
            if ($store !== null) {
                $row = &$store->hit($id, ProbeKind::COND);
                if ($row !== null && $row['hits'] <= $store->sampleLimit()) {
                    $row['last'] = self::dump($name, $value);
                    if (is_scalar($value) || $value === null) {
                        $label = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                        $row['values'][$label] = ($row['values'][$label] ?? 0) + 1;
                        if (count($row['values']) > 10) {
                            array_shift($row['values']);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'probe val');
        }

        return $value;
    }

    private static function store(): ?ProbeStore
    {
        return self::session()?->deep();
    }

    private static function session(): ?TraceSession
    {
        $tracer = Tracer::$instance;

        return $tracer !== null && $tracer->on ? $tracer->session() : null;
    }

    /** Dump a value that is known by a name (a variable, a property, a function): secret names are masked. */
    private static function dump(?string $name, mixed $value): ?string
    {
        $session = self::session();
        if ($session === null) {
            return null;
        }
        if ($name !== null && $session->dumper->masker()->isSensitiveKey($name)) {
            $value = new MaskedValue;
        }

        return $session->dump($value);
    }
}
