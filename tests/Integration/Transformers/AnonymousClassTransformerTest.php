<?php

declare(strict_types=1);

namespace Tests\Integration\Transformers {

    use Serializor\CodeExtractors\AnonymousClassCodeExtractor;
    use Serializor\Transformers\AnonymousClassTransformer;

    class NamespacedClass {}
    interface NamespacedInterface {}

    function createAnonymousClass(): object
    {
        return new class extends NamespacedClass implements NamespacedInterface {
            public function method(): NamespacedClass
            {
                return new NamespacedClass();
            }
        };
    }

    covers(AnonymousClassTransformer::class);

    test('transforms and resolves class in same namespace', function (): void {
        $input = createAnonymousClass();
        $transformer = new AnonymousClassTransformer(new AnonymousClassCodeExtractor());

        $actual = $transformer->resolve($transformer->transform($input));

        expect($actual)->toBeInstanceOf(NamespacedClass::class);
        expect($actual)->toBeInstanceOf(NamespacedInterface::class);
        expect($actual->method())->toBeInstanceOf(NamespacedClass::class);
    });
}

namespace Tests\Integration\Transformers\DifferentNamespace {

    use Serializor\CodeExtractors\AnonymousClassCodeExtractor;
    use Serializor\Transformers\AnonymousClassTransformer;
    use Tests\Integration\Transformers\NamespacedClass as NamespacedClassFromAnotherNamespace;
    use Tests\Integration\Transformers\NamespacedInterface as NamespacedInterfaceFromAnotherNamespace;

    use function Tests\Integration\Transformers\createAnonymousClass;

    class NamespacedClass {}
    interface NamespacedInterface {}

    covers(AnonymousClassTransformer::class);

    test('transforms and resolves class in different namespace', function (): void {
        $input = createAnonymousClass();
        $transformer = new AnonymousClassTransformer(new AnonymousClassCodeExtractor());

        $actual = $transformer->resolve($transformer->transform($input));

        expect($actual)->toBeInstanceOf(NamespacedClassFromAnotherNamespace::class);
        expect($actual)->toBeInstanceOf(NamespacedInterfaceFromAnotherNamespace::class);
        expect($actual->method())->toBeInstanceOf(NamespacedClassFromAnotherNamespace::class);
        expect($actual)->not()->toBeInstanceOf(NamespacedClass::class);
        expect($actual)->not()->toBeInstanceOf(NamespacedInterface::class);
        expect($actual->method())->not()->toBeInstanceOf(NamespacedClass::class);
    });
}
