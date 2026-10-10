<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * Ids in exception messages have their control characters escaped: an id with a line break cannot add a line
 * to a log that writes the message. The error hook gets the id as it is.
 */
class ControlCharacterTest extends TestCase
{
    public function testControlCharactersInAnIdAreEscapedInTheMessage(): void
    {
        $container = new Container();
        $ids = [];
        $container->on('error', function (array $data) use (&$ids) {
            $ids[] = $data['id'];
        });

        try {
            $container->get("Foo\nERROR forged-line\t\x1B[31m\x7F");
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame('Class or service \'Foo\x0AERROR forged-line\x09\x1B[31m\x7F\' not found.', $e->getMessage());
        }

        $this->assertSame(["Foo\nERROR forged-line\t\x1B[31m\x7F"], $ids);
    }

    public function testEveryMessageNamingAnIdEscapesIt(): void
    {
        $container = new Container();
        $container->set("a\nb", fn (Container $c) => $c->get("a\nb"));

        try {
            $container->get("a\nb");
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame('Error while creating service \'a\x0Ab\'.', $e->getMessage());
            $this->assertSame('Circular dependency detected: a\x0Ab -> a\x0Ab', $e->getPrevious()?->getMessage());
        }

        $container->set("c\rd", fn () => null);
        $container->get("c\rd");
        try {
            $container->set("c\rd", fn () => null);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame('Cannot redefine \'c\x0Dd\': the entry has been created.', $e->getMessage());
        }

        try {
            $container->on("res\nolve", fn () => null);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame('Unknown event \'res\x0Aolve\'. Available: resolve, error.', $e->getMessage());
        }
    }

    public function testBackslashesOfClassNamesStay(): void
    {
        try {
            new Container()->get('App\Missing\Service');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Class or service 'App\\Missing\\Service' not found.", $e->getMessage());
        }
    }
}
