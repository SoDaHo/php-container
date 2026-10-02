<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\CacheException;

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

    private function cacheIsWrittenBy(Container $container): bool
    {
        $container->get(\stdClass::class);
        $container->saveCache();

        return file_exists($this->cacheFile);
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

    // ==================== Explicit Config Wins, Even When It Says "Off" ====================

    public function testExplicitNullCacheFileDisablesCacheDespiteEnv(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = 'env-key';

        $container = new Container(['debug' => false, 'cacheFile' => null]);

        $this->assertFalse($this->cacheIsWrittenBy($container));
    }

    public function testExplicitNullCacheFileNeedsNoKeyDespiteEnvFile(): void
    {
        // Without the explicit null this constructor throws: cache file from the environment, no key
        putenv('CONTAINER_CACHE_FILE=' . $this->cacheFile);

        $container = new Container(['debug' => false, 'cacheFile' => null]);

        $this->assertFalse($this->cacheIsWrittenBy($container));
    }

    public function testEnvCacheFileWithoutKeyThrows(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new Container(['debug' => false]);
    }

    public function testExplicitEmptyCacheFileDisablesCacheDespiteEnv(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = 'env-key';

        $container = new Container(['debug' => false, 'cacheFile' => '']);

        $this->assertFalse($this->cacheIsWrittenBy($container));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unsetKeys(): array
    {
        return ['null' => [null], 'empty' => ['']];
    }

    #[DataProvider('unsetKeys')]
    public function testUnsetSignatureInConfigFallsBackToEnvKey(?string $unset): void
    {
        // 'cacheSignature' => $_ENV['CONTAINER_CACHE_KEY'] ?? null worked in 1.0.0 when only getenv() had the key
        putenv('CONTAINER_CACHE_KEY=env-key');

        $container = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => $unset]);

        $this->assertTrue($this->cacheIsWrittenBy($container));
    }

    public function testSignatureInConfigTakesPriorityOverEnvKey(): void
    {
        $_ENV['CONTAINER_CACHE_KEY'] = 'env-key';

        $container = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'config-key']);
        $this->assertTrue($this->cacheIsWrittenBy($container));

        $reader = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'env-key']);
        $this->expectException(CacheException::class);
        $reader->get(\stdClass::class);
    }

    public function testExplicitCacheFileStillTakesTheKeyFromEnv(): void
    {
        $_ENV['CONTAINER_CACHE_KEY'] = 'env-key';

        $container = new Container(['debug' => false, 'cacheFile' => $this->cacheFile]);

        $this->assertTrue($this->cacheIsWrittenBy($container));
    }

    // ==================== Empty Values Mean "Not Set" ====================

    public function testEmptyEnvCacheFileMeansNoCache(): void
    {
        // KEY= in a .env file arrives as an empty string
        $_ENV['CONTAINER_CACHE_FILE'] = '';

        $container = new Container(['debug' => false]);
        $container->get(\stdClass::class);
        $container->saveCache();

        $this->assertFalse($container->clearCache());
    }

    public function testEmptyEnvValueDoesNotHideGetenv(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = '';
        $_ENV['CONTAINER_CACHE_KEY'] = '';
        putenv('CONTAINER_CACHE_FILE=' . $this->cacheFile);
        putenv('CONTAINER_CACHE_KEY=process-key');

        $this->assertTrue($this->cacheIsWrittenBy(new Container(['debug' => false])));
    }

    public function testProcessEnvironmentValueZeroIsAValue(): void
    {
        putenv('CONTAINER_CACHE_FILE=' . $this->cacheFile);
        putenv('CONTAINER_CACHE_KEY=0');

        $this->assertTrue($this->cacheIsWrittenBy(new Container(['debug' => false])));
    }

    public function testEmptyGetenvValueMeansNoCache(): void
    {
        putenv('CONTAINER_CACHE_FILE=');

        $container = new Container(['debug' => false]);

        $this->assertFalse($this->cacheIsWrittenBy($container));
        $this->assertFalse($container->clearCache());
    }

    public function testEmptyEnvKeyCountsAsMissing(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = '';

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new Container(['debug' => false]);
    }

    public function testEmptyExplicitKeyCountsAsMissing(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => '']);
    }

    // ==================== enableCache() / disableCache() ====================

    public function testEnableCacheWithoutKeyKeepsTheConfiguredKey(): void
    {
        $_ENV['CONTAINER_CACHE_KEY'] = 'env-key';

        $container = Container::create(['debug' => false])->enableCache($this->cacheFile);

        $this->assertTrue($this->cacheIsWrittenBy($container));
        // Readable with the key from the environment
        $reader = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'env-key']);
        $hits = 0;
        $reader->on('cacheHit', function () use (&$hits) {
            $hits++;
        });
        $reader->get(\stdClass::class);
        $this->assertSame(1, $hits);
    }

    public function testEnableCacheWithKeyReplacesTheConfiguredKey(): void
    {
        $container = Container::create(['debug' => false, 'cacheSignature' => 'first-key'])
            ->enableCache($this->cacheFile, 'second-key');
        $this->assertTrue($this->cacheIsWrittenBy($container));

        $reader = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'first-key']);

        $this->expectException(CacheException::class);
        $reader->get(\stdClass::class);
    }

    public function testEnableCacheWithoutAnyKeyThrows(): void
    {
        $container = Container::create(['debug' => false]);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        $container->enableCache($this->cacheFile);
    }

    public function testEnableCacheWithEmptyFileDisablesCache(): void
    {
        $container = Container::create(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'key'])
            ->enableCache('');

        $this->assertFalse($this->cacheIsWrittenBy($container));
    }

    public function testDisableCacheStopsWriting(): void
    {
        $_ENV['CONTAINER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['CONTAINER_CACHE_KEY'] = 'env-key';

        $container = new Container(['debug' => false]);
        $container->get(\ArrayObject::class);

        $this->assertSame($container, $container->disableCache());
        $this->assertFalse($this->cacheIsWrittenBy($container));
    }

    public function testDisableCacheStopsReading(): void
    {
        file_put_contents($this->cacheFile, 'not a cache file');
        $container = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'key']);

        $container->disableCache();

        // An enabled cache would throw on this file
        $this->assertInstanceOf(\stdClass::class, $container->get(\stdClass::class));
        $this->assertFalse($container->clearCache(), 'clearCache() has no file to clear once caching is disabled');
        $this->assertFileExists($this->cacheFile);
    }

    public function testDisableCacheDropsMetadataLoadedFromTheFile(): void
    {
        $writer = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'key']);
        $writer->get(Fixtures\TestService::class);
        $writer->saveCache();
        $otherFile = $this->cacheFile . '.other';

        $container = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'key']);
        $container->get(Fixtures\TestService::class); // loads the file
        $container->disableCache()->enableCache($otherFile);
        $container->get(Fixtures\ConcreteService::class);
        $container->saveCache();

        $content = (string) file_get_contents($otherFile);
        unlink($otherFile);
        $this->assertStringContainsString('ConcreteService', $content);
        $this->assertStringNotContainsString('TestService', $content);
    }

    public function testDisableCacheDiscardsPendingWrites(): void
    {
        $container = new Container(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'key']);
        $container->get(Fixtures\TestService::class); // would be written by saveCache()

        $container->disableCache()->enableCache($this->cacheFile);
        $container->saveCache();

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testReEnablingAfterDisableCacheKeepsTheKey(): void
    {
        $container = Container::create(['debug' => false, 'cacheFile' => $this->cacheFile, 'cacheSignature' => 'key'])
            ->disableCache()
            ->enableCache($this->cacheFile);

        $this->assertTrue($this->cacheIsWrittenBy($container));
    }
}
