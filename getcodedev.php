<?php

namespace Frode\Pode;

require 'vendor/autoload.php';


use Serializor\Reflect;
use Serializor\Transformers\ClosureTransformer;

use function SomeNamespace\someFunc as whatever;

function some_func() {

}

function test() {
    whatever();
}

$code = ClosureTransformer::getCode(Reflect::getReflectionFunction(test(...)));

echo $code;
