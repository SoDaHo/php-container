<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Cache\ContainerCache;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\CacheException;
use Sodaho\Container\Tests\Unit\Fixtures as UnitFixtures;

/**
 * Integration tests for Container + Cache working together.
 */
class CacheIntegrationTest extends TestCase
{
    private string $cacheFile;
    private string $signatureKey = 'integration-test-key';

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/container_integration_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    private function container(): Container
    {
        return Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
    }

    /**
     * Resolve the ids in a first request and save the cache.
     *
     * @param list<string> $ids
     */
    private function warm(array $ids): void
    {
        $container = $this->container();
        foreach ($ids as $id) {
            $container->get($id);
        }
        $container->saveCache();
    }

    /**
     * @return array{hit: list<string>, miss: list<string>}
     */
    private function &countCacheEvents(Container $container): array
    {
        $events = ['hit' => [], 'miss' => []];
        $container->on('cacheHit', function (array $data) use (&$events) {
            $events['hit'][] = $data['id'];
        });
        $container->on('cacheMiss', function (array $data) use (&$events) {
            $events['miss'][] = $data['id'];
        });

        return $events;
    }

    // ==================== Cache Save/Load Cycle ====================

    public function testCacheSaveAndLoad(): void
    {
        // First request: resolve with Reflection, save cache
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $controller1 = $container1->get(Fixtures\TestController::class);
        $container1->saveCache();

        $this->assertFileExists($this->cacheFile);

        // Second request: load from cache (no Reflection)
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $controller2 = $container2->get(Fixtures\TestController::class);

        $this->assertInstanceOf(Fixtures\TestController::class, $controller2);
        $this->assertInstanceOf(Fixtures\TestService::class, $controller2->service);
    }

