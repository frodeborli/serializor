<?php

declare(strict_types=1);

namespace Tests\Integration\Transformers {

    use Closure;
    use ReflectionClass;
    use Serializor\CodeExtractors\ClosureCodeExtractor;
    use Serializor\Transformers\ClosureTransformer;
    use Tests\Fixtures\RegularClass as NamespacedClass;
    use WeakMap;

    covers(ClosureTransformer::class);

    function createClosure(): Closure
    {
        return static function (): NamespacedClass {
            return new NamespacedClass();
        };
    }

    test('`\Closure` returning a class instance called `Closure`', function (): void {
        $expected = fn(): \Tests\Fixtures\Closure => new \Tests\Fixtures\Closure();

        expect($expected)->toEqualAfterSerializeAndUnserialize();
    });

    test('transforms and resolves closure in same namespace', function (): void {
        $input = createClosure();
        $transformer = new ClosureTransformer(new ClosureCodeExtractor());

        $stasis = $transformer->transform($input);
        (new ReflectionClass($transformer))->setStaticPropertyValue('transformedObjects', new WeakMap());
        $actual = $transformer->resolve($stasis);

        expect($actual())->toBeInstanceOf(NamespacedClass::class);
    });
}

namespace Tests\Integration\Transformers\DifferentNamespace {

    use ReflectionClass;
    use Serializor\CodeExtractors\ClosureCodeExtractor;
    use Serializor\Transformers\ClosureTransformer;
    use Tests\Fixtures\RegularClass as NamespacedClassFromAnotherNamespace;
    use Tests\Integration\Transformers\DifferentNamespace\NamespacedClassForClosure as NamespacedClass;
    use WeakMap;

    covers(ClosureTransformer::class);

    use function Tests\Integration\Transformers\createClosure;

    class NamespacedClassForClosure {}

    test('transforms and resolves closure in different namespace', function (): void {
        $input = createClosure();
        $transformer = new ClosureTransformer(new ClosureCodeExtractor());

        $stasis = $transformer->transform($input);
        (new ReflectionClass($transformer))->setStaticPropertyValue('transformedObjects', new WeakMap());
        $actual = $transformer->resolve($stasis);

        expect($actual())->toBeInstanceOf(NamespacedClassFromAnotherNamespace::class);
        expect($actual())->not()->toBeInstanceOf(NamespacedClass::class);
    });
}
