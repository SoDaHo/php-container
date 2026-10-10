<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit\Fixtures\LateLoaded;

// Loaded only by the autoloader of TypeNameTest, and only under its declared name (the file name is not the class name)
class LateConsumer
{
    // @phpstan-ignore interface.nameCase, interface.nameCase (written in another case on purpose: parameter and property)
    public function __construct(public ?lateinterface $late = null)
    {
    }
}
