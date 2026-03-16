<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sodaho\Container\Container;

/**
 * Feature tests - real-world usage scenarios.
 */
class RealWorldTest extends TestCase
{
    private string $cacheFile;
    private string $signatureKey = 'feature-test-key';

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/container_feature_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    // ==================== Typical Application Bootstrap ====================

    public function testTypicalApplicationBootstrap(): void
    {
        // Simulate typical app bootstrap
        $container = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);

        // Bind interfaces to implementations
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $container->bind(Fixtures\CacheInterface::class, Fixtures\ArrayCache::class);
        $container->bind(Fixtures\DatabaseInterface::class, Fixtures\SqliteDatabase::class);

        // Manual definition for config-dependent service
        $container->set(Fixtures\MailerService::class, fn (Container $c) => new Fixtures\MailerService(
            'smtp.example.com',
            587,
            $c->get(Fixtures\LoggerInterface::class),
        ));

        // Get application
        $app = $container->get(Fixtures\Application::class);

        $this->assertInstanceOf(Fixtures\Application::class, $app);
        $this->assertInstanceOf(Fixtures\UserController::class, $app->userController);
        $this->assertInstanceOf(Fixtures\FileLogger::class, $app->userController->logger);

        // Save cache for next request
        $container->saveCache();
        $this->assertFileExists($this->cacheFile);
    }

    public function testSubsequentRequestUsesCache(): void
    {
        // First request - build cache
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container1->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $container1->get(Fixtures\UserController::class);
        $container1->saveCache();

        // Second request - should use cache
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $this->signatureKey,
        ]);
        $container2->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);

        $controller = $container2->get(Fixtures\UserController::class);

        $this->assertInstanceOf(Fixtures\UserController::class, $controller);
        $this->assertInstanceOf(Fixtures\FileLogger::class, $controller->logger);
    }

    // ==================== PSR-11 Compliance ====================

    public function testPsr11Compliance(): void
    {
        $container = new Container();

        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    public function testContainerCanBeInjected(): void
    {
        $container = new Container();

        // Service that needs the container
        $container->set(Fixtures\ServiceLocator::class, fn (Container $c) => new Fixtures\ServiceLocator($c));

        $locator = $container->get(Fixtures\ServiceLocator::class);

        $this->assertInstanceOf(Fixtures\ServiceLocator::class, $locator);
        $this->assertSame($container, $locator->container);
    }

    // ==================== Multiple Binding Patterns ====================

    public function testDecoratorPattern(): void
    {
        $container = new Container();

        // Base logger
        $container->set(Fixtures\LoggerInterface::class, function (Container $c) {
            $fileLogger = $c->get(Fixtures\FileLogger::class);
            return new Fixtures\TimestampLogger($fileLogger);
        });

        $logger = $container->get(Fixtures\LoggerInterface::class);

        $this->assertInstanceOf(Fixtures\TimestampLogger::class, $logger);
        $this->assertInstanceOf(Fixtures\FileLogger::class, $logger->inner);
    }

    public function testFactoryWithMultipleDependencies(): void
    {
        $container = new Container();

        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $container->bind(Fixtures\CacheInterface::class, Fixtures\ArrayCache::class);

        $container->set(Fixtures\ComplexService::class, fn (Container $c) => new Fixtures\ComplexService(
            $c->get(Fixtures\LoggerInterface::class),
            $c->get(Fixtures\CacheInterface::class),
            'api-key-123',
            ['timeout' => 30],
        ));

        $service = $container->get(Fixtures\ComplexService::class);

        $this->assertInstanceOf(Fixtures\ComplexService::class, $service);
        $this->assertEquals('api-key-123', $service->apiKey);
        $this->assertEquals(['timeout' => 30], $service->options);
    }

    // ==================== Edge Cases ====================

    public function testSameInstanceAcrossMultipleDependencies(): void
    {
        $container = new Container();
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);

        // Two controllers that both need the logger
        $controller1 = $container->get(Fixtures\UserController::class);
        $controller2 = $container->get(Fixtures\ProductController::class);

        // They should share the same logger instance
        $this->assertSame($controller1->logger, $controller2->logger);
    }

    public function testLateBindingOverride(): void
    {
        $container = new Container();

        // Initial binding
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);

        // Override for testing
        $container->set(Fixtures\LoggerInterface::class, fn () => new Fixtures\NullLogger());

        $controller = $container->get(Fixtures\UserController::class);

        $this->assertInstanceOf(Fixtures\NullLogger::class, $controller->logger);
    }

    // ==================== Production Cache with Signature ====================

    public function testProductionCacheWithSignature(): void
    {
        $secretKey = 'production-secret-key-' . bin2hex(random_bytes(16));

        // Deploy: build cache
        $container1 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $secretKey,
        ]);
        $container1->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $container1->get(Fixtures\UserController::class);
        $container1->saveCache();

        // Verify cache has signature
        $content = file_get_contents($this->cacheFile);
        $this->assertStringContainsString('HMAC-SHA256:', $content);

        // Production: use cache
        $container2 = Container::create([
            'debug' => false,
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => $secretKey,
        ]);
        $container2->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);

        $controller = $container2->get(Fixtures\UserController::class);

        $this->assertInstanceOf(Fixtures\UserController::class, $controller);
    }
}
