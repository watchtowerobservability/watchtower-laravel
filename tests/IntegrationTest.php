<?php

declare(strict_types=1);

namespace Watchtower\Laravel\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Watchtower\Laravel\WatchtowerServiceProvider;

final class FailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('job boom');
    }
}

final class IntegrationTest extends TestCase
{
    private InMemoryExporter $exporter;

    protected function getPackageProviders($app): array
    {
        return [WatchtowerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->exporter = new InMemoryExporter();
        $app->singleton(TracerProviderInterface::class, fn () => new TracerProvider(new SimpleSpanProcessor($this->exporter)));
        $app['config']->set('logging.channels.wt', ['driver' => 'watchtower']);
        $app['config']->set('queue.default', 'sync');
    }

    /** @return list<string> span names */
    private function names(): array
    {
        return array_map(static fn ($s) => $s->getName(), $this->exporter->getSpans());
    }

    private function exceptionEvents(): int
    {
        $n = 0;
        foreach ($this->exporter->getSpans() as $s) {
            foreach ($s->getEvents() as $e) {
                $n += $e->getName() === 'exception' ? 1 : 0;
            }
        }
        return $n;
    }

    public function testConfigIsMerged(): void
    {
        $this->assertTrue(config('watchtower.enabled'));
        $this->assertTrue(config('watchtower.query_spans'));
    }

    public function testQueryProducesSpanWithoutBindings(): void
    {
        DB::select('select ? as secret', ['hunter2']);
        $span = $this->exporter->getSpans()[0];
        $this->assertSame('SELECT', $span->getName());
        $this->assertSame('select ? as secret', $span->getAttributes()->get('db.query.text'));
        $this->assertStringNotContainsString('hunter2', json_encode($span->getAttributes()->toArray()));
    }

    public function testExceptionHandlerReports(): void
    {
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('boom'));
        $this->assertSame(['exception'], $this->names());
        $this->assertSame(1, $this->exceptionEvents());
    }

    public function testLogChannelCapturesExceptionOnce(): void
    {
        $e = new RuntimeException('logged');
        Log::channel('wt')->error('failed', ['exception' => $e]);
        Log::channel('wt')->error('failed again', ['exception' => $e]);
        $this->assertSame(1, $this->exceptionEvents());
    }

    public function testFailingJobGetsSpanWithException(): void
    {
        try {
            Queue::push(new FailingJob());
        } catch (RuntimeException) {
            // sync queue rethrows
        }
        $this->assertContains('queue.process ' . FailingJob::class, $this->names());
        $this->assertSame(1, $this->exceptionEvents());
    }
}
