<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Serializor\Transformers\Transformer;

arch()
    ->expect('Serializor\Transformers')
    ->toBeClasses()
    ->ignoring(Transformer::class)
    ->toExtend(Transformer::class);
