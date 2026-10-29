<?php

// Run: composer install && WT_INGEST_KEY=... php run.php
// Boots the provider on a bare container, runs a query and reports an exception.
require __DIR__ . '/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Watchtower\Laravel\Reporter;
use Watchtower\Laravel\WatchtowerServiceProvider;

$app = new Application(__DIR__);
$app->instance('config', new Repository(['watchtower' => ['service' => 'example', 'endpoint' => getenv('WT_OTLP_ENDPOINT') ?: null, 'key' => getenv('WT_INGEST_KEY') ?: null]]));

$app->register(WatchtowerServiceProvider::class);
$app->boot();

$db = new Capsule($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setEventDispatcher($app['events']);
$db->setAsGlobal();
Capsule::select('select 1');

$traceId = $app->make(Reporter::class)->capture(new RuntimeException('example failure'));
echo "captured, trace id $traceId\n";
$app->terminate();
