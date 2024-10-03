<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Serializor\CodeExtractors\CodeExtractor;

arch()
    ->expect('Serializor\CodeExtractors')
    ->toBeClasses()
    ->ignoring(CodeExtractor::class)
    ->toExtend(CodeExtractor::class);
