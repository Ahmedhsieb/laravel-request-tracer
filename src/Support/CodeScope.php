<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Support;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Routing\Controller;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Component;

/**
 * Which of the project's own code is looked into: the folders and files in `code.paths` (default: the whole `app/` folder),
 * minus whatever `code.except` names. Everything inside is traced, nothing has to be listed class by class.
 *
 * An entry is a folder or a file relative to the project (`app/Services`, `modules/Billing/src`, `app/Support/Money.php`) or absolute.
 * In `except`, an entry that looks like a class or namespace (`App\Legacy\*`, `App\Services\Cache`) is matched against class names.
 */
final class CodeScope
{
    /** Code that is never the project's own, whatever the paths say. */
    private const NEVER = ['/vendor/', '/node_modules/', '/storage/framework/', '/bootstrap/cache/'];

    /**
     * Parents a class can have that make a proxy a bad idea: the framework builds or calls these in ways a subclass would disturb
     * (kernels, exception handlers, providers, commands, models, exceptions) or they already have a step of their own (controllers).
     * Line-by-line tracing still applies to their files; only the container proxy is skipped.
     */
    private const NOT_PROXIED = [
        Kernel::class,
        \Illuminate\Foundation\Console\Kernel::class,
        ExceptionHandler::class,
        ServiceProvider::class,
        Command::class,
        Model::class,
        Controller::class,
        FormRequest::class,
        JsonResource::class,
        Mailable::class,
        Notification::class,
        Factory::class,
        Seeder::class,
        Migration::class,
        ShouldQueue::class,
        View::class,
        Component::class,
        \Throwable::class,
    ];

    /** @var array{0: list<string>, 1: list<string>, 2: list<string>}|null paths, except paths, except class patterns */
    private static ?array $parsed = null;

    private static string $signature = '';

    /** True when the file is inside `code.paths` and not under `code.except` (and not vendor code). */
    public static function includesFile(string $file): bool
    {
        $file = self::normalize($file);
        // Only what is below the project counts: a project that happens to live under a folder called "vendor" is still a project.
        $below = $file;
        foreach ([self::normalize((string) realpath(Paths::base())), Paths::base()] as $base) {
            if ($base !== '' && self::under($file, $base)) {
                $below = substr($file, strlen($base));

                break;
            }
        }
        foreach (self::NEVER as $never) {
            if (str_contains($below.'/', $never)) {
                return false;
            }
        }
        [$paths, $exceptPaths] = self::parsed();

        $inside = false;
        foreach ($paths as $path) {
            if (self::under($file, $path)) {
                $inside = true;

                break;
            }
        }
        if (! $inside) {
            return false;
        }
        foreach ($exceptPaths as $path) {
            if (self::under($file, $path)) {
                return false;
            }
        }

        return true;
    }

    /** True when a class name is not named in `code.except`. */
    public static function classAllowed(string $class): bool
    {
        $patterns = self::parsed()[2];

        return $patterns === [] || ! ClassPatterns::matches($class, $patterns);
    }

    /**
     * Whether a class from the project may get a container proxy (see NOT_PROXIED).
     *
     * @param  \ReflectionClass<object>  $class
     */
    public static function proxyable(\ReflectionClass $class): bool
    {
        if ($class->isFinal() || $class->isAbstract() || $class->isInterface() || $class->isEnum() || $class->isAnonymous()) {
            return false;
        }
        foreach (self::NOT_PROXIED as $parent) {
            if (is_a($class->getName(), $parent, true)) {   // classes and interfaces; a parent that is not installed simply never matches
                return false;
            }
        }

        return true;
    }

    /** Forget the parsed configuration (tests, and after the config changed). */
    public static function flush(): void
    {
        self::$parsed = null;
        self::$signature = '';
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<string>} */
    private static function parsed(): array
    {
        $config = App::config();
        $paths = (array) $config->get('request-tracer.code.paths', ['app']);
        $except = (array) $config->get('request-tracer.code.except', []);
        $signature = md5(serialize([$paths, $except]));
        if (self::$parsed !== null && self::$signature === $signature) {
            return self::$parsed;
        }

        $included = [];
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $included[] = self::resolve($path);
            }
        }
        $exceptPaths = [];
        $exceptClasses = [];
        foreach ($except as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (str_contains($entry, '\\') && ! str_contains($entry, '/')) {
                $exceptClasses[] = $entry;
            } else {
                $exceptPaths[] = self::resolve($entry);
            }
        }
        self::$signature = $signature;

        return self::$parsed = [$included, $exceptPaths, $exceptClasses];
    }

    private static function resolve(string $path): string
    {
        return self::resolved(Paths::absolute(str_replace('\\', '/', $path)));
    }

    /**
     * The real location of a path, also when it does not exist (yet): the nearest folder that does exist is resolved
     * (symlinked releases, `..`), and the rest is appended. Files come from Composer with their real paths, so this is what they match.
     */
    private static function resolved(string $path): string
    {
        $path = self::normalize($path);
        $rest = [];
        $head = $path;
        $real = realpath($head);
        while ($real === false) {
            $parent = dirname($head);
            if ($parent === $head || $parent === '.') {
                return $path;
            }
            array_unshift($rest, basename($head));
            $head = $parent;
            $real = realpath($head);
        }

        return self::normalize($real.($rest === [] ? '' : '/'.implode('/', $rest)));
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function under(string $file, string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {                 // Windows paths are not case sensitive
            $file = strtolower($file);
            $path = strtolower($path);
        }

        return $file === $path || str_starts_with($file, $path.'/');
    }
}
