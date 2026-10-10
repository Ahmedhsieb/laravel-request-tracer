<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use PhpParser\Lexer\Emulative;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * nikic/php-parser 4.18+ support. In version 4 the lexer must be asked to record file positions and the parser is created with
 * `create()`; version 5 removed both (positions are always recorded, `createForNewestSupportedVersion()` replaces `create()`).
 *
 * This file only runs on php-parser 4 and cannot be analysed against version 5 (excluded in phpstan.neon); the test suite runs
 * on both: `composer update --prefer-lowest` installs 4.18, the default install is 5.x.
 */
final class LegacyParser
{
    public static function create(): Parser
    {
        $lexer = new Emulative(['usedAttributes' => ['comments', 'startLine', 'endLine', 'startTokenPos', 'endTokenPos', 'startFilePos', 'endFilePos']]);

        return (new ParserFactory)->create(ParserFactory::PREFER_PHP7, $lexer);
    }
}