    public function testCacheDisabledInDebugMode(): void
    {
        $container = Container::create([
            'debug' => true,
            'cacheFile' => $this->cacheFile,
        ]);

        $container->get(Fixtures\TestController::class);
        $container->saveCache();

        // Cache should NOT be written in debug mode
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testSetDebugTrueAfterConstructorPreventsCacheLoading(): void
    {
        // First: build and save cache
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container1->get(Fixtures\TestController::class);
        $container1->saveCache();
        $this->assertFileExists($this->cacheFile);

        // Second: create with cache, then switch to debug
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container2->setDebug(true);

        // Should NOT load cache in debug mode
        $misses = [];
        $hits = [];
        $container2->on('cacheMiss', function (array $data) use (&$misses) {
            $misses[] = $data['id'];
        });
        $container2->on('cacheHit', function (array $data) use (&$hits) {
            $hits[] = $data['id'];
        });

        $container2->get(Fixtures\TestController::class);

        $this->assertEmpty($hits, 'Cache should not be read in debug mode');
    }

    public function testSetDebugTrueAfterConstructorPreventsCacheWriting(): void
    {
        $container = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container->setDebug(true);

        $container->get(Fixtures\TestController::class);
        $container->saveCache();

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testSetDebugFalseAfterConstructorEnablesCaching(): void
    {
        $container = Container::create(['debug' => true])
            ->enableCache($this->cacheFile, $this->signatureKey)
            ->setDebug(false);

        $container->get(Fixtures\TestController::class);
        $container->saveCache();

        $this->assertFileExists($this->cacheFile);
    }

    public function testEnableCacheFluentApi(): void
    {
        $container = Container::create()
            ->setDebug(false)
            ->enableCache($this->cacheFile, $this->signatureKey);

        $container->get(Fixtures\TestController::class);
        $container->saveCache();

        $this->assertFileExists($this->cacheFile);
    }

    public function testEnableCacheAfterResolvingServices(): void
    {
        $container = Container::create()->setDebug(false);

        // Resolve services before enabling cache
        $controller1 = $container->get(Fixtures\TestController::class);

        // Enable cache after resolution
        $container->enableCache($this->cacheFile, $this->signatureKey);
        $container->saveCache();

        // Cache should contain the previously resolved metadata
        $this->assertFileExists($this->cacheFile);

        // Verify cache is usable in a new container
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $controller2 = $container2->get(Fixtures\TestController::class);

        $this->assertInstanceOf(Fixtures\TestController::class, $controller2);
        $this->assertInstanceOf(Fixtures\TestService::class, $controller2->service);
    }

    // ==================== Cache with Dependencies ====================

    public function testCacheWithDefaultValues(): void
    {
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $service1 = $container1->get(Fixtures\ServiceWithDefaults::class);
        $container1->saveCache();

        // Load from cache
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $service2 = $container2->get(Fixtures\ServiceWithDefaults::class);

        $this->assertEquals('default', $service2->value);
        $this->assertEquals(42, $service2->number);
    }

    public function testCacheWithNestedDependencies(): void
    {
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $deep1 = $container1->get(Fixtures\DeepController::class);
        $container1->saveCache();

        // Load from cache
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $deep2 = $container2->get(Fixtures\DeepController::class);

        $this->assertInstanceOf(Fixtures\DeepController::class, $deep2);
        $this->assertInstanceOf(Fixtures\TestController::class, $deep2->controller);
        $this->assertInstanceOf(Fixtures\TestService::class, $deep2->controller->service);
    }

    // ==================== Cache Signature ====================

    public function testCacheWithSignature(): void
    {
        $signature = 'my-secret-key';

        // Save with signature
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $signature,
        ]);

        $container1->get(Fixtures\TestController::class);
        $container1->saveCache();

        // Verify file contains signature
        $content = file_get_contents($this->cacheFile);
        $this->assertStringContainsString('HMAC-SHA256:', $content);

        // Load with same signature (should work)
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $signature,
        ]);

        $controller = $container2->get(Fixtures\TestController::class);
        $this->assertInstanceOf(Fixtures\TestController::class, $controller);
    }

    public function testCacheWithWrongSignatureThrows(): void
    {
        // Save with signature
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => 'key1',
        ]);

        $container1->get(Fixtures\TestController::class);
        $container1->saveCache();

        // Load with different signature
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => 'key2',
        ]);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature');

        $container2->get(Fixtures\TestController::class);
    }

    // ==================== Cache Dirty Flag ====================

    public function testSaveCacheOnlyWhenDirty(): void
    {
        // First: save cache
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container1->get(Fixtures\TestController::class);
        $container1->saveCache();

        // save() moves a fresh temp file into place, so any rewrite changes the inode
        $inode1 = fileinode($this->cacheFile);

        // Second: load from cache, don't resolve new classes
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container2->get(Fixtures\TestController::class); // From cache
        $container2->saveCache(); // Should NOT write (not dirty)

        clearstatcache();

        $this->assertSame($inode1, fileinode($this->cacheFile), 'Cache should not be rewritten if not dirty');
    }

    public function testCacheUpdatedWhenNewClassResolved(): void
    {
        // First: save cache with one class
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container1->get(Fixtures\TestService::class);
        $container1->saveCache();

        $content1 = file_get_contents($this->cacheFile);
        $this->assertStringContainsString('TestService', $content1);
        $this->assertStringNotContainsString('TestController', $content1);

        // Second: resolve additional class
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container2->get(Fixtures\TestService::class);  // From cache
        $container2->get(Fixtures\TestController::class); // New - triggers dirty
        $container2->saveCache();

        $content2 = file_get_contents($this->cacheFile);
        $this->assertStringContainsString('TestService', $content2);
        $this->assertStringContainsString('TestController', $content2);
    }

    // ==================== Clear Cache ====================

    public function testClearCache(): void
    {
        $container = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $container->get(Fixtures\TestController::class);
        $container->saveCache();

        $this->assertFileExists($this->cacheFile);

        $result = $container->clearCache();

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testClearCacheReturnsFalseIfNoFile(): void
    {
        $container = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $result = $container->clearCache();

        $this->assertFalse($result);
    }

    public function testClearCacheResetsInternalState(): void
    {
        $container = $this->container();
        $container->get(Fixtures\TestService::class);
        $container->saveCache();
        $this->assertFileExists($this->cacheFile);

        $container->clearCache();
        $this->assertFileDoesNotExist($this->cacheFile);

        // The same container starts over: a new class is a miss again and only that class is written
        $events = &$this->countCacheEvents($container);
        $container->get(Fixtures\ServiceWithDefaults::class);
        $container->saveCache();

        $this->assertSame([Fixtures\ServiceWithDefaults::class], $events['miss']);
        $this->assertSame(
            [Fixtures\ServiceWithDefaults::class],
            array_keys((array) (new ContainerCache($this->cacheFile, $this->signatureKey))->load())
        );
    }

    // ==================== Cache Hooks ====================

    public function testCacheMissHookFired(): void
    {
        $container = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $misses = [];
        $container->on('cacheMiss', function (array $data) use (&$misses) {
            $misses[] = $data['id'];
        });

        $container->get(Fixtures\TestService::class);

        $this->assertContains(Fixtures\TestService::class, $misses);
    }

    public function testCacheHitHookFired(): void
    {
        // First: build cache
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container1->get(Fixtures\TestService::class);
        $container1->saveCache();

        // Second: load from cache
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $hits = [];
        $container2->on('cacheHit', function (array $data) use (&$hits) {
            $hits[] = $data['id'];
        });

        $container2->get(Fixtures\TestService::class);

        $this->assertContains(Fixtures\TestService::class, $hits);
    }

    public function testCacheHooksNotFiredWithoutCache(): void
    {
        $container = new Container();  // No cache configured

        $hits = [];
        $misses = [];
        $container->on('cacheHit', function (array $data) use (&$hits) {
            $hits[] = $data['id'];
        });
        $container->on('cacheMiss', function (array $data) use (&$misses) {
            $misses[] = $data['id'];
        });

        $container->get(Fixtures\TestService::class);

        $this->assertEmpty($hits);
        $this->assertEmpty($misses);
    }

    // ==================== Defaults That Cannot Be Cached ====================

    public function testClassWithUncacheableDefaultIsNotCachedAndDoesNotForceRewrites(): void
    {
        // An untyped parameter always gets its default, so the object would have to be stored
        $this->warm([Fixtures\TestService::class, UnitFixtures\ServiceWithUntypedObjectDefault::class]);

        $content = (string) file_get_contents($this->cacheFile);
        $this->assertStringContainsString('TestService', $content);
        $this->assertStringNotContainsString('ServiceWithUntypedObjectDefault', $content);
        $inode = fileinode($this->cacheFile);

        $container = $this->container();
        $events = &$this->countCacheEvents($container);
        $service = $container->get(UnitFixtures\ServiceWithUntypedObjectDefault::class);
        $container->get(Fixtures\TestService::class);
        $container->saveCache();
        clearstatcache();

        $this->assertInstanceOf(UnitFixtures\FileLogger::class, $service->logger);
        $this->assertSame([Fixtures\TestService::class], $events['hit'], 'The cache still serves the other classes');
        $this->assertSame([UnitFixtures\ServiceWithUntypedObjectDefault::class], $events['miss']);
        $this->assertSame($inode, fileinode($this->cacheFile), 'A class that cannot be cached must not make every request rewrite the file');
    }

    // ==================== Signature Errors ====================

    public function testAfterASignatureErrorTheContainerWorksWithoutTheCacheAndReplacesTheFile(): void
    {
        $this->warm([Fixtures\TestService::class]);
        $rotated = Container::create(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'new-key']);

        try {
            $rotated->get(Fixtures\TestService::class);
            $this->fail('Expected CacheException');
        } catch (CacheException) {
            // Reported once; an application that carries on gets a working container
        }

        $this->assertInstanceOf(Fixtures\TestService::class, $rotated->get(Fixtures\TestService::class));
        $rotated->saveCache();

        $next = Container::create(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'new-key']);
        $events = &$this->countCacheEvents($next);
        $next->get(Fixtures\TestService::class);
        $this->assertSame([Fixtures\TestService::class], $events['hit']);
    }

    public function testFactoryServicesNeverReadTheCacheFile(): void
    {
        file_put_contents($this->cacheFile, 'not a cache file');
        $container = $this->container();
        $container->set('value', fn () => 42);

        $this->assertSame(42, $container->get('value'));
    }

    // ==================== Upgrade from 1.0.x ====================

    public function testCacheFileWrittenBy10IsReplacedWithoutBeingExecuted(): void
    {
        $marker = $this->cacheFile . '.executed';
        $export = var_export([
            Fixtures\TestService::class => ['class' => Fixtures\TestService::class, 'dependencies' => [], 'defaults' => []],
        ], true);
        file_put_contents(
            $this->cacheFile,
            "<?php\n// HMAC-SHA256: " . hash_hmac('sha256', $export, $this->signatureKey) . "\ntouch(" . var_export($marker, true) . ");\nreturn {$export};"
        );

        $upgraded = $this->container();
        $events = &$this->countCacheEvents($upgraded);
        $this->assertInstanceOf(Fixtures\TestService::class, $upgraded->get(Fixtures\TestService::class));
        $upgraded->saveCache();

        $this->assertFileDoesNotExist($marker);
        $this->assertSame([Fixtures\TestService::class], $events['miss']);
        $this->assertStringStartsWith('<?php __halt_compiler(); ?>', (string) file_get_contents($this->cacheFile));

        $next = $this->container();
        $events = &$this->countCacheEvents($next);
        $next->get(Fixtures\TestService::class);
        $this->assertSame([Fixtures\TestService::class], $events['hit']);
    }

    public function testCacheFileWrittenBy10IsReplacedEvenIfNothingCacheableWasResolved(): void
    {
        file_put_contents($this->cacheFile, "<?php\n// HMAC-SHA256: " . str_repeat('a', 64) . "\nreturn array ();");

        $container = $this->container();
        $container->get(UnitFixtures\ServiceWithUntypedObjectDefault::class);
        $container->saveCache();

        $this->assertStringStartsWith('<?php __halt_compiler(); ?>', (string) file_get_contents($this->cacheFile));
        $this->assertSame([], (new ContainerCache($this->cacheFile, $this->signatureKey))->load());
    }

    // ==================== enableCache() After First Use ====================

    public function testDisableCacheStopsUsingWhatWasLoaded(): void
    {
        // Outdated for TestController; loaded by the first get()
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            Fixtures\TestService::class => ['class' => Fixtures\TestService::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
            Fixtures\TestController::class => ['class' => Fixtures\TestController::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
        ]);
        $container = $this->container();
        $container->get(Fixtures\TestService::class);

        $container->disableCache();

        $this->assertInstanceOf(Fixtures\TestService::class, $container->get(Fixtures\TestController::class)->service);
    }

    // ==================== Concurrent First Requests ====================

    public function testConcurrentFirstRequestsLeaveAValidCacheAndEmitNoWarnings(): void
    {
        $directory = sys_get_temp_dir() . '/container_concurrency_' . uniqid();
        $cacheFile = $directory . '/not/yet/there/container.php';
        $startSignal = sys_get_temp_dir() . '/container_concurrency_' . uniqid() . '.go';
        $command = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', __DIR__ . '/Workers/first_request.php', $cacheFile, $startSignal];

        $processes = [];
        $pipes = [];
        for ($i = 0; $i < 12; $i++) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes[$i]);
            $this->assertIsResource($process);
            $processes[$i] = $process;
        }
        // Wait until every process is at the start line
        $deadline = microtime(true) + 10;
        while (count((array) glob($startSignal . '.ready.*')) < 12 && microtime(true) < $deadline) {
            usleep(1000);
        }
        $ready = count((array) glob($startSignal . '.ready.*'));
        touch($startSignal);

        $outputs = [];
        $exitCodes = [];
        foreach ($processes as $i => $process) {
            $outputs[] = stream_get_contents($pipes[$i][1]);
            fclose($pipes[$i][1]);
            $exitCodes[] = proc_close($process);
        }
        array_map('unlink', [$startSignal, ...(array) glob($startSignal . '.ready.*')]);

        try {
            $this->assertSame(12, $ready, 'Every process must be waiting before the start signal, or nothing races');
            $this->assertSame(array_fill(0, 12, 'ok'), $outputs);
            $this->assertSame(array_fill(0, 12, 0), $exitCodes);
            $this->assertSame(['container.php'], array_values(array_diff((array) scandir(dirname($cacheFile)), ['.', '..'])), 'No temp file left behind');

            $container = Container::create(['debug' => false, 'cacheFile' => $cacheFile, 'cacheSignature' => 'concurrency-key']);
            $events = &$this->countCacheEvents($container);
            $container->get(Fixtures\DeepController::class);
            $container->get(Fixtures\ServiceWithDefaults::class);
            $this->assertSame([], $events['miss']);
            $this->assertCount(4, $events['hit']);
        } finally {
            @unlink($cacheFile);
            @rmdir($directory . '/not/yet/there');
            @rmdir($directory . '/not/yet');
            @rmdir($directory . '/not');
            @rmdir($directory);
        }
    }
}
