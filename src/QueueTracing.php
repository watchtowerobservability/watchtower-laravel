<?php

declare(strict_types=1);

namespace Watchtower\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/** One span per processed queue job; flushes after each job because workers never terminate. */
final class QueueTracing
{
    /** @var array<int, array{SpanInterface, ScopeInterface}> */
    private array $open = [];

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly Reporter $reporter,
        private readonly TracerProviderInterface $provider,
    ) {
    }

    public function listen(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $e): void {
            $span = $this->tracer->spanBuilder('queue.process ' . $e->job->resolveName())
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setAttribute('messaging.operation.type', 'process')
                ->setAttribute('messaging.destination.name', (string) $e->job->getQueue())
                ->setAttribute('messaging.message.id', (string) $e->job->getJobId())
                ->startSpan();
            $this->open[spl_object_id($e->job)] = [$span, $span->activate()];
        });
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->finish($e->job, false));
        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $e): void {
            $this->reporter->capture($e->exception);
            $this->finish($e->job, true);
        });
        // timeouts and max-attempts failures arrive here without an exceptionOccurred
        $events->listen(JobFailed::class, fn (JobFailed $e) => $this->reporter->capture($e->exception));
    }

    private function finish(object $job, bool $failed): void
    {
        $entry = $this->open[spl_object_id($job)] ?? null;
        if ($entry === null) {
            return;
        }
        [$span, $scope] = $entry;
        unset($this->open[spl_object_id($job)]);
        $scope->detach();
        if ($failed) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
        $span->end();
        $this->provider->forceFlush();
    }
}
