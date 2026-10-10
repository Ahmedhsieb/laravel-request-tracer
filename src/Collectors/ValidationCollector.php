<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Validation\Factory;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/**
 * Validation as a span. Installs a validator resolver on the validation factory so every validator is a TracedValidator.
 * An application that already set its own resolver keeps it (we never replace it) and loses only this span.
 */
final class ValidationCollector implements Collector
{
    public static function key(): string
    {
        return 'validation';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        $install = static function ($factory): void {
            if (! $factory instanceof Factory) {
                return;
            }
            try {
                $property = new \ReflectionProperty(Factory::class, 'resolver');
                if ($property->getValue($factory) !== null) {
                    Failure::debug('validation collector: the application already has a validator resolver; validation spans are disabled');

                    return;
                }
            } catch (\Throwable) {
                // unknown internals: install anyway
            }
            $factory->resolver(static fn ($translator, array $data, array $rules, array $messages, array $attributes) => new TracedValidator($translator, $data, $rules, $messages, $attributes));
        };

        $app->afterResolving('validator', $install);
        if ($app->resolved('validator')) {
            $install($app->make('validator'));
        }
    }
}
