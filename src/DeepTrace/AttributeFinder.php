<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeVisitorAbstract;
use RequestTracer\Attributes\NoTrace;
use RequestTracer\Attributes\Trace;

/**
 * Reads class-level `#[Trace(deep: true)]` / `#[NoTrace]` from an AST (names must already be resolved), and offers the
 * same lookups for single nodes to the compiler.
 */
final class AttributeFinder extends NodeVisitorAbstract
{
    /** @param  array{deep: bool, skip: bool}  $hints */
    public function __construct(public array $hints) {}

    /** @return null */
    public function enterNode(Node $node)
    {
        if ($node instanceof ClassLike) {
            if (self::has($node, NoTrace::class)) {
                $this->hints['skip'] = true;
            }
            foreach (self::find($node, Trace::class) as $attribute) {
                if (self::deepFlag($attribute)) {
                    $this->hints['deep'] = true;
                }
            }
        }

        return null;
    }

    /** @param  class-string  $class */
    public static function has(Node $node, string $class): bool
    {
        return self::find($node, $class) !== [];
    }

    /**
     * @param  class-string  $class
     * @return list<Node\Attribute>
     */
    public static function find(Node $node, string $class): array
    {
        $found = [];
        foreach ($node->attrGroups ?? [] as $group) {
            foreach ($group->attrs as $attribute) {
                if (strcasecmp(ltrim($attribute->name->toString(), '\\'), $class) === 0) {
                    $found[] = $attribute;
                }
            }
        }

        return $found;
    }

    private static function deepFlag(Node\Attribute $attribute): bool
    {
        foreach ($attribute->args as $i => $arg) {
            $named = $arg->name?->toString() === 'deep';
            $positional = $arg->name === null && $i === 1;
            if (($named || $positional) && $arg->value instanceof Node\Expr\ConstFetch && strtolower($arg->value->name->toString()) === 'true') {
                return true;
            }
        }

        return false;
    }
}
