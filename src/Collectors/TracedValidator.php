<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Validation\Validator;
use RequestTracer\SpanFlag;
use RequestTracer\SpanType;
use RequestTracer\Support\CallSite;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/** Validator that records a `validation` span around passes(): rules and data in, pass/fail and error messages out. */
final class TracedValidator extends Validator
{
    public function passes()
    {
        $tracer = Tracer::$instance;
        if ($tracer === null || ! $tracer->on) {
            return parent::passes();
        }

        $span = Failure::guard(function () use ($tracer) {
            $session = $tracer->session();
            if ($session === null) {
                return null;
            }
            $fields = array_keys($this->rules);
            $label = 'validate '.implode(', ', array_slice($fields, 0, 6)).(count($fields) > 6 ? ' … (+'.(count($fields) - 6).')' : '');
            [$file, $line] = CallSite::find();
            $span = $session->recorder->begin(SpanType::Validation, $label, $file, $line);
            if (! $span->ghost) {
                $span->input = $session->dump(['rules' => $this->rules, 'data' => $this->data]);
            }

            return $span;
        }, 'validation span start');

        $passed = false;
        try {
            return $passed = parent::passes();
        } finally {
            if ($span !== null) {
                Failure::guard(function () use ($tracer, $span, $passed) {
                    $session = $tracer->session();
                    if ($session === null) {
                        return;
                    }
                    if (! $span->ghost) {
                        $span->meta['passed'] = $passed;
                        $span->meta['summary'] = $passed ? 'Passed' : 'Failed: '.$this->errors()->count().' '.($this->errors()->count() === 1 ? 'error' : 'errors');
                        $span->output = $session->dump($passed ? ['passed' => true] : ['passed' => false, 'errors' => $this->errors()->messages()]);
                        if (! $passed) {
                            $span->flag = SpanFlag::Failed;
                        }
                    }
                    $session->recorder->end($span);
                }, 'validation span end');
            }
        }
    }
}
