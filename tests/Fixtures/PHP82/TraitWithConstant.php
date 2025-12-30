<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP82;

/**
 * Trait with constant (PHP 8.2 feature).
 */
trait TraitWithConstant
{
    public const TRAIT_CONST = 'trait_value';
}
