<?php

declare(strict_types=1);

namespace Watchtower\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;

/** A client span per executed query (Eloquent and the query builder both fire QueryExecuted). Bindings are never recorded. */
final class QueryTracing
{
    public static function listen(Dispatcher $events, TracerInterface $tracer): void
    {
        $events->listen(QueryExecuted::class, static function (QueryExecuted $q) use ($tracer): void {
            $end = (int) (microtime(true) * 1e9);
            $op = strtoupper((string) strtok(ltrim($q->sql), " \t\n("));
            $tracer->spanBuilder($op !== '' ? $op : 'query')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp($end - (int) ($q->time * 1e6))
                ->setAttribute('db.system.name', $q->connection->getDriverName())
                ->setAttribute('db.namespace', (string) $q->connection->getDatabaseName())
                ->setAttribute('db.query.text', $q->sql)
                ->startSpan()
                ->end($end);
        });
    }
}
