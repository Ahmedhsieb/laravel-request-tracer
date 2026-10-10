<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RequestTracer\Attributes\NoTrace;

/**
 * Decides where probes go. Everything is an insertion at an original offset (see Edits); nothing is re-printed.
 *
 *  - conditions of if / elseif / while / do / for / ternary and every operand of && / || / and / or: `Probe::cond()`, or
 *    `Probe::cmp()` for comparisons (it records both operands and compares exactly like the operator);
 *  - first statement of every if / elseif / else / catch / finally / case body: `Probe::block()` (`Probe::caught()` for catch);
 *  - loop bodies: `Probe::loop()` / `Probe::iter()` (foreach: key and value of the first iterations);
 *  - every `=` and compound assignment: the right-hand side is wrapped in `Probe::asg()`; function entry records the arguments;
 *  - `return <expr>` and `throw <expr>`: wrapped in `Probe::ret()` / `Probe::thr()`;
 *  - `match`: subject and the value of the arm that ran; `switch`: the subject;
 *  - every other plain statement gets `Probe::block()` so "this line ran" is known.
 *
 * Probes sit inside function bodies only; property defaults, constants, static variables, parameter defaults and attributes
 * (where a function call would be a compile error) are never touched. Methods and classes marked #[NoTrace] are skipped.
 */
final class CompilerVisitor extends NodeVisitorAbstract
{
    private const P = '\\RequestTracer\\DeepTrace\\Probe::';

    private const HOT = '\\RequestTracer\\Hot::$on';

    /** @var array<class-string, array{0: int, 1: list<string>}> */
    private const COMPARISONS = [
        BinaryOp\Equal::class => [0, ['==']],
        BinaryOp\NotEqual::class => [1, ['!=', '<>']],
        BinaryOp\Identical::class => [2, ['===']],
        BinaryOp\NotIdentical::class => [3, ['!==']],
        BinaryOp\Smaller::class => [4, ['<']],
        BinaryOp\SmallerOrEqual::class => [5, ['<=']],
        BinaryOp\Greater::class => [6, ['>']],
        BinaryOp\GreaterOrEqual::class => [7, ['>=']],
    ];

    /** @var list<array{k: string, l: int, s: string, n: string|null, f: array{0: string, 1: int, 2: int}|null}> */
    private array $probes = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, true> nodes already wrapped as part of a condition */
    private array $handled = [];

    /** @var list<array{name: string|null, start: int, end: int, byRef: bool, arrow: bool}> */
    private array $functions = [];

    /** @var array<int, true> function-like nodes that pushed onto */
    private array $pushed = [];

    /** @var list<string|null> */
    private array $classes = [];

    /** @var list<\PhpToken> significant tokens (no whitespace / comments) */
    private array $tokens = [];

    /** @var list<int> byte offsets of */
    private array $tokenOffsets = [];

    private int $fileKey;

    public function __construct(
        private readonly string $source,
        private readonly string $relativePath,
        private readonly string $originalPath,
        private readonly Edits $edits,
    ) {
        $this->fileKey = (int) hexdec(substr(sha1($relativePath), 0, 7));
        foreach (\PhpToken::tokenize($source) as $token) {
            if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $this->tokens[] = $token;
            $this->tokenOffsets[] = $token->pos;
            // __FILE__ / __DIR__ must keep meaning the original location once the code runs from the compiled copy
            if ($token->id === T_FILE) {
                $edits->replace($token->pos, $token->pos + strlen($token->text), var_export($originalPath, true));
            } elseif ($token->id === T_DIR) {
                $edits->replace($token->pos, $token->pos + strlen($token->text), var_export(dirname($originalPath), true));
            }
        }
    }

    /** @return list<array{k: string, l: int, s: string, n: string|null, f: array{0: string, 1: int, 2: int}|null}> */
    public function probes(): array
    {
        return $this->probes;
    }

    public function fileKey(): int
    {
        return $this->fileKey;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return int|null */
    public function enterNode(Node $node)
    {
        // Places where a function call is not allowed: leave them alone.
        if ($node instanceof Stmt\Property || $node instanceof Stmt\ClassConst || $node instanceof Stmt\Const_
            || $node instanceof Stmt\Static_ || $node instanceof Node\Param || $node instanceof Node\AttributeGroup
            || $node instanceof Stmt\EnumCase || (class_exists(PropertyHook::class) && $node instanceof PropertyHook)) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Stmt\ClassLike) {
            $this->classes[] = $node->name?->toString();

            if (AttributeFinder::has($node, NoTrace::class)) {
                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }

            return null;
        }

        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            if (AttributeFinder::has($node, NoTrace::class)) {
                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }
            $this->enterFunction($node);

            return null;
        }

