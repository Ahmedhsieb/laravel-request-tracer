<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The Deep trace autoloader sits in front of Composer's, so it is asked about every class, including the ones it needs
 * itself. In the test suite those are always loaded already; in a real application they are not, and PHP refuses to re-enter
 * the autoloader for a class that is being autoloaded. This runs the real order in a PHP process of its own.
 *
 * @return array{pricing: string, coupon: string, user: string, cache: string, failures: list<string>}
 */
function freshProcess(string $mode): array
{
    $ini = php_ini_loaded_file();
    $process = new Process(array_values(array_filter([PHP_BINARY, $ini ? '-c' : null, $ini ?: null, dirname(__DIR__).'/Fixtures/bin/deep-loader-fresh-process.php', $mode])));
    $process->setTimeout(60)->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true);
    expect($result)->toBeArray();

    return $result;
}

it('compiles a class named in the config when the autoloader is registered in a fresh process, without a single reported failure', function () {
    $result = freshProcess('namespace');

    expect($result['failures'])->toBe([])
        ->and($result['pricing'])->toStartWith($result['cache'])              // served from the compiled copy
        ->and($result['coupon'])->not->toStartWith($result['cache']);
});

it('compiles everything inside the folders of code.paths, and nothing outside them or named in code.except', function () {
    $result = freshProcess('scope');

    expect($result['failures'])->toBe([])
        ->and($result['pricing'])->toStartWith($result['cache'])
        ->and($result['coupon'])->not->toStartWith($result['cache'])           // left out by name
        ->and($result['user'])->not->toStartWith($result['cache']);            // another folder
});
