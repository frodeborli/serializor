<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class ModelAttribute
{
    // ..
}
