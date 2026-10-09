<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Promises the package makes outside of the code: what composer.json declares.
 */
class PackageTest extends TestCase
{
    public function testPackageProvidesAPsr11Implementation(): void
    {
        // Packages that require psr/container-implementation can only be installed next to one that provides it
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);

        $this->assertSame(['psr/container-implementation' => '2.0'], $composer['provide'] ?? null);
        $this->assertSame('^2.0', $composer['require']['psr/container'] ?? null, 'The version provided is the one implemented');
    }
}
