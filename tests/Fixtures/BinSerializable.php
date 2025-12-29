<?php
namespace Tests\Fixtures;

use Serializor\Struct\char;
use Serializor\Struct\float32;
use Serializor\Struct\float64;
use Serializor\Struct\int16;
use Serializor\Struct\int32;
use Serializor\Struct\int64;
use Serializor\Struct\int8;
use Serializor\Struct\uint16;
use Serializor\Struct\uint32;
use Serializor\Struct\uint64;
use Serializor\Struct\uint8;

class BinSerializable {
    #[int8] public int $int8;
    #[int16] public int $int16;
    #[int32] public int $int32;
    #[int64] public int $int64;
    #[uint8] public int $uint8;
    #[uint16] public int $uint16;
    #[uint32] public int $uint32;
    #[uint64] public int $uint64;
    #[float32] public float $float32;
    #[float64] public float $float64;    
    #[char(128, "\0")] public string $someStringData;
}