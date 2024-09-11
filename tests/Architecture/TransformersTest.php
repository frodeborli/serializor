<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Serializor\TransformerInterface;

arch()
    ->expect('Serializor\Transformers')
    ->toBeClasses()
    ->toExtend(TransformerInterface::class);
