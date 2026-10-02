<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * A cache file as the library writes it, for an arbitrary payload.
     */
    private function signed(string $payload, ?string $key = null, string $context = "sodaho/container cache v2\n"): string
    {
        return "<?php __halt_compiler(); ?>\nHMAC-SHA256: " . hash_hmac('sha256', $context . $payload, $key ?? $this->signatureKey) . "\n" . $payload;
    }

    /**
     * A cache file exactly as 1.0.x wrote it (executable PHP, signature over the export only).
     *
     * @param array<mixed> $data
     */
    private function legacy(array $data, string $injectedCode = ''): string
    {
        $export = var_export($data, true);

        return "<?php\n// HMAC-SHA256: " . hash_hmac('sha256', $export, $this->signatureKey) . "\n{$injectedCode}return {$export};";
    }

    // ==================== Security: Signature Key Required ====================

    public function testSignatureKeyRequiredWhenEnabled(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new ContainerCache($this->cacheFile, null, true);
    }

    public function testEmptySignatureKeyIsRejected(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new ContainerCache($this->cacheFile, '', true);
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
                'dependencies' => ['DepA', null, 'DepB'],
                'defaults' => [1 => 'fallback', 2 => null],
                'optional' => [2 => true],
            ],
        ];

        $cache->save($data);

        $this->assertSame($data, (new ContainerCache($this->cacheFile, $this->signatureKey))->load());
    }

    public function testLoadRestoresDefaultValuesExactly(): void
    {
        $defaults = [1.0, 0, '0', false, null, "\x00\xff binary", ['nested' => [1, 'a' => 2.5]], PHP_INT_MAX, ''];
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $cache->save(['T' => ['defaults' => $defaults]]);

        $this->assertSame(['T' => ['defaults' => $defaults]], $cache->load());
    }

    public function testEnumCasesSurviveTheRoundTrip(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $cache->save(['T' => ['defaults' => [Fixtures\Mode::Fast]]]);

        $this->assertSame(['T' => ['defaults' => [Fixtures\Mode::Fast]]], $cache->load());
    }

    public function testEnumCaseThatNoLongerExistsIsAMiss(): void
    {
        $payload = str_replace('Mode:Fast', 'Mode:Gone', serialize(['T' => ['defaults' => [Fixtures\Mode::Fast]]]));
        file_put_contents($this->cacheFile, $this->signed($payload));

        $this->assertNull((new ContainerCache($this->cacheFile, $this->signatureKey))->load());
    }

    public function testSaveCreatesDirectory(): void
    {
        $deepPath = sys_get_temp_dir() . '/container_test_' . uniqid() . '/deep/path/cache.php';
        $cache = new ContainerCache($deepPath, $this->signatureKey);

        $cache->save(['test' => []]);

        $this->assertFileExists($deepPath);
        $this->assertSame(['cache.php'], array_values(array_diff((array) scandir(dirname($deepPath)), ['.', '..'])), 'No temp file left behind');

        // Cleanup
        unlink($deepPath);
        rmdir(dirname($deepPath));
        rmdir(dirname(dirname($deepPath)));
        rmdir(dirname(dirname(dirname($deepPath))));
    }

    public function testSaveReplacesExistingFile(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $cache->save(['first' => []]);
        $cache->save(['second' => []]);

        $this->assertSame(['second' => []], $cache->load());
    }

    // ==================== File Format: Data Only ====================

    public function testFileIsGuardLineSignatureLineAndSerializedPayload(): void
    {
        $data = ['Test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => [], 'optional' => []]];

        (new ContainerCache($this->cacheFile, $this->signatureKey))->save($data);

        $this->assertSame($this->signed(serialize($data)), file_get_contents($this->cacheFile));
    }

    public function testFileOutputsNothingWhenExecutedAsPhp(): void
    {
        $marker = $this->cacheFile . '.executed';
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save(
            ['Test' => ['defaults' => ['<?php touch(' . var_export($marker, true) . '); echo "leak";']]]
        );

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->cacheFile) . ' 2>&1', $output, $exitCode);

        $this->assertSame([], $output);
        $this->assertSame(0, $exitCode);
        $this->assertFileDoesNotExist($marker);
    }

    public function testSaveRejectsObjects(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        try {
            $cache->save(['T' => ['defaults' => [0 => ['deep' => new \stdClass()]]]]);
            $this->fail('Expected CacheException');
        } catch (CacheException $e) {
            $this->assertSame('Cache data is not cacheable', $e->getMessage());
            $this->assertNotNull($e->getDebugMessage());
        }

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function cacheableValues(): array
    {
        return [
            'null' => [null, true],
            'int' => [1, true],
            'float' => [1.5, true],
            'string' => ['a', true],
            'bool' => [false, true],
            'empty array' => [[], true],
            'nested array' => [['a' => [1, [null, 'x']]], true],
            'object' => [new \stdClass(), false],
            'enum case' => [Fixtures\Mode::Fast, true],
            'enum case nested in array' => [['a' => [Fixtures\Mode::Fast]], true],
            'closure' => [static fn () => null, false],
            'object nested in array' => [['a' => [1, [new \stdClass()]]], false],
            'object after plain values' => [[1, 'a', new \stdClass()], false],
        ];
    }

    #[DataProvider('cacheableValues')]
    public function testIsCacheable(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, ContainerCache::isCacheable($value));
    }

    public function testLoadDoesNotInstantiateObjectsFromPayload(): void
    {
        // Only possible for someone who knows the key; the payload still must not create objects
        file_put_contents($this->cacheFile, $this->signed(serialize(['T' => new \ArrayObject([1])])));

        $loaded = (new ContainerCache($this->cacheFile, $this->signatureKey))->load();

        $this->assertIsArray($loaded);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $loaded['T']);
    }

    // ==================== Disabled Cache ====================

    public function testSaveDisabledDoesNothing(): void
    {
        $cache = new ContainerCache($this->cacheFile, null, false);

        $cache->save(['test' => 'data']);

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testLoadDisabledReturnsNullEvenForValidFile(): void
    {
        (new ContainerCache($this->cacheFile, $this->signatureKey))->save(['test' => []]);

        $this->assertNull((new ContainerCache($this->cacheFile, $this->signatureKey, false))->load());
    }

    public function testLoadDisabledIgnoresTamperedFile(): void
    {
        file_put_contents($this->cacheFile, 'garbage');

        $this->assertNull((new ContainerCache($this->cacheFile, null, false))->load());
    }

    public function testExistsReturnsFalseWhenDisabled(): void
    {
        $cache = new ContainerCache($this->cacheFile, null, false);

        // Even if file exists, disabled cache returns false
        file_put_contents($this->cacheFile, 'x');

        $this->assertFalse($cache->exists());
    }

    // ==================== Non-Existent Files ====================

    public function testLoadNonExistentFileReturnsNull(): void
    {
        $cache = new ContainerCache('/non/existent/file.php', $this->signatureKey);

        $this->assertNull($cache->load());
    }

    public function testLoadDirectoryReturnsNull(): void
    {
        $cache = new ContainerCache(sys_get_temp_dir(), $this->signatureKey);

        $this->assertNull($cache->load());
    }

    // ==================== Signature Verification ====================

    public function testLoadWithInvalidSignatureThrows(): void
    {
        $cache1 = new ContainerCache($this->cacheFile, 'key1');
        $cache1->save(['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]]);

        // Try to load with different key
        $cache2 = new ContainerCache($this->cacheFile, 'key2');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Cache file signature is invalid');

        $cache2->load();
    }

    /**
     * @return array<string, array{callable(string, string): string}> content and injected PHP code in, tampered content out
     */
    public static function tamperings(): array
    {
        $payloadStart = strlen("<?php __halt_compiler(); ?>\nHMAC-SHA256: ") + 64 + 1;

        return [
            'payload byte changed' => [static fn (string $c): string => substr($c, 0, -2) . 'X' . substr($c, -1)],
            'byte appended' => [static fn (string $c): string => $c . ' '],
            'newline appended' => [static fn (string $c): string => $c . "\n"],
            'payload truncated' => [static fn (string $c): string => substr($c, 0, -1)],
            'byte inserted before payload' => [static fn (string $c): string => substr($c, 0, $payloadStart) . ' ' . substr($c, $payloadStart)],
            'code inserted before payload' => [static fn (string $c, string $code): string => substr($c, 0, $payloadStart) . $code . substr($c, $payloadStart)],
            'code appended' => [static fn (string $c, string $code): string => $c . $code],
            'payload removed' => [static fn (string $c): string => substr($c, 0, $payloadStart)],
            'signature digit changed' => [static fn (string $c): string => substr_replace($c, $c[$payloadStart - 2] === '0' ? '1' : '0', $payloadStart - 2, 1)],
            'signature in upper case' => [static fn (string $c): string => substr($c, 0, $payloadStart - 65) . strtoupper(substr($c, $payloadStart - 65, 64)) . substr($c, $payloadStart - 1)],
            'signature line without newline' => [static fn (string $c): string => substr_replace($c, ' ', $payloadStart - 1, 1)],
            // Same length as the guard line, so the payload offset stays where it was
            'guard line replaced' => [static fn (string $c): string => '<?php                    ?>' . substr($c, 27)],
            'guard line replaced by code' => [static fn (string $c, string $code): string => $code . substr($c, strpos($c, "\n"))],
            'signature label changed' => [static fn (string $c): string => str_replace('HMAC-SHA256: ', 'hmac-sha256: ', $c)],
            'code before guard line' => [static fn (string $c, string $code): string => $code . $c],
            'file cut inside signature' => [static fn (string $c): string => substr($c, 0, $payloadStart - 10)],
            'empty file' => [static fn (string $c): string => ''],
            'plain php' => [static fn (string $c, string $code): string => $code . "<?php\nreturn ['test' => []];"],
        ];
    }

    /**
     * @param callable(string, string): string $tamper
     */
    #[DataProvider('tamperings')]
    public function testLoadThrowsForTamperedFileAndRunsNothing(callable $tamper): void
    {
        $marker = $this->cacheFile . '.executed';
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);
        $cache->save(['Test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => ['abc'], 'optional' => []]]);
        $original = (string) file_get_contents($this->cacheFile);
        $tampered = $tamper($original, '<?php touch(' . var_export($marker, true) . '); ?>');
        $this->assertNotSame($original, $tampered);
        file_put_contents($this->cacheFile, $tampered);

        try {
            $cache->load();
            $this->fail('Expected CacheException');
        } catch (CacheException $e) {
            $this->assertSame('Cache file signature is invalid', $e->getMessage());
        }

        $this->assertFileDoesNotExist($marker);
    }

    public function testSignatureIsBoundToThisLibrary(): void
    {
        $payload = serialize(['Test' => []]);

        // Same key, signed without context or for the route cache of sodaho/php-router
        foreach (['', "sodaho/php-router route-cache v2\n"] as $foreignContext) {
            file_put_contents($this->cacheFile, $this->signed($payload, null, $foreignContext));

            try {
                (new ContainerCache($this->cacheFile, $this->signatureKey))->load();
                $this->fail('Expected CacheException');
            } catch (CacheException $e) {
                $this->assertSame('Cache file signature is invalid', $e->getMessage());
            }
        }

        file_put_contents($this->cacheFile, $this->signed($payload));
        $this->assertSame(['Test' => []], (new ContainerCache($this->cacheFile, $this->signatureKey))->load());
    }

    public function testLoadReturnsNullWhenSignedPayloadIsNotAnArray(): void
    {
        file_put_contents($this->cacheFile, $this->signed(serialize('not an array')));

        $this->assertNull((new ContainerCache($this->cacheFile, $this->signatureKey))->load());
    }

    public function testLoadReturnsNullWhenSignedPayloadIsNotSerializedData(): void
    {
        file_put_contents($this->cacheFile, $this->signed('garbage'));

        $this->assertNull((new ContainerCache($this->cacheFile, $this->signatureKey))->load());
    }

    // ==================== Files Written by 1.0.x ====================

    public function testLegacyFileIsIgnoredAndNeverExecuted(): void
    {
        $marker = $this->cacheFile . '.executed';
        $data = ['Test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]];
        // The 1.0.x signature covered only the export: this file verified there and ran the injected line
        file_put_contents($this->cacheFile, $this->legacy($data, 'touch(' . var_export($marker, true) . ");\n"));

        $loaded = (new ContainerCache($this->cacheFile, $this->signatureKey))->load();

        $this->assertNull($loaded);
        $this->assertFileDoesNotExist($marker);
    }

    public function testLegacyFileIsReplacedOnSave(): void
    {
        file_put_contents($this->cacheFile, $this->legacy(['Old' => []]));
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $cache->save(['New' => []]);

        $this->assertSame(['New' => []], $cache->load());
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

    public function testClearReturnsFalseWithoutWarningWhenThePathCannotBeDeleted(): void
    {
        // unlink() fails on a directory, whoever runs the tests
        $directory = sys_get_temp_dir() . '/container_cache_dir_' . uniqid();
        mkdir($directory);

        try {
            $cache = new ContainerCache($directory, $this->signatureKey);

            $this->assertFalse($cache->clear());
            $this->assertTrue($cache->exists());
        } finally {
            rmdir($directory);
        }
    }

    public function testExists(): void
    {
        $cache = new ContainerCache($this->cacheFile, $this->signatureKey);

        $this->assertFalse($cache->exists());

        $cache->save(['test' => []]);

        $this->assertTrue($cache->exists());
    }

    // ==================== Filesystem Error Tests (vfsStream) ====================

    public function testSaveThrowsWhenDirectoryNotCreatable(): void
    {
        vfsStream::setup('cache', 0o444); // Read-only root
        $cacheFile = vfsStream::url('cache/subdir/container.php');

        $cache = new ContainerCache($cacheFile, $this->signatureKey);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('not writable');

        // No error suppression here: the library must not emit a PHP warning on top of its exception
        $cache->save(['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]]);
    }

    public function testSaveThrowsWhenFileNotWritable(): void
    {
        $root = vfsStream::setup('cache', 0o755);
        // Create directory but make it read-only after
        vfsStream::newDirectory('subdir', 0o444)->at($root);
        $cacheFile = vfsStream::url('cache/subdir/container.php');

        $cache = new ContainerCache($cacheFile, $this->signatureKey);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to write');

        $cache->save(['test' => ['class' => 'Test', 'dependencies' => [], 'defaults' => []]]);
    }

    /**
     * Note: Testing rename() failure is not reliably possible with vfsStream.
     * That path is covered by @codeCoverageIgnore
     * as it requires filesystem-level failures that can't be simulated.
     */
}
