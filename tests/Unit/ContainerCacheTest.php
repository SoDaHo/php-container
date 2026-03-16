<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Cache\ContainerCache;
use Sodaho\Container\Exception\CacheException;

/**
 * Unit tests for ContainerCache - isolated cache functionality tests.
 */
class ContainerCacheTest extends TestCase
{
    private string $cacheFile;
    private string $signatureKey = 'test-secret-key';

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/container_cache_test_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    // ==================== Security: Signature Key Required ====================

    public function testSignatureKeyRequiredWhenEnabled(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new ContainerCache($this->cacheFile, null, true);
    }

    public function testSignatureKeyNotRequiredWhenDisabled(): void
    {
        $cache = new ContainerCache($this->cacheFile, null, false);

        $this->assertFalse($cache->exists());
    }

    public function testSignatureKeyRequiredExceptionHasDebugMessage(): void
    {
        try {
            new ContainerCache($this->cacheFile, null, true);
            $this->fail('Expected CacheException');
        } catch (CacheException $e) {
            $this->assertNotNull($e->getDebugMessage());
            $this->assertStringContainsString('RCE', $e->getDebugMessage());
            $this->assertStringContainsString('CONTAINER_CACHE_KEY', $e->getDebugMessage());
        }
    }

    // ==================== Basic Save/Load ====================

    public function testSaveAndLoad(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $data = [
            'TestClass' => [
                'class' => 'TestClass',
                'dependencies' => ['DepA', 'DepB'],
                'defaults' => [],
            ],
        ];

        $cache->save($data);
        $loaded = $cache->load();

        $this->assertEquals($data, $loaded);
    }

    public function testSaveCreatesDirectory(): void
    {
        $deepPath = sys_get_temp_dir() . '/container_test_' . uniqid() . '/deep/path/cache.php';
        $cache = new ContainerCache($deepPath, $this->signatureKey);

        $cache->save(['test' => []]);

        $this->assertFileExists($deepPath);

        // Cleanup
        unlink($deepPath);
        rmdir(dirname($deepPath));
        rmdir(dirname(dirname($deepPath)));
        rmdir(dirname(dirname(dirname($deepPath))));
    }

    // ==================== Disabled Cache ====================