        if ($this->functions === []) {
            return null;                                           // top-level code is never instrumented
        }

        switch (true) {
            case $node instanceof Stmt\If_:
                $this->condition($node->cond);
                $this->body($node->stmts, 'block', $node);
                foreach ($node->elseifs as $elseif) {
                    $this->condition($elseif->cond);
                    $this->body($elseif->stmts, 'block', $elseif);
                }
                if ($node->else !== null) {
                    $this->body($node->else->stmts, 'block', $node->else);
                }
                break;

            case $node instanceof Stmt\While_:
                $this->condition($node->cond);
                $this->body($node->stmts, 'loop', $node);
                break;

            case $node instanceof Stmt\Do_:
                $this->body($node->stmts, 'loop', $node);
                $this->condition($node->cond);
                break;

            case $node instanceof Stmt\For_:
                if ($node->cond !== []) {
                    $this->condition($node->cond[count($node->cond) - 1]);
                }
                $this->body($node->stmts, 'loop', $node);
                break;

            case $node instanceof Stmt\Foreach_:
                $this->body($node->stmts, 'iter', $node, $this->loopVariables($node));
                break;

            case $node instanceof Stmt\Switch_:
                $this->subject($node->cond);
                foreach ($node->cases as $case) {
                    $this->body($case->stmts, 'block', null);
                }
                break;

            case $node instanceof Stmt\TryCatch:
                foreach ($node->catches as $catch) {
                    $this->catchBlock($catch);
                }
                if ($node->finally !== null) {
                    $this->finallyBlock($node->finally);
                }
                break;

            case $node instanceof Expr\Match_:
                $this->subject($node->cond);
                foreach ($node->arms as $arm) {
                    $this->wrapExpression($arm->body, 'arm', ProbeKind::BLOCK, 'match arm', null);
                }
                break;

            case $node instanceof Expr\Ternary:
                $this->condition($node->cond);
                break;

            case $node instanceof BinaryOp\BooleanAnd || $node instanceof BinaryOp\BooleanOr || $node instanceof BinaryOp\LogicalAnd || $node instanceof BinaryOp\LogicalOr:
                if (! isset($this->handled[spl_object_id($node)])) {
                    $this->condition($node);                        // `return $a && $b;`, `$ok = $x || $y;`
                }
                break;

            case $node instanceof Expr\Assign || $node instanceof Expr\AssignOp:
                $name = $this->nameOf($node->var);
                $this->wrapExpression($node->expr, 'asg', ProbeKind::ASSIGN, $this->snippet($node), $name);
                break;

            case $node instanceof Stmt\Return_:
                if ($node->expr !== null) {
                    $function = $this->functions[count($this->functions) - 1];
                    if (! $function['byRef']) {
                        $this->wrapReturn($node->expr, $function['name']);
                    }
                }
                break;

            case $node instanceof Expr\Throw_:
                $this->wrapExpression($node->expr, 'thr', ProbeKind::THROW, $this->snippet($node), null);
                break;

            case class_exists(Stmt\Throw_::class) && $node instanceof Stmt\Throw_:   // php-parser 4 only
                $this->wrapExpression($node->expr, 'thr', ProbeKind::THROW, $this->snippet($node), null);
                break;
        }

