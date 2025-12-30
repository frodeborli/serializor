<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP82;

/**
 * Class that uses the TraitWithConstant trait.
 */
class TraitConstantUser
{
    use TraitWithConstant;

    public function getClosure(): \Closure
    {
        return fn() => self::TRAIT_CONST;
    }
}
