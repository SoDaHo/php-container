<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;

/**
 * Unit tests for environment variable configuration.
 */
class EnvironmentConfigTest extends TestCase
{
    private string $cacheFile;

    private const KEYS = ['APP_DEBUG', 'APP_ENV', 'CONTAINER_CACHE_FILE', 'CONTAINER_CACHE_KEY'];

    /** @var array<string, mixed> */
    private array $originalEnv = [];

    /** @var array<string, string|false> */
    private array $originalProcessEnv = [];

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/container_env_test_' . uniqid() . '.php';

        // Backup $_ENV and the process environment, then clear both
        $this->originalEnv = $_ENV;
        foreach (self::KEYS as $key) {
            $this->originalProcessEnv[$key] = getenv($key);
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }

        $_ENV = $this->originalEnv;
        foreach ($this->originalProcessEnv as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    // ==================== Config Priority Tests ====================

    public function testConfigTakesPriorityOverEnv(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = '/env/path.php';

        $container = new Container([
            'cacheFile' => $this->cacheFile,
            'cacheSignature' => 'test-key',
            'debug' => false,
        ]);

        $container->get(\stdClass::class);
        $container->saveCache();

        // Config value should be used, not $_ENV
        $this->assertFileExists($this->cacheFile);
    }

    public function testEnvTakesPriorityOverGetenv(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = 'test-key';
        putenv('CONTAINER_CACHE_FILE=/getenv/path.php');

        $container = Container::create(['debug' => false]);

        $container->get(\stdClass::class);
        $container->saveCache();

        // $_ENV should be used, not getenv()
        $this->assertFileExists($this->cacheFile);
    }

    public function testGetenvFallbackWhenEnvNotSet(): void
    {
        putenv('CONTAINER_CACHE_FILE=' . $this->cacheFile);
        putenv('CONTAINER_CACHE_KEY=test-key');

        $container = Container::create(['debug' => false]);

        $container->get(\stdClass::class);
        $container->saveCache();

        // getenv() fallback should be used
        $this->assertFileExists($this->cacheFile);
    }

    // ==================== Debug Mode Auto-Detection ====================

    public function testDebugModeViaAppDebugTrue(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;

        $container = new Container();

        $container->get(\stdClass::class);
        $container->saveCache();

        // Debug mode should prevent cache writing
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testDebugModeViaAppDebug1(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;

        $container = new Container();

        $container->get(\stdClass::class);
        $container->saveCache();

        // Debug mode should prevent cache writing
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testDebugModeViaAppEnvLocal(): void
    {
        $_ENV['APP_ENV'] = 'local';
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;

        $container = new Container();

        $container->get(\stdClass::class);
        $container->saveCache();

        // Debug mode should prevent cache writing
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testDebugModeViaAppEnvDev(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;

        $container = new Container();

        $container->get(\stdClass::class);
        $container->saveCache();

        // Debug mode should prevent cache writing
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testDebugModeViaAppEnvDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;

        $container = new Container();

        $container->get(\stdClass::class);
        $container->saveCache();

        // Debug mode should prevent cache writing
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testProductionModeEnablesCaching(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = 'prod-key';

        $container = new Container();

        $container->get(\stdClass::class);
        $container->saveCache();

        // Cache should be written in production
        $this->assertFileExists($this->cacheFile);
    }

    public function testConfigDebugOverridesEnvDetection(): void
    {
        $_ENV['APP_ENV'] = 'local';  // Would normally enable debug
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = 'test-key';

        $container = new Container(['debug' => false]);  // Explicit override

        $container->get(\stdClass::class);
        $container->saveCache();

        // Cache should be written because config overrides
        $this->assertFileExists($this->cacheFile);
    }

    // ==================== Cache Signature from Env ====================

    public function testCacheSignatureFromEnv(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = 'env-secret-key';
        $_ENV['APP_DEBUG'] = 'false';

        $container = new Container();
        $container->get(\stdClass::class);
        $container->saveCache();

        $content = file_get_contents($this->cacheFile);
        $this->assertStringContainsString('HMAC-SHA256:', $content);
    }
}
