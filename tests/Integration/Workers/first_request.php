<?php

declare(strict_types=1);

/**
 * One "first request": reports ready, waits for the start signal, resolves, saves the cache.
 * Prints "ok" and nothing else, so any PHP warning shows up in the output.
 *
 * Usage: php first_request.php <cacheFile> <startSignalFile>
 */

use Sodaho\Container\Container;
use Sodaho\Container\Tests\Integration\Fixtures\DeepController;
use Sodaho\Container\Tests\Integration\Fixtures\ServiceWithDefaults;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $cacheFile, $startSignal] = $argv;

// Report at the start line, then wait for the signal so that all workers run at once
touch($startSignal . '.ready.' . getmypid());
$deadline = microtime(true) + 10;
while (!file_exists($startSignal)) {
    if (microtime(true) > $deadline) {
        echo 'no start signal';
        exit(1);
    }
    usleep(100);
}

$container = new Container(['debug' => false, 'cacheFile' => $cacheFile, 'cacheSignature' => 'concurrency-key']);
$container->get(DeepController::class);
$container->get(ServiceWithDefaults::class);
$container->saveCache();

echo 'ok';
