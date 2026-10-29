<?php

declare(strict_types=1);

namespace Watchtower\Laravel\Tests;

use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watchtower\Laravel\Reporter;
use Watchtower\Laravel\TracerFactory;

final class ReporterTest extends TestCase
{
    public function testCaptureReturnsTraceIdAndDedupes(): void
    {
        $exp = new InMemoryExporter();
        $r = new Reporter((new TracerProvider(new SimpleSpanProcessor($exp)))->getTracer('t'));
        $e = new RuntimeException('x');
        $id = $r->capture($e, ['wt.tenant' => 'evil', 'ok' => 'y']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertSame($id, $r->capture($e));
        $spans = $exp->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame($id, $spans[0]->getTraceId());
        $attrs = $spans[0]->getEvents()[0]->getAttributes()->toArray();
        $this->assertSame('y', $attrs['ok']);
        $this->assertArrayNotHasKey('wt.tenant', $attrs);
    }

    public function testFactoryBuildsProviderWithoutNetwork(): void
    {
        $tp = TracerFactory::make(['service' => 's', 'endpoint' => null, 'key' => '', 'environment' => 'prod', 'release' => '1.0']);
        $this->assertNotNull($tp->getTracer('t'));
    }
}
