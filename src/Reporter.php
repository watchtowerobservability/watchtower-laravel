<?php

declare(strict_types=1);

namespace Watchtower\Laravel;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;
use Watchtower\Config;
use WeakMap;

/** Records an exception on the active span (or a standalone one) and returns the trace id. */
final class Reporter
{
    /** @var WeakMap<Throwable, string> */
    private WeakMap $seen;

    public function __construct(private readonly TracerInterface $tracer)
    {
        $this->seen = new WeakMap();
    }

    /** @param array<string, mixed> $attrs wt.* keys are dropped */
    public function capture(Throwable $e, array $attrs = []): string
    {
        if (isset($this->seen[$e])) {
            return $this->seen[$e];
        }
        $span = Span::getCurrent();
        $own = !$span->getContext()->isValid();
        if ($own) {
            $span = $this->tracer->spanBuilder('exception')->startSpan();
        }
        $span->recordException($e, Config::redact($attrs));
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        $traceId = $span->getContext()->getTraceId();
        if ($own) {
            $span->end();
        }
        return $this->seen[$e] = $traceId;
    }
}
