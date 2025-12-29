<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP85;

/**
 * Test class demonstrating PHP 8.5 pipe operator.
 */
class PipeOperatorClass
{
    public static function getBasicPipeClosure(): \Closure
    {
        return fn(string $input) => $input |> strtoupper(...) |> trim(...);
    }

    public static function getChainedPipeClosure(): \Closure
    {
        return fn(string $input) =>
            $input
            |> trim(...)
            |> strtolower(...)
            |> ucfirst(...);
    }

    public static function getPipeWithCustomFunctions(): \Closure
    {
        $double = fn(int $x) => $x * 2;
        $addTen = fn(int $x) => $x + 10;

        return fn(int $value) => $value |> $double |> $addTen;
    }

    public static function getPipeWithArrayFunctions(): \Closure
    {
        $filterPositive = fn(array $arr) => array_filter($arr, fn($x) => $x > 0);
        $doubleAll = fn(array $arr) => array_map(fn($x) => $x * 2, $arr);
        $sum = fn(array $arr) => array_sum($arr);

        return fn(array $numbers) => $numbers |> $filterPositive |> $doubleAll |> $sum;
    }

    public \Closure $transformer;

    public function __construct()
    {
        $this->transformer = fn(string $s) => $s |> strtoupper(...) |> str_split(...);
    }
}
