<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Dumper;

use Ahmedhsieb\RequestTracer\Support\Failure;
use Ahmedhsieb\RequestTracer\Support\MemoryGuard;
use Symfony\Component\VarDumper\Cloner\VarCloner;

/**
 * Converts any PHP value into the stored JSON structure (see StructureBuilder), with masking and size limits.
 *
 * One instance per trace: it also keeps the trace's memory budget. Once `memoryCeiling` bytes have been captured,
 * values are stored as type and size only.
 */
final class ValueDumper
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    private VarCloner $cloner;

    private int $used = 0;

    private bool $truncated = false;

    private bool $masked = false;

    /** the memory guard of `unlimited` mode stopped the capture of a value */
    private bool $guardTripped = false;

    /** @param  array<string, mixed>  $captureConfig  the `capture` section of the config (header allowlists, body size) */
    public function __construct(
        private readonly Masker $masker,
        private readonly DumpLimits $limits,
        array $captureConfig = [],
    ) {
        $this->cloner = new VarCloner;
        $this->cloner->setMaxItems($limits->unlimited ? -1 : max(500, $limits->maxItems * 20));
        $this->cloner->setMaxString($limits->maxString);
        $this->cloner->addCasters(Casters::make($limits, $captureConfig));
    }

    public function masker(): Masker
    {
        return $this->masker;
    }

    /** JSON for one value; never throws. */
    public function dump(mixed $value): string
    {
        try {
            if ($this->limits->memoryCeiling > 0 && $this->used >= $this->limits->memoryCeiling) {
                $this->truncated = true;

                return $this->describe($value);
            }

            if ($this->limits->unlimited) {
                // no caps, but never more than the memory that is left: cloning a value costs several times its size
                if (MemoryGuard::low()) {
                    $this->guardTripped = $this->truncated = true;

                    return $this->describe($value);
                }
                $this->cloner->setMaxItems(MemoryGuard::itemBudget());
            }

            $data = $this->cloner->cloneVar($value)->withRefHandles(false);
            $json = '';
            for ($depth = $this->limits->maxDepth; $depth >= 0; $depth--) {
                $builder = new StructureBuilder($this->masker, $this->limits->maxItems);
                $data->withMaxDepth($depth)->dump($builder);
                $json = (string) json_encode($builder->result(), self::JSON_FLAGS);
                $this->masked = $this->masked || $builder->masked;
                if ($builder->truncated || $depth < $this->limits->maxDepth) {
                    $this->truncated = $this->truncated || $builder->truncated || $depth < $this->limits->maxDepth;
                }
                if (strlen($json) <= $this->limits->maxSpanBytes) {
                    $this->used += strlen($json);

                    return $json;
                }
            }

            $this->truncated = true;

            return $this->describe($value);
        } catch (\Throwable $e) {
            Failure::report($e, 'value dumper');

            return $this->describe($value);
        }
    }

    /** Decoded structure (tests, API). */
    public function dumpToStructure(mixed $value): mixed
    {
        return json_decode($this->dump($value), true);
    }

    /** Type and size only. */
    public function describe(mixed $value): string
    {
        $node = match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : ['$' => 'float', 'v' => is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')],
            is_string($value) => ['$' => 'type', 't' => 'string', 'size' => strlen($value)],
            is_array($value) => ['$' => 'type', 't' => 'array', 'size' => count($value)],
            is_object($value) => ['$' => 'type', 't' => $value::class],
            default => ['$' => 'type', 't' => get_debug_type($value)],
        };

        return (string) json_encode($node, self::JSON_FLAGS);
    }

    public function used(): int
    {
        return $this->used;
    }

    /** True once any captured value was shortened, depth-limited or replaced by a type marker. */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function masked(): bool
    {
        return $this->masked;
    }

    /** True when `unlimited` mode stopped storing a value because the process was running out of memory. */
    public function guardTripped(): bool
    {
        return $this->guardTripped;
    }
}
