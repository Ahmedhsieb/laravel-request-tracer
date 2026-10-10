<?php

declare(strict_types=1);

namespace RequestTracer\Analysis;

use RequestTracer\Span;
use RequestTracer\SpanFlag;
use RequestTracer\SpanType;
use RequestTracer\Support\Paths;
use RequestTracer\TraceSession;
use Throwable;

/**
 * Records every exception once (by instance), wherever it was seen: the exception handler's report path, a traced span that
 * threw, a Deep trace `catch` block. Whether it was *caught* is decided at the end: an exception the response was built
 * from is uncaught, every other one is caught.
 */
final class ExceptionTracker
{
    /** @var \WeakMap<Throwable, array{span: int, id: int}> */
    private \WeakMap $seen;

    /** @var list<ExceptionRecord> */
    private array $records = [];

    public function __construct(private readonly TraceSession $session)
    {
        $this->seen = new \WeakMap;
    }

    /** Record $e (first sighting wins) and return its span id. */
    public function record(Throwable $e): int
    {
        if (isset($this->seen[$e])) {
            return $this->seen[$e]['span'];
        }

        $file = Paths::relative($e->getFile());
        $span = $this->session->recorder->leaf(SpanType::Exception, $e::class.': '.$e->getMessage(), 0, $file, $e->getLine());
        $id = count($this->records);
        $this->seen[$e] = ['span' => $span->id, 'id' => $id];
        $this->records[] = new ExceptionRecord($e::class, $e->getMessage(), $file, $e->getLine(), $span->id);

        if (! $span->ghost) {
            $span->flag = SpanFlag::Caught;
            $span->output = $this->session->dump($e);
            $span->meta['exception'] = $id;
        }

        return $span->id;
    }

    /** The exception the response was rendered from: it was not caught by application code. */
    public function markUncaught(Throwable $e, bool $ignorable): void
    {
        $this->record($e);
        $info = $this->seen[$e] ?? null;
        if ($info === null) {
            return;
        }
        $this->records[$info['id']]->uncaught = true;
        $this->records[$info['id']]->ignore = $ignorable;
        $span = $this->session->recorder->find($info['span']);
        if ($span instanceof Span && $span->type === SpanType::Exception) {
            $span->flag = $ignorable ? null : SpanFlag::Failed;
        }
    }

    public function seen(Throwable $e): bool
    {
        return isset($this->seen[$e]);
    }

    /** @return list<ExceptionRecord> */
    public function records(): array
    {
        return $this->records;
    }
}
