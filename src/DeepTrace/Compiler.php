<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Compiles a PHP file into a copy with Deep trace probes (see CompilerVisitor for what is instrumented).
 *
 * The AST is used only to find *where* things are. Probes are inserted into the original text at those offsets; the rest of the
 * file (whitespace, comments, formatting, heredocs) is byte-for-byte the original, and no inserted text contains a line break, so
 * every line number is preserved by construction. The result is verified (same line count, every token on its line, still parses);
 * a file that fails verification is not used.
 *
 * Works with nikic/php-parser 4.18+ and 5.x.
 */
final class Compiler
{
    /** Bump when the compiled output changes (part of the cache key). */
    public const VERSION = '1';

    private ?Parser $parser = null;

    /**
     * @param  string  $relativePath  path shown in the dashboard (and the seed of the probe ids)
     * @param  string  $originalPath  absolute path of the original: `__FILE__` / `__DIR__` are rewritten to it
     *
     * @throws CompileException
     */
    public function compile(string $source, string $relativePath, string $originalPath): CompileResult
    {
        if (! str_contains($source, '<?php') && ! str_contains($source, '<?=')) {
            throw new CompileException('no PHP code found');
        }

        try {
            $ast = $this->parser()->parse($source);
        } catch (\Throwable $e) {
            throw new CompileException('cannot parse: '.$e->getMessage());
        }
        if ($ast === null) {
            throw new CompileException('cannot parse the file');
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);

        $edits = new Edits;
        $visitor = new CompilerVisitor($source, $relativePath, $originalPath, $edits);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $compiled = $edits->apply($source);
        LineGuard::verify($source, $compiled, $edits->replacedRanges());

        return new CompileResult($compiled, $visitor->probes(), $visitor->fileKey(), $visitor->warnings());
    }

    /**
     * Class-level `#[NoTrace]` / `#[Trace(deep: true)]` of a file, read from its AST without compiling it.
     *
     * @return array{deep: bool, skip: bool}
     */
    public function attributeHints(string $source): array
    {
        try {
            $ast = $this->parser()->parse($source);
        } catch (\Throwable) {
            return ['deep' => false, 'skip' => false];
        }
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast ?? []);

        $hints = ['deep' => false, 'skip' => false];
        $finder = new AttributeFinder($hints);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->hints;
    }

    private function parser(): Parser
    {
        if ($this->parser !== null) {
            return $this->parser;
        }

        // php-parser 5: the default lexer already records file positions
        if (class_exists(PhpVersion::class)) {
            return $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        }

        return $this->parser = LegacyParser::create();
    }
}
