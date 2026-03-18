<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\CacheException;

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

        $mtime1 = filemtime($this->cacheFile);
        clearstatcache();

        // Wait a moment
        usleep(10000);

        // Second: load from cache, don't resolve new classes
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container2->get(Fixtures\TestController::class); // From cache
        $container2->saveCache(); // Should NOT write (not dirty)

        clearstatcache();
        $mtime2 = filemtime($this->cacheFile);

        $this->assertEquals($mtime1, $mtime2, 'Cache should not be rewritten if not dirty');
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
        // First container: build and save cache
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $container1->get(Fixtures\TestController::class);
        $container1->saveCache();
        $this->assertFileExists($this->cacheFile);

        // Clear cache
        $container1->clearCache();
        $this->assertFileDoesNotExist($this->cacheFile);

        // New container: should rebuild cache from scratch
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        $container2->get(Fixtures\TestController::class);
        $container2->saveCache();

        // Cache should be recreated
        $this->assertFileExists($this->cacheFile);
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
}
