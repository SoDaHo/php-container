<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * What exception messages carry: getMessage() names ids only, getDebugMessage() the wrapped causes.
 */
class MessageTest extends TestCase
{
    public function testMessageOfAWrappedExceptionStaysOutOfGetMessage(): void
    {
        $container = new Container();
        $container->set('broken', fn () => throw new \LogicException('mysql://app:secret@db/app refused'));
        $messages = [
            'broken' => "Error while creating service 'broken'.",
            Fixtures\ServiceThrowsInConstructor::class => "Failed to instantiate '" . Fixtures\ServiceThrowsInConstructor::class . "'.",
        ];

        foreach ($messages as $id => $message) {
            try {
                $container->get($id);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $previous = $e->getPrevious();
                $this->assertNotNull($previous);
                $this->assertSame($message, $e->getMessage());
                $this->assertSame(
                    $previous::class . ' in ' . $previous->getFile() . ':' . $previous->getLine() . ': ' . $previous->getMessage(),
                    $e->getDebugMessage()
                );
            }
        }
    }

    public function testDebugMessageNamesEveryCauseInOrder(): void
    {
        $container = new Container();
        $container->set('outer', fn (Container $c) => $c->get('inner'));
        $container->set('inner', fn () => throw new \RuntimeException('Oops', 0, new \LogicException('Root cause')));

        try {
            $container->get('outer');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $inner = $e->getPrevious();
            $this->assertInstanceOf(ContainerException::class, $inner);
            $thrown = $inner->getPrevious();
            $this->assertInstanceOf(\RuntimeException::class, $thrown);
            $root = $thrown->getPrevious();
            $this->assertInstanceOf(\LogicException::class, $root);

            $this->assertSame(
                ContainerException::class . ' in ' . $inner->getFile() . ':' . $inner->getLine() . ": Error while creating service 'inner'."
                . ' <- RuntimeException in ' . $thrown->getFile() . ':' . $thrown->getLine() . ': Oops'
                . ' <- LogicException in ' . $root->getFile() . ':' . $root->getLine() . ': Root cause',
                $e->getDebugMessage()
            );
            $this->assertSame(
                'RuntimeException in ' . $thrown->getFile() . ':' . $thrown->getLine() . ': Oops'
                . ' <- LogicException in ' . $root->getFile() . ':' . $root->getLine() . ': Root cause',
                $inner->getDebugMessage()
            );
        }
    }

    public function testExceptionsTheContainerRaisesItselfHaveNoDebugMessage(): void
    {
        $container = new Container();

        foreach (['Missing\Service', Fixtures\AbstractService::class, Fixtures\CircularA::class, Fixtures\ControllerWithInterface::class] as $id) {
            try {
                $container->get($id);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $this->assertNull($e->getDebugMessage());
            }
        }
    }
}
