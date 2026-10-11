<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The Deep trace autoloader sits in front of Composer's, so it is asked about every class, including the ones it needs
 * itself. In the test suite those are always loaded already; in a real application they are not, and PHP refuses to re-enter
 * the autoloader for a class that is being autoloaded. This runs the real order in a PHP process of its own.
 */
it('compiles a class when the autoloader is registered in a fresh process, without a single reported failure', function () {
    $ini = php_ini_loaded_file();
    $process = new Process(array_values(array_filter([PHP_BINARY, $ini ? '-c' : null, $ini ?: null, dirname(__DIR__).'/Fixtures/bin/deep-loader-fresh-process.php'])));
    $process->setTimeout(60)->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true);

    expect($result)->toBeArray()
        ->and($result['failures'])->toBe([])
        ->and($result['file'])->toStartWith($result['cache']);              // the class was served from the compiled copy
});
