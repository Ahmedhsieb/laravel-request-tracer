<?php

declare(strict_types=1);

namespace RequestTracer\Recording;

use RequestTracer\RecordingState;

final class Decision
{
    /**
     * @param  string  $trigger  always | token | watch | sample | job | command | schedule
     * @param  array<string, mixed>  $hints  what a tentative recording needs to be confirmed later (`token_uid`, `watch`, `sample`)
     */
    public function __construct(
        public readonly RecordingState $state,
        public readonly string $trigger = 'always',
        public readonly array $hints = [],
    ) {}

    public static function off(): self
    {
        return new self(RecordingState::Off, 'off');
    }

    public function on(): bool
    {
        return $this->state !== RecordingState::Off;
    }
}
