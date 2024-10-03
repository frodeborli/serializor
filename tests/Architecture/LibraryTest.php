<?php

declare(strict_types=1);

namespace Tests\Architecture;

arch()
    ->preset()->php()
    ->ignoring('var_export');

arch()
    ->preset()->security()
    ->ignoring('mt_rand')
    ->ignoring('unserialize');

arch()
    ->preset()->strict();
