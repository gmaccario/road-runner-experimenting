<?php

use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\Http\PSR7Worker;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;

require __DIR__ . '/vendor/autoload.php';

$worker = Worker::create();
$factory = new Psr17Factory();
$psr7 = new PSR7Worker($worker, $factory, $factory, $factory);

while ($request = $psr7->waitRequest()) {
    try {
        $response = new Response(200, [], 'Hello from RoadRunner!');
        $psr7->respond($response);
    } catch (\Throwable $e) {
        $psr7->getWorker()->error((string)$e);
    }
}