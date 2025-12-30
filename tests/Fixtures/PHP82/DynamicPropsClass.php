<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP82;

use AllowDynamicProperties;

/**
 * Class that allows dynamic properties (PHP 8.2+).
 */
#[AllowDynamicProperties]
class DynamicPropsClass
{
    public string $name = 'default';
}
