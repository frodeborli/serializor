<?php
namespace Serializor\Struct;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class char {

    public function __construct(public int $length, public string $padChar = "\0")
    {}

}
