<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Cache\ContainerCache;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\CacheException;
use Sodaho\Container\Exception\ContainerException;
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
        UnitFixtures\ServiceFailsOnDemand::$fail = false;
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

    public function testCacheFileHoldsTheConstructorSignature(): void
    {
        $this->warm([Fixtures\TestController::class, UnitFixtures\ServiceWithOptionalInterface::class, Fixtures\ServiceWithDefaults::class]);
        $stored = (array) (new ContainerCache($this->cacheFile, $this->signatureKey))->load();
        ksort($stored);
        $expected = [
            Fixtures\TestService::class => ['class' => Fixtures\TestService::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
            Fixtures\TestController::class => [
                'class' => Fixtures\TestController::class,
                'dependencies' => [Fixtures\TestService::class],
                'defaults' => [],
                'optional' => [],
            ],
            UnitFixtures\ServiceWithOptionalInterface::class => [
                'class' => UnitFixtures\ServiceWithOptionalInterface::class,
                'dependencies' => [UnitFixtures\ServiceInterface::class],
                'defaults' => [],
                'optional' => [0 => true],
            ],
            Fixtures\ServiceWithDefaults::class => [
                'class' => Fixtures\ServiceWithDefaults::class,
                'dependencies' => [null, null],
                'defaults' => ['default', 42],
                'optional' => [],
            ],
        ];
        ksort($expected);

        $this->assertSame($expected, $stored);
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

    // ==================== Warm Resolution Equals Cold Resolution ====================

    /**
     * What a request observes for one id: the object graph or the exception, plus the hooks.
     *
     * @return array{result: string, errors: list<string>, resolved: list<string>}
     */
    private function observe(Container $container, string $id): array
    {
        $errors = [];
        $container->on('error', function (array $data) use (&$errors) {
            $errors[] = $data['id'] . ': ' . $data['exception']::class;
        });
        $resolved = [];
        $container->on('resolve', function (array $data) use (&$resolved) {
            $resolved[] = $data['id'];
        });

        try {
            $result = print_r($container->get($id), true);
        } catch (ContainerException $e) {
            $result = $e::class . ': ' . $e->getMessage() . ' / debug: ' . $e->getDebugMessage()
                . ' / previous: ' . ($e->getPrevious() === null ? 'none' : $e->getPrevious()::class);
        }

        return ['result' => $result, 'errors' => $errors, 'resolved' => $resolved];
    }

    /**
     * @return array<string, array{string, bool}> id, and whether the warm request must be served from the cache
     *                                            (a class is remembered once its arguments could be put together)
     */
    public static function idsForColdAndWarm(): array
    {
        return [
            'no constructor' => [Fixtures\TestService::class, true],
            'nested dependencies' => [Fixtures\DeepController::class, true],
            'scalar defaults' => [Fixtures\ServiceWithDefaults::class, true],
            'union default' => [UnitFixtures\ServiceWithUnionDefault::class, true],
            'optional interface, nothing bound' => [UnitFixtures\ServiceWithOptionalDep::class, true],
            'optional abstract class' => [UnitFixtures\ServiceWithOptionalAbstract::class, true],
            'bound interface' => [UnitFixtures\ControllerWithInterface::class, true],
            'optional interface, bound' => [UnitFixtures\ServiceWithOptionalInterface::class, true],
            'enum default' => [UnitFixtures\ServiceWithEnumDefault::class, true],
            'object default' => [UnitFixtures\ServiceWithObjectDefault::class, true],
            'untyped object default' => [UnitFixtures\ServiceWithUntypedObjectDefault::class, false],
            'untyped enum default' => [UnitFixtures\ServiceWithUntypedEnumDefault::class, true],
            'fails: default throws' => [UnitFixtures\ServiceWithThrowingDefault::class, false],
            'fails: untyped default throws' => [UnitFixtures\ServiceWithThrowingUntypedDefault::class, false],
            'fails: missing dependency' => [UnitFixtures\ServiceWithNullableNoDefault::class, false],
            'fails: optional dependency cannot be built' => [UnitFixtures\ServiceWithOptionalBroken::class, false],
            'fails: constructor throws' => [UnitFixtures\ServiceThrowsInConstructor::class, true],
            'fails: circular' => [UnitFixtures\CircularA::class, false],
            'fails: primitive' => [UnitFixtures\ServiceWithConfig::class, false],
            'fails: abstract' => [UnitFixtures\AbstractService::class, false],
            'fails: not found' => ['Missing\Service', false],
        ];
    }

    #[DataProvider('idsForColdAndWarm')]
    public function testWarmResolutionBehavesExactlyLikeColdResolution(string $id, bool $servedFromCache): void
    {
        $configure = fn (Container $c): Container => $c->bind(UnitFixtures\ServiceInterface::class, UnitFixtures\ConcreteService::class);

        $cold = $this->observe($configure(new Container(['debug' => true])), $id);

        // First request fills the cache, whatever the outcome
        $first = $configure($this->container());
        $this->observe($first, $id);
        $first->saveCache();

        $warmContainer = $configure($this->container());
        $events = &$this->countCacheEvents($warmContainer);
        $warm = $this->observe($warmContainer, $id);

        $this->assertSame($cold, $warm);
        $this->assertSame($servedFromCache, in_array($id, $events['hit'], true));
    }

    public function testWarmResolvedClassIsSingleton(): void
    {
        $this->warm([Fixtures\TestController::class]);

        $container = $this->container();
        $controller = $container->get(Fixtures\TestController::class);

        $this->assertSame($controller, $container->get(Fixtures\TestController::class));
        $this->assertSame($controller->service, $container->get(Fixtures\TestService::class));
    }

    // ==================== Cached Metadata Is Not a Wiring Decision ====================

    public function testBindingAddedAfterCachingIsHonoured(): void
    {
        // Cached while nothing was bound: the optional parameter fell back to null
        $this->warm([UnitFixtures\ServiceWithOptionalInterface::class]);

        $container = $this->container()->bind(UnitFixtures\ServiceInterface::class, UnitFixtures\ConcreteService::class);
        $events = &$this->countCacheEvents($container);

        $this->assertInstanceOf(
            UnitFixtures\ConcreteService::class,
            $container->get(UnitFixtures\ServiceWithOptionalInterface::class)->service
        );
        $this->assertContains(UnitFixtures\ServiceWithOptionalInterface::class, $events['hit']);
    }

    public function testBindingRemovedAfterCachingFallsBackToDefault(): void
    {
        $bound = $this->container()->bind(UnitFixtures\ServiceInterface::class, UnitFixtures\ConcreteService::class);
        $bound->get(UnitFixtures\ServiceWithOptionalInterface::class);
        $bound->saveCache();

        $this->assertNull($this->container()->get(UnitFixtures\ServiceWithOptionalInterface::class)->service);
    }

    public function testCycleIntroducedByBindingIsDetectedOnWarmCache(): void
    {
        // Acyclic when cached: NeedsLogger -> LoggerInterface -> FileLogger
        $first = $this->container()->bind(UnitFixtures\LoggerInterface::class, UnitFixtures\FileLogger::class);
        $first->get(UnitFixtures\NeedsLogger::class);
        $first->saveCache();

        $container = $this->container()->bind(UnitFixtures\LoggerInterface::class, UnitFixtures\NeedsLogger::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected: ' . UnitFixtures\NeedsLogger::class . ' -> ' . UnitFixtures\LoggerInterface::class . ' -> ' . UnitFixtures\NeedsLogger::class
        );

        $container->get(UnitFixtures\NeedsLogger::class);
    }

    public function testConstructorFailureOnWarmCacheIsWrappedAndReported(): void
    {
        $this->warm([UnitFixtures\ServiceFailsOnDemand::class]);
        UnitFixtures\ServiceFailsOnDemand::$fail = true;

        $container = $this->container();
        $errors = [];
        $container->on('error', function (array $data) use (&$errors) {
            $errors[] = $data;
        });

        try {
            $container->get(UnitFixtures\ServiceFailsOnDemand::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Failed to instantiate '" . UnitFixtures\ServiceFailsOnDemand::class . "': Failing on demand", $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        $this->assertCount(1, $errors);
        $this->assertSame(UnitFixtures\ServiceFailsOnDemand::class, $errors[0]['id']);
        $this->assertInstanceOf(\RuntimeException::class, $errors[0]['exception']);
    }

    public function testOutdatedMetadataIsReportedAsContainerException(): void
    {
        // The cache predates a code change: TestController gained its constructor parameter afterwards
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            Fixtures\TestController::class => [
                'class' => Fixtures\TestController::class,
                'dependencies' => [],
                'defaults' => [],
                'optional' => [],
            ],
        ]);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Failed to instantiate '" . Fixtures\TestController::class . "'");

        $this->container()->get(Fixtures\TestController::class);
    }

    public function testOutdatedMetadataNamesTheParameterByPositionWhenItIsGone(): void
    {
        // The cache still lists a dependency for a class that no longer has a constructor
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            Fixtures\TestService::class => [
                'class' => Fixtures\TestService::class,
                'dependencies' => ['Missing\Dependency'],
                'defaults' => [],
                'optional' => [],
            ],
        ]);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            "Cannot resolve dependency 'Missing\Dependency' for parameter '#0' in class '" . Fixtures\TestService::class . "'."
        );

        $this->container()->get(Fixtures\TestService::class);
    }

    public function testDefaultMissingFromTheMetadataIsReadFromTheClass(): void
    {
        // 1.0.x passed null here; the class knows better
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            Fixtures\ServiceWithDefaults::class => ['class' => Fixtures\ServiceWithDefaults::class, 'dependencies' => [null, null], 'defaults' => [1 => 7], 'optional' => []],
        ]);

        $service = $this->container()->get(Fixtures\ServiceWithDefaults::class);

        $this->assertSame('default', $service->value);
        $this->assertSame(7, $service->number);
    }

    public function testMetadataWithoutDefaultsKeyIsCompletedFromTheClass(): void
    {
        // The shape 1.0.x accepted from direct users of ContainerCache
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            UnitFixtures\ServiceWithNoTypeDefault::class => ['class' => UnitFixtures\ServiceWithNoTypeDefault::class, 'dependencies' => [null]],
        ]);

        $this->assertSame('default', $this->container()->get(UnitFixtures\ServiceWithNoTypeDefault::class)->value);
    }

    public function testNullDefaultInTheMetadataIsAValue(): void
    {
        // Stored as null when the class was cached; not to be mistaken for "unknown"
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            UnitFixtures\ServiceWithNoTypeDefault::class => ['class' => UnitFixtures\ServiceWithNoTypeDefault::class, 'dependencies' => [null], 'defaults' => [0 => null], 'optional' => []],
        ]);

        $this->assertNull($this->container()->get(UnitFixtures\ServiceWithNoTypeDefault::class)->value);
    }

    public function testDefaultMissingFromTheMetadataAndFromTheClassIsReported(): void
    {
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            Fixtures\TestController::class => ['class' => Fixtures\TestController::class, 'dependencies' => [null], 'defaults' => [], 'optional' => []],
        ]);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve parameter #0 in class '" . Fixtures\TestController::class . "': the cached metadata is outdated. Clear the cache.");

        $this->container()->get(Fixtures\TestController::class);
    }

    public function testClassNamedByTheMetadataThatIsGoneIsReportedAsOutdated(): void
    {
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            UnitFixtures\TestService::class => ['class' => 'Removed\\Replacement', 'dependencies' => [null], 'defaults' => [], 'optional' => []],
        ]);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve parameter #0 in class '" . UnitFixtures\TestService::class . "': the cached metadata is outdated. Clear the cache.");

        $this->container()->get(UnitFixtures\TestService::class);
    }

    public function testClassNamedByTheMetadataIsTheOneThatIsCreated(): void
    {
        // As in 1.0.x: the entry says which class to create for the id
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            UnitFixtures\TestService::class => ['class' => UnitFixtures\ReplacementService::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
        ]);

        $this->assertInstanceOf(UnitFixtures\ReplacementService::class, $this->container()->get(UnitFixtures\TestService::class));
    }

    public function testUncacheableDefaultIsEvaluatedAfterTheDependenciesInFrontOfItOnEveryRequest(): void
    {
        foreach ([1, 2] as $request) {
            UnitFixtures\BootedService::$booted = false;
            $container = $this->container();

            $this->assertInstanceOf(
                UnitFixtures\NeedsBootedService::class,
                $container->get(UnitFixtures\ServiceWithDefaultAfterDependency::class)->value
            );
            $container->saveCache();
        }
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

    public function testObjectAndEnumDefaultsOfOptionalDependenciesAreCachedAsSignatureOnly(): void
    {
        $ids = [UnitFixtures\ServiceWithObjectDefault::class, UnitFixtures\ServiceWithEnumDefault::class, UnitFixtures\ServiceWithCountingDefault::class];
        $this->warm($ids);
        UnitFixtures\CountingLogger::$created = 0;

        $container = $this->container()->bind(UnitFixtures\LoggerInterface::class, UnitFixtures\FileLogger::class);
        $events = &$this->countCacheEvents($container);

        $this->assertInstanceOf(UnitFixtures\FileLogger::class, $container->get(UnitFixtures\ServiceWithCountingDefault::class)->logger);
        $this->assertSame(0, UnitFixtures\CountingLogger::$created, 'A default that is not used must not be created');
        $this->assertSame(UnitFixtures\Mode::Safe, $container->get(UnitFixtures\ServiceWithEnumDefault::class)->mode);
        $this->assertSame([UnitFixtures\ServiceWithCountingDefault::class, UnitFixtures\ServiceWithEnumDefault::class], $events['hit']);
        $this->assertSame([UnitFixtures\FileLogger::class], $events['miss'], 'Only the newly bound implementation is unknown to the cache');

        $unbound = $this->container();
        $this->assertInstanceOf(UnitFixtures\CountingLogger::class, $unbound->get(UnitFixtures\ServiceWithCountingDefault::class)->logger);
        $this->assertSame(1, UnitFixtures\CountingLogger::$created);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function classesWhoseOptionalParameterIsGone(): array
    {
        return [
            'constructor removed' => [Fixtures\TestService::class],
            'parameter no longer has a default' => [Fixtures\TestController::class],
        ];
    }

    /**
     * @param class-string $id
     */
    #[DataProvider('classesWhoseOptionalParameterIsGone')]
    public function testOutdatedOptionalParameterIsReportedInsteadOfGuessed(string $id): void
    {
        // The cache says: parameter 0 is optional. The code no longer has that default.
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            $id => ['class' => $id, 'dependencies' => [UnitFixtures\NonExistentInterface::class], 'defaults' => [], 'optional' => [0 => true]],
        ]);
        $container = $this->container();
        $errors = [];
        $container->on('error', function (array $data) use (&$errors) {
            $errors[] = $data['id'];
        });

        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Cannot resolve parameter #0 in class '$id': the cached metadata is outdated. Clear the cache.", $e->getMessage());
        }

        $this->assertSame([$id], $errors);
    }

    // ==================== Signature Errors ====================

    public function testInvalidSignatureIsReportedToErrorHook(): void
    {
        $this->warm([Fixtures\TestService::class]);

        $container = Container::create(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'another-key']);
        $errors = [];
        $container->on('error', function (array $data) use (&$errors) {
            $errors[] = $data;
        });

        try {
            $container->get(Fixtures\TestService::class);
            $this->fail('Expected CacheException');
        } catch (CacheException $e) {
            $this->assertSame('Cache file signature is invalid', $e->getMessage());
        }

        $this->assertCount(1, $errors);
        $this->assertSame(Fixtures\TestService::class, $errors[0]['id']);
        $this->assertSame($e, $errors[0]['exception']);
    }

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

    public function testEnableCacheAfterResolvingKeepsEarlierMetadataWhenAFileExists(): void
    {
        $this->warm([Fixtures\ServiceWithDefaults::class]);

        $container = Container::create(['debug' => false]);
        $container->get(Fixtures\TestController::class);
        $container->enableCache($this->cacheFile, $this->signatureKey);
        $container->get(Fixtures\DeepController::class); // loads the existing file
        $container->saveCache();

        $next = $this->container();
        $events = &$this->countCacheEvents($next);
        $next->get(Fixtures\DeepController::class);
        $next->get(Fixtures\ServiceWithDefaults::class);

        $this->assertSame([], $events['miss']);
        $this->assertCount(4, $events['hit']);
    }

    public function testSwitchingTheCacheFileDropsWhatWasLoadedFromTheOldOne(): void
    {
        // File A is outdated for TestController, file B is right
        $fileA = $this->cacheFile . '.a';
        (new ContainerCache($fileA, $this->signatureKey))->save([
            Fixtures\TestService::class => ['class' => Fixtures\TestService::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
            Fixtures\TestController::class => ['class' => Fixtures\TestController::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
        ]);
        $this->warm([Fixtures\TestController::class]);

        try {
            foreach ([$this->cacheFile, $this->cacheFile . '.not-written-yet'] as $fileB) {
                $container = Container::create(['debug' => false, 'cacheFile' => $fileA, 'cacheSignature' => $this->signatureKey]);
                $container->get(Fixtures\TestService::class); // loads file A
                $container->enableCache($fileB);

                $this->assertInstanceOf(Fixtures\TestService::class, $container->get(Fixtures\TestController::class)->service);
            }
        } finally {
            unlink($fileA);
        }
    }

    public function testOwnAnalysisIsUsedEvenIfTheFileKnowsTheClassToo(): void
    {
        // Analyzed here, but not created: the constructor failed
        UnitFixtures\ServiceFailsOnDemand::$fail = true;
        $container = Container::create(['debug' => false]);
        try {
            $container->get(UnitFixtures\ServiceFailsOnDemand::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException) {
            UnitFixtures\ServiceFailsOnDemand::$fail = false;
        }

        // The file loaded afterwards is outdated for that class
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            UnitFixtures\ServiceFailsOnDemand::class => ['class' => UnitFixtures\ServiceFailsOnDemand::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
        ]);
        $container->enableCache($this->cacheFile, $this->signatureKey);

        $this->assertInstanceOf(UnitFixtures\TestService::class, $container->get(UnitFixtures\ServiceFailsOnDemand::class)->service);
    }

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

    public function testDebugModeStopsUsingWhatWasLoaded(): void
    {
        // Outdated for TestController; loaded by the first get()
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            Fixtures\TestService::class => ['class' => Fixtures\TestService::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
            Fixtures\TestController::class => ['class' => Fixtures\TestController::class, 'dependencies' => [], 'defaults' => [], 'optional' => []],
        ]);
        $container = $this->container();
        $container->get(Fixtures\TestService::class);

        $container->setDebug(true);

        $this->assertInstanceOf(Fixtures\TestService::class, $container->get(Fixtures\TestController::class)->service);
    }

    public function testClearCacheForgetsWhatWasLoaded(): void
    {
        $this->warm([Fixtures\TestService::class]);
        $container = $this->container();
        $container->get(Fixtures\TestService::class); // loads the file

        $container->clearCache();
        $container->get(Fixtures\ServiceWithDefaults::class);
        $container->saveCache();

        $this->assertSame(
            [Fixtures\ServiceWithDefaults::class],
            array_keys((array) (new ContainerCache($this->cacheFile, $this->signatureKey))->load())
        );
    }

    public function testOwnAnalysisWinsOverTheFile(): void
    {
        // Analyzed before the cache was enabled; the file that is loaded afterwards is outdated for the same class
        $container = Container::create(['debug' => false]);
        $container->get(Fixtures\ServiceWithDefaults::class);
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save([
            Fixtures\ServiceWithDefaults::class => ['class' => Fixtures\ServiceWithDefaults::class, 'dependencies' => [null], 'defaults' => ['outdated'], 'optional' => []],
        ]);

        $container->enableCache($this->cacheFile, $this->signatureKey);
        $container->get(Fixtures\TestService::class); // loads the file, adds a class
        $container->saveCache();

        $stored = (array) (new ContainerCache($this->cacheFile, $this->signatureKey))->load();
        $this->assertSame(['default', 42], $stored[Fixtures\ServiceWithDefaults::class]['defaults']);
        $this->assertArrayHasKey(Fixtures\TestService::class, $stored);
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