    public function testSaveDisabledDoesNothing(): void
    {
        $cache = new ContainerCache($this->cacheFile, null, false);

        $cache->save(['test' => 'data']);

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testLoadDisabledReturnsNull(): void
    {
        $cache = new ContainerCache($this->cacheFile, null, false);

        $result = $cache->load();

        $this->assertNull($result);
    }

    public function testExistsReturnsFalseWhenDisabled(): void
    {
        $cache = new ContainerCache($this->cacheFile, null, false);

        // Even if file exists, disabled cache returns false
        file_put_contents($this->cacheFile, '<?php return [];');

        $this->assertFalse($cache->exists());
    }

    // ==================== Non-Existent/Corrupted Files ====================

    public function testLoadNonExistentFileReturnsNull(): void
    {
        $cache = new ContainerCache('/non/existent/file.php', $this->signatureKey);

        $result = $cache->load();

        $this->assertNull($result);
    }

    public function testLoadCorruptedCacheReturnsNull(): void
    {
        // Write invalid PHP (returns non-array) - but with valid signature format
        file_put_contents($this->cacheFile, "<?php\n// HMAC-SHA256: " . str_repeat('a', 64) . "\nreturn \"not an array\";");

        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        // Will fail signature check, throwing exception
        $this->expectException(CacheException::class);
        $cache->load();
    }

    // ==================== Signature Verification ====================

    public function testSaveWithSignatureAddsHmacHeader(): void
    {
        $cache = new ContainerCache($this->cacheFile, 'secret-key');

        $data = ['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]];
        $cache->save($data);

        $content = file_get_contents($this->cacheFile);
        $this->assertStringContainsString('HMAC-SHA256:', $content);
    }

    public function testLoadWithValidSignature(): void
    {
        $signature = 'my-secret';
        $cache = new ContainerCache($this->cacheFile, $signature);

        $data = ['Test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]];
        $cache->save($data);

        // Load with same signature
        $cache2 = new ContainerCache($this->cacheFile, $signature);
        $loaded = $cache2->load();

        $this->assertEquals($data, $loaded);
    }

    public function testLoadWithInvalidSignatureThrows(): void
    {
        $cache1 = new ContainerCache($this->cacheFile, 'key1');
        $cache1->save(['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]]);

        // Try to load with different key
        $cache2 = new ContainerCache($this->cacheFile, 'key2');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature');

        $cache2->load();
    }

    public function testLoadWithSignatureButMalformedContentThrows(): void
    {
        // Write file with signature but no return statement
        file_put_contents($this->cacheFile, "<?php\n// HMAC-SHA256: " . str_repeat('a', 64) . "\necho 'no return';");

        $cache = new ContainerCache($this->cacheFile, 'some-key');

        $this->expectException(CacheException::class);
        $cache->load();
    }

    // ==================== Clear/Exists ====================

    public function testClear(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);
        $cache->save(['test' => []]);

        $this->assertFileExists($this->cacheFile);

        $result = $cache->clear();

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testClearNonExistentReturnsFalse(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $result = $cache->clear();

        $this->assertFalse($result);
    }

    public function testExists(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $this->assertFalse($cache->exists());

        $cache->save(['test' => []]);

        $this->assertTrue($cache->exists());
    }

    // ==================== Edge Cases ====================

    public function testLoadWithoutHmacHeaderThrows(): void
    {
        // Write valid PHP but without HMAC header when signature key is set
        file_put_contents($this->cacheFile, "<?php\nreturn ['test' => []];");

        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature');

        $cache->load();
    }

    public function testLoadReturnsNullWhenRequireReturnsNonArray(): void
    {
        // Create a file with valid signature but returns non-array
        // First, we need to create it with the correct signature
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);
        $cache->save(['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]]);

        // Now manually corrupt the file content but keep the signature line
        // This simulates a corrupted but loadable file
        $content = file_get_contents($this->cacheFile);
        // Replace the return array with return null (keeping signature)
        $content = preg_replace('/return array \(.*\);/s', 'return null;', $content);
        file_put_contents($this->cacheFile, $content);

        // Loading should throw because signature won't match
        $this->expectException(CacheException::class);
        $cache->load();
    }

    public function testLoadReturnsNullWhenRequireThrows(): void
    {
        // Create file with valid signature that throws when executed
        // The signature is computed on the part after "return "
        $throwCode = "(function() { throw new \\Exception('fail'); })()";
        $signature = hash_hmac('sha256', $throwCode, $this->signatureKey);
        $content = "<?php\n// HMAC-SHA256: {$signature}\nreturn {$throwCode};";
        file_put_contents($this->cacheFile, $content);

        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        // The catch block returns null
        $result = $cache->load();

        $this->assertNull($result);
    }

    // ==================== Filesystem Error Tests (vfsStream) ====================

    public function testSaveThrowsWhenDirectoryNotCreatable(): void
    {
        $root = vfsStream::setup('cache', 0o444); // Read-only root
        $cacheFile = vfsStream::url('cache/subdir/container.php');

        $cache = new ContainerCache($cacheFile, $this->signatureKey);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('not writable');

        $cache->save(['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]]);
    }

    public function testSaveThrowsWhenFileNotWritable(): void
    {
        $root = vfsStream::setup('cache', 0o755);
        // Create directory but make it read-only after
        $dir = vfsStream::newDirectory('subdir', 0o444)->at($root);
        $cacheFile = vfsStream::url('cache/subdir/container.php');

        $cache = new ContainerCache($cacheFile, $this->signatureKey);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to write');

        // Suppress the PHP warning that file_put_contents emits
        @$cache->save(['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]]);
    }

    public function testLoadReturnsNullWhenDataIsNotArray(): void
    {
        // Create file with valid signature but returns non-array (null)
        $returnValue = 'null';
        $signature = hash_hmac('sha256', $returnValue, $this->signatureKey);
        $content = "<?php\n// HMAC-SHA256: {$signature}\nreturn {$returnValue};";
        file_put_contents($this->cacheFile, $content);

        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $result = $cache->load();

        $this->assertNull($result);
    }

    /**
     * Note: Testing rename() failure is not reliably possible with vfsStream.
     * The rename failure path (line 85-88) is covered by @codeCoverageIgnore
     * as it requires filesystem-level failures that can't be simulated.
     */
}
