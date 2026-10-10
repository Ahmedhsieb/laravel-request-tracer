<?php

declare(strict_types=1);

namespace RequestTracer\Recording;

use RequestTracer\RecordingState;

final class Decision
{
    public function __construct(
        public readonly RecordingState $state,
        /** always | token | watch | sample | job | command */
        public readonly string $trigger = 'always',
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