        return null;
    }

    /** @return null */
    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }
        if (isset($this->pushed[spl_object_id($node)])) {
            array_pop($this->functions);
            unset($this->pushed[spl_object_id($node)]);
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------------
    // functions
    // ------------------------------------------------------------------------------------------------

    private function enterFunction(Node $node): void
    {
        $name = null;
        if ($node instanceof Stmt\ClassMethod) {
            $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
            $name = ($class !== null ? $class.'::' : '').$node->name->toString();
        } elseif ($node instanceof Stmt\Function_) {
            $name = $node->name->toString();
        } else {
            $parent = $this->functions === [] ? null : $this->functions[count($this->functions) - 1]['name'];
            $name = ($parent ?? 'closure').'{closure}';
        }

        $this->functions[] = [
            'name' => $name,
            'start' => $node->getStartLine(),
            'end' => $node->getEndLine(),
            'byRef' => (bool) ($node->byRef ?? false),
            'arrow' => $node instanceof Expr\ArrowFunction,
        ];
        $this->pushed[spl_object_id($node)] = true;

        if ($node instanceof Expr\ArrowFunction) {
            return;
        }
        $stmts = $this->realStatements($node->stmts ?? []);
        if ($stmts !== [] && $this->isInstrumentable($stmts[0])) {
            $id = $this->probe('assign', $stmts[0]->getStartLine(), 'arguments', null);
            $this->edits->statement($this->start($stmts[0]), self::HOT.' && '.self::P."enter({$id}, \\get_defined_vars()); ", -2);
            $this->statementList($stmts, null, null);
        }
    }

    // ------------------------------------------------------------------------------------------------
    // statement lists
    // ------------------------------------------------------------------------------------------------

    /**
     * @param  array<int, Stmt>  $stmts
     * @param  list<string>|null  $iterVars
     */
    private function body(array $stmts, string $kind, ?Node $owner, ?array $iterVars = null): void
    {
        $stmts = $this->realStatements($stmts);
        if ($stmts === []) {
            return;
        }
        $first = $stmts[0];
        if (! $this->isInstrumentable($first)) {
            return;
        }
        $firstStart = $this->start($first);

        if ($owner !== null) {
            $prev = $this->previousToken($firstStart);
            if ($prev !== null && $prev->text !== '{' && $prev->text !== ':') {
                $this->edits->braces($firstStart, $this->end($stmts[count($stmts) - 1]));       // brace-less body
            }
        }

        $line = $first->getStartLine();
        $snippet = $this->snippet($first);
        if ($kind === 'iter' && $iterVars !== null) {
            $id = $this->probe('loop', $line, $snippet, implode(',', $iterVars));
            $pairs = [];
            foreach ($iterVars as $var) {
                $pairs[] = var_export($var, true).' => $'.$var;
            }
            $text = self::HOT.' && '.self::P."iter({$id}, [".implode(', ', $pairs).']); ';
        } elseif ($kind === 'loop') {
            $id = $this->probe('loop', $line, $snippet, null);
            $text = self::HOT.' && '.self::P."loop({$id}); ";
        } else {
            $id = $this->probe('block', $line, $snippet, null);
            $text = self::HOT.' && '.self::P."block({$id}); ";
        }
        $this->edits->statement($firstStart, $text, -1);

        $this->statementList($stmts, $kind, $first);
    }

    /**
     * "This line ran" probes for the plain statements of a list (not for the first one when it already carries a body probe).
     *
     * @param  array<int, Stmt>  $stmts
     */
    private function statementList(array $stmts, ?string $bodyKind, ?Stmt $skip): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt === $skip || ! $this->isPlainStatement($stmt)) {
                continue;
            }
            $id = $this->probe('block', $stmt->getStartLine(), $this->snippet($stmt), null);
            $this->edits->statement($this->start($stmt), self::HOT.' && '.self::P."block({$id}); ", 0);
        }
    }

    /** Inline HTML starts in "text mode": PHP inserted there would be printed. */
    private function isInstrumentable(Stmt $stmt): bool
    {
        return ! $stmt instanceof Stmt\InlineHTML && ! $stmt instanceof Stmt\HaltCompiler;
    }

    private function isPlainStatement(Stmt $stmt): bool
    {
        if ($stmt instanceof Stmt\Expression) {
            $e = $stmt->expr;

            return ! ($e instanceof Expr\Assign || $e instanceof Expr\AssignOp || $e instanceof Expr\Throw_);
        }
        if ($stmt instanceof Stmt\Return_) {
            return $stmt->expr === null;
        }

        return $stmt instanceof Stmt\Echo_ || $stmt instanceof Stmt\Unset_ || $stmt instanceof Stmt\Break_ || $stmt instanceof Stmt\Continue_;
    }

    private function catchBlock(Stmt\Catch_ $catch): void
    {
        $stmts = $this->realStatements($catch->stmts);
        if ($stmts !== [] && ! $this->isInstrumentable($stmts[0])) {
            return;
        }
        $var = $catch->var instanceof Expr\Variable && is_string($catch->var->name) ? '$'.$catch->var->name : 'null';
        $line = $catch->getStartLine();
        $id = $this->probe('block', $line, 'catch ('.implode('|', array_map(static fn ($t) => $t->toString(), $catch->types)).')', null);
        $text = self::HOT.' && '.self::P."caught({$id}, {$var}); ";

        if ($stmts !== []) {
            $this->edits->statement($this->start($stmts[0]), $text, -1);
            $this->statementList($stmts, 'block', null);

            return;
        }
        $brace = $this->nextToken('{', $this->start($catch) + 1);
        if ($brace !== null) {
            $this->edits->insertAfterBrace($brace->pos + 1, $text);
        }
    }

    private function finallyBlock(Stmt\Finally_ $finally): void
    {
        $stmts = $this->realStatements($finally->stmts);
        if ($stmts !== [] && ! $this->isInstrumentable($stmts[0])) {
            return;
        }
        $id = $this->probe('block', $finally->getStartLine(), 'finally', null);
        $text = self::HOT.' && '.self::P."block({$id}); ";
        if ($stmts !== []) {
            $this->edits->statement($this->start($stmts[0]), $text, -1);
            $this->statementList($stmts, 'block', null);

            return;
        }
        $brace = $this->nextToken('{', $this->start($finally));
        if ($brace !== null) {
            $this->edits->insertAfterBrace($brace->pos + 1, $text);
        }
    }

    /**
     * @param  array<int, Stmt>  $stmts
     * @return list<Stmt>
     */
    private function realStatements(array $stmts): array
    {
        return array_values(array_filter($stmts, static fn (Stmt $s): bool => ! $s instanceof Stmt\Nop));
    }

    /** @return list<string> */
    private function loopVariables(Stmt\Foreach_ $loop): array
    {
        $vars = [];
        foreach ([$loop->keyVar, $loop->valueVar] as $var) {
            if ($var instanceof Expr\Variable && is_string($var->name)) {
                $vars[] = $var->name;
            }
        }

        return $vars;
    }

    // ------------------------------------------------------------------------------------------------
    // conditions
    // ------------------------------------------------------------------------------------------------

    /** Wrap a condition expression (recursing into `&&`, `||`, `!` so that every part is recorded). */
    private function condition(Expr $e): void
    {
        $this->handled[spl_object_id($e)] = true;

        if ($e instanceof BinaryOp\BooleanAnd || $e instanceof BinaryOp\BooleanOr || $e instanceof BinaryOp\LogicalAnd || $e instanceof BinaryOp\LogicalOr) {
            $this->condition($e->left);
            $this->condition($e->right);
            $this->wrapCond($e);

            return;
        }
        if ($e instanceof Expr\BooleanNot) {
            $this->condition($e->expr);
            $this->wrapCond($e);

            return;
        }
        foreach (self::COMPARISONS as $class => [$opcode, $symbols]) {
            if ($e instanceof $class && $this->wrapComparison($e, $opcode, $symbols)) {
                return;
            }
        }
        $this->wrapCond($e);
    }

    private function wrapCond(Expr $e): void
    {
        $id = $this->probe('cond', $e->getStartLine(), $this->snippet($e), null);
        $this->edits->wrap($this->start($e), $this->end($e), self::P."cond({$id}, ", ')');
    }

    /** @param  list<string>  $symbols */
    private function wrapComparison(BinaryOp $e, int $opcode, array $symbols): bool
    {
        $operator = $this->tokenBetween($this->end($e->left), $this->start($e->right), $symbols);
        if ($operator === null) {
            return false;
        }

        $names = array_values(array_filter([$this->nameOf($e->left), $this->nameOf($e->right)], static fn (?string $n): bool => $n !== null));
        $id = $this->probe('cond', $e->getStartLine(), $this->snippet($e), $names === [] ? null : implode(',', $names));
        $a = $this->quoteName($this->nameOf($e->left));
        $b = $this->quoteName($this->nameOf($e->right));
        $this->edits->wrap($this->start($e), $this->end($e), self::P."cmp({$id}, ", ", {$opcode}, {$a}, {$b})");
        $this->edits->replace($operator->pos, $operator->pos + strlen($operator->text), ',');

        return true;
    }

    private function subject(Expr $subject): void
    {
        $id = $this->probe('cond', $subject->getStartLine(), $this->snippet($subject), null);
        $name = $this->quoteName($this->nameOf($subject));
        $this->edits->wrap($this->start($subject), $this->end($subject), self::P."val({$id}, {$name}, ", ')');
    }

    // ------------------------------------------------------------------------------------------------
    // wrapping expressions
    // ------------------------------------------------------------------------------------------------

    /** Wrap $e in `Probe::<method>(id, [name,] ...)`. `asg` carries the assigned name, the others only the probe id. */
    private function wrapExpression(Expr $e, string $method, string $kind, string $snippet, ?string $name): void
    {
        $id = $this->probe($kind, $e->getStartLine(), $snippet, $name);
        $head = $method === 'asg' ? "{$id}, ".$this->quoteName($name).', ' : "{$id}, ";
        $this->edits->wrap($this->start($e), $this->end($e), self::P."{$method}({$head}", ')');
    }

    private function wrapReturn(Expr $e, ?string $function): void
    {
        $id = $this->probe('return', $e->getStartLine(), $this->snippet($e), $function);
        $short = $function !== null && str_contains($function, '::') ? substr($function, (int) strrpos($function, '::') + 2) : $function;
        $this->edits->wrap($this->start($e), $this->end($e), self::P."ret({$id}, ".$this->quoteName($short).', ', ')');
    }

    // ------------------------------------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------------------------------------

    private function probe(string $kind, int $line, string $snippet, ?string $name): int
    {
        if (count($this->probes) >= 65535) {
            throw new CompileException('the file needs more than 65535 probes');
        }
        $function = $this->functions === [] ? null : $this->functions[count($this->functions) - 1];
        $this->probes[] = [
            'k' => $kind,
            'l' => $line,
            's' => $snippet,
            'n' => $name,
            'f' => $function === null || $function['name'] === null ? null : [$function['name'], $function['start'], $function['end']],
        ];

        return ($this->fileKey << 16) | (count($this->probes) - 1);
    }

    private function start(Node $node): int
    {
        return $node->getStartFilePos();
    }

    private function end(Node $node): int
    {
        return $node->getEndFilePos() + 1;
    }

    private function snippet(Node $node): string
    {
        $text = substr($this->source, $this->start($node), $this->end($node) - $this->start($node));
        $multiline = str_contains($text, '
');
        if ($multiline) {
            $text = substr($text, 0, (int) strpos($text, '
'));
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $text)).($multiline ? ' …' : '');

        return strlen($text) > 120 ? substr($text, 0, 117).'…' : $text;
    }

    /** A short identifier for the thing an expression reads or assigns: `$password` -> password, `$user->token` -> token. */
    private function nameOf(Node $e): ?string
    {
        $name = null;
        if ($e instanceof Expr\Variable && is_string($e->name)) {
            $name = $e->name;
        } elseif (($e instanceof Expr\PropertyFetch || $e instanceof Expr\NullsafePropertyFetch || $e instanceof Expr\StaticPropertyFetch) && $e->name instanceof Node\Identifier) {
            $name = $e->name->toString();
        } elseif ($e instanceof Expr\StaticPropertyFetch && $e->name instanceof Node\VarLikeIdentifier) {
            $name = $e->name->toString();
        } elseif ($e instanceof Expr\ArrayDimFetch && $e->dim instanceof Node\Scalar\String_) {
            $name = $e->dim->value;
        } elseif ($e instanceof Expr\ArrayDimFetch && $e->var instanceof Expr\Variable && is_string($e->var->name)) {
            $name = $e->var->name;
        } elseif (($e instanceof Expr\MethodCall || $e instanceof Expr\NullsafeMethodCall) && $e->name instanceof Node\Identifier) {
            $name = $e->name->toString();
        }

        return $name !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : null;
    }

    private function quoteName(?string $name): string
    {
        return $name === null ? 'null' : "'".$name."'";
    }

    private function previousToken(int $offset): ?\PhpToken
    {
        $i = $this->bisect($offset) - 1;

        return $this->tokens[$i] ?? null;
    }

    /** First token at or after $offset whose text is $text. */
    private function nextToken(string $text, int $offset): ?\PhpToken
    {
        for ($i = $this->bisect($offset), $n = count($this->tokens); $i < $n; $i++) {
            if ($this->tokens[$i]->text === $text) {
                return $this->tokens[$i];
            }
        }

        return null;
    }

    /**
     * The comparison operator between two operands: the first token in [from, to) whose text is one of $symbols.
     *
     * @param  list<string>  $symbols
     */
    private function tokenBetween(int $from, int $to, array $symbols): ?\PhpToken
    {
        for ($i = $this->bisect($from), $n = count($this->tokens); $i < $n && $this->tokens[$i]->pos < $to; $i++) {
            if (in_array($this->tokens[$i]->text, $symbols, true)) {
                return $this->tokens[$i];
            }
        }

        return null;
    }

    /** Index of the first token whose offset is >= $offset. */
    private function bisect(int $offset): int
    {
        $lo = 0;
        $hi = count($this->tokenOffsets);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->tokenOffsets[$mid] < $offset) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }
}
