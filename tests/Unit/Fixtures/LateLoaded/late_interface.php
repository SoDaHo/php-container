<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit\Fixtures\LateLoaded;

// Loaded only by the autoloader of TypeNameTest, and only under its declared name (the file name is not the class name)
interface LateInterface
{
}
