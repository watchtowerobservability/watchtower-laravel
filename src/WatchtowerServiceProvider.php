<?php

declare(strict_types=1);

namespace Watchtower\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

final class WatchtowerServiceProvider extends ServiceProvider
{
    private const TRACER = 'watchtower-laravel';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/watchtower.php', 'watchtower');
        // bindIf lets an app (or a test) supply its own provider first
        $this->app->bindIf(TracerProviderInterface::class, fn ($app) => TracerFactory::make($app['config']->get('watchtower')), true);
        $this->app->singleton(Reporter::class, fn ($app) => new Reporter($app->make(TracerProviderInterface::class)->getTracer(self::TRACER)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/watchtower.php' => config_path('watchtower.php')], 'watchtower-config');
        }
        $cfg = $this->app['config']->get('watchtower');
        if (!($cfg['enabled'] ?? true)) {
            return;
        }
        $provider = $this->app->make(TracerProviderInterface::class);
        $tracer = $provider->getTracer(self::TRACER);
        $reporter = $this->app->make(Reporter::class);

        $this->callAfterResolving(ExceptionHandler::class, function ($handler) use ($reporter): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) use ($reporter): void {
                    $reporter->capture($e);
                });
            }
        });

        // `'driver' => 'watchtower'` in config/logging.php
        $this->app->make('log')->extend('watchtower', fn ($app, array $c) => new Logger('watchtower', [
            new WatchtowerHandler($reporter, Level::fromName(ucfirst(strtolower($c['level'] ?? 'error')))),
        ]));

        $events = $this->app['events'];
        if ($cfg['queue_spans'] ?? true) {
            (new QueueTracing($tracer, $reporter, $provider))->listen($events);
        }
        if ($cfg['query_spans'] ?? true) {
            QueryTracing::listen($events, $tracer);
        }
        $this->app->terminating(static fn () => $provider->forceFlush());
    }
}
