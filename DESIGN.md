# Design Notes: Closure Serialization Architecture

This document describes the architectural decisions in Serializor.

## Background

In 2024, Opis/Closure v4 was being developed as a complete rewrite using PHP's FFI (Foreign Function Interface) extension. This posed a problem for web applications: FFI is not enabled by default in PHP web requests for security reasons.

Laravel recognized this issue and [forked Opis v3](https://github.com/laravel/serializable-closure):

> *"This project is a fork of the excellent opis/closure: 3.x package. At Laravel, we decided to fork this package as the upcoming version 4.x is a complete rewrite on top of the FFI extension. As Laravel is a web framework, and FFI is not enabled by default in web requests, this fork allows us to keep using the 3.x series while adding support for new PHP versions."*

Rather than forking v3, Serializor was created as a new native PHP implementation that solved the underlying problems differently.

## Timeline

| Date | Event |
|------|-------|
| January 27, 2023 | Opis/Closure v3.6.3 released |
| 2024 | Opis v4 development begins (FFI-based) |
| 2024 | Laravel forks Opis v3 due to FFI concerns |
| **September 5, 2024** | **Serializor v1.0.0 released** (native PHP) |
| December 28, 2024 | Opis/Closure v4.0.0 released (native PHP, no FFI) |
| January 7, 2025 | Opis v4.2.0 adds anonymous class support |

Notably, when Opis v4 was finally released, it no longer used FFI—it was rewritten in native PHP with an architecture similar to Serializor.

## Core Design Decisions

Serializor was built around several architectural choices that solve fundamental challenges in PHP serialization.

### 1. Direct Serialization API (No Wrapper Classes)

**The problem**: Traditional closure serialization libraries (Opis v3, Laravel) require wrapping closures in a special class before serialization, which breaks when closures are stored in typed/readonly properties.

**Serializor's solution** (Sep 2024):
```php
// Direct serialization - closure can be anywhere in the object graph
$serialized = Serializor::serialize($closure);
$restored = Serializor::unserialize($serialized);
```

**Opis v4's approach** (Dec 2024):
```php
// Same pattern - direct function calls
$serialized = Opis\Closure\serialize($closure);
$restored = Opis\Closure\unserialize($serialized);
```

### 2. Stream Wrapper Protocol (Avoiding eval)

**The problem**: Reconstructing closures from source code traditionally requires `eval()`, which has security implications and prevents opcode caching.

**Solution**: A custom PHP stream wrapper that allows `require()` to load dynamically generated code. This technique was used by Opis v3 and is adopted by both Serializor and Opis v4.

```php
// Serializor: src/ClosureStream.php (serializor:// protocol)
// Opis v3/v4: closure:// protocol
$factory = require('serializor://' . $phpCode);
```

### 3. Cycle Detection via WeakMap + ReflectionReference

**The problem**: Object graphs can contain cycles (A → B → A) and multiple references to the same object. Naive serialization either fails or duplicates objects incorrectly.

**Serializor's solution**: Combine `WeakMap` for object identity with `ReflectionReference` for array/variable reference identity, using an "early registration" pattern.

```php
// Serializor: src/Codec.php
class Codec
{
    private WeakMap $encodedObjects;
    private array $referenceSources = [];
    private array $referenceTargets = [];

    protected function &transform(mixed &$source, ...): mixed
    {
        // Get unique reference ID for this variable
        $sourceWrap = [&$source];
        $referenceId = ReflectionReference::fromArrayElement($sourceWrap, 0)->getId();

        // Already processed? Return existing transformation
        if (isset($this->referenceSources[$referenceId])) {
            return $this->referenceTargets[$referenceId];
        }

        // For objects, also check WeakMap
        if (is_object($source) && isset($this->encodedObjects[$source])) {
            return $this->encodedObjects[$source];
        }

        // KEY INSIGHT: Register placeholder BEFORE recursing
        // This handles cycles - if we encounter this reference again,
        // we'll find the placeholder and return it
        $result = [];
        $this->referenceTargets[$referenceId] = &$result;

        // Now safe to recurse into children
        foreach ($source as $k => &$v) {
            $result[$k] = &$this->transform($source[$k], ...);
        }

        return $result;
    }
}
```

**Opis v4's approach**: Same algorithm with same data structures.

```php
// Opis v4: src/SerializationHandler.php
class SerializationHandler
{
    private ?WeakMap $objectMap;
    private ?array $arrayMap;

    private function handleObject(object $data): object
    {
        if (isset($this->objectMap[$data])) {
            return $this->objectMap[$data];
        }

        $box = new Box(...);

        // Same pattern: register BEFORE recursing
        $this->objectMap[$data] = $box;

        $box->data[1] = $this->getObjectVars($data, $info);
        return $box;
    }

    private function &handleArray(array &$data, ...): array
    {
        $id = ReflectionClass::getRefId($data, ...);  // Uses ReflectionReference

        if (array_key_exists($id, $this->arrayMap)) {
            return $this->arrayMap[$id];
        }

        $box = [];
        $this->arrayMap[$id] = &$box;  // Register before recursing

        foreach ($data as $key => &$value) {
            // ... recurse
        }
        return $box;
    }
}
```

### 4. Tokenization with Scope Tracking

**The problem**: Closures may reference `$this`, `self`, `static`, or `parent`. The serialized closure must know which scope bindings to restore.

**Serializor's solution**: Track these keywords during tokenization.

```php
// Serializor: src/Transformers/ClosureTransformer.php
public static function getCode(ReflectionFunction $rf,
    bool &$usedThis = null,
    bool &$usedStatic = null, ...): string
{
    $tokens = PhpToken::tokenize(file_get_contents($sourceFile));

    foreach ($tokens as $token) {
        // Track $this usage
        if ($token->id === T_VARIABLE && $token->text === '$this') {
            $usedThis = true;
        }

        // Track self/static/parent usage
        if (in_array($token->text, ['self', 'static', 'parent'])) {
            $usedStatic = true;
        }

        // Detect static closures
        if ($token->id === T_STATIC) {
            // Check if followed by function/fn keyword
            // ...
        }
    }
}
```

**Opis v4's approach**: Same tracking with equivalent variables.

```php
// Opis v4: src/ClosureParser.php
final class ClosureParser extends AbstractParser
{
    private bool $isStatic = false;
    private bool $scopeRef = false;   // ≈ Serializor's $usedStatic
    private bool $thisRef = false;    // ≈ Serializor's $usedThis

    private function handleBalanceToken(int $index): void
    {
        $token = $this->tokens[$index];

        if ($token[0] === T_VARIABLE && strcasecmp($token[1], '$this') === 0) {
            $this->thisRef = true;
        }

        if (in_array(strtolower($token[1]), ['self', 'static', 'parent'])) {
            $this->scopeRef = true;
        }
    }
}
```

### 5. Factory-Based Reconstruction

**The problem**: Restored closures need proper variable scope (`use` variables), `$this` binding, and class scope.

**Serializor's solution**: Generate a factory function that uses `extract()` and `Closure::bind()`.

```php
// Serializor generates:
namespace {$namespace} {
    {$useStatements}
    return static function(array &$useVars, ?object $thisObject, ?string $scopeClass): Closure {
        extract($useVars, EXTR_OVERWRITE | EXTR_REFS);
        return Closure::bind({$closureCode}, $thisObject, $scopeClass);
    };
}
```

**Opis v4's approach**: Same factory pattern with `extract()` and `Closure::bind()`.

### 6. Anonymous Class Source Extraction

**The problem**: Anonymous classes can't be serialized natively because they have no reusable class name.

**Serializor's solution**: Extract the class definition source code via tokenization, serialize it, and reconstruct by evaluating the source.

```php
// Serializor: src/Transformers/AnonymousClassTransformer.php
public function transforms(mixed $value): bool
{
    return is_object($value) && str_contains(get_class($value), '@anonymous');
}

public function transform(mixed $value): mixed
{
    $frozen = new Stasis('class@anonymous');
    $frozen->p['|code'] = self::getCode($ro);  // Extracted via tokenization
    $frozen->p['|props'] = Stasis::getObjectProperties($value);
    return $frozen;
}
```

**Opis v4's approach** (added in v4.2, Jan 2025): Same strategy.

```php
// Opis v4
if ($info->isAnonymousLike()) {
    $anonInfo = AnonymousClassParser::parse($info);
    // Extract source, serialize, reconstruct
}
```

### 7. Custom Serializer Registry (Transformers)

**The problem**: Some types require special handling that can't be generalized. Users need to extend serialization for their own types.

**Serializor's solution** (Sep 2024): A transformer interface with `transforms()` and `resolve()` methods, registered globally.

```php
// Serializor: TransformerInterface
interface TransformerInterface {
    public function transforms(mixed $value): bool;
    public function resolves(Stasis $value): bool;
    public function transform(mixed $value): Stasis;
    public function resolve(Stasis $value): mixed;
}

// Registration
Serializor::addTransformer(new MyCustomTransformer());
```

**Opis v4's approach** (Dec 2024): Same pattern with different naming.

```php
// Opis v4: Custom serializers
Opis\Closure\Serializer::addResolver(...);
```

## Architectural Convergence Summary

| Design Decision | Opis v3 | Serializor (Sep 2024) | Opis v4 (Dec 2024) |
|-----------------|---------|----------------------|-------------------|
| Direct serialize API | No (wrapper classes) | ✅ | ✅ |
| Stream wrapper (no eval) | `closure://` | `serializor://` | `closure://` |
| Object identity | ❌ | `WeakMap` | `WeakMap` |
| Reference identity | ❌ | `ReflectionReference::getId()` | `ReflectionReference` |
| Cycle handling | Limited | Early placeholder registration | Early placeholder registration |
| Scope tracking | Basic | `$usedThis`, `$usedStatic` | `$thisRef`, `$scopeRef` |
| Factory reconstruction | `eval()` | `extract()` + `Closure::bind()` | `extract()` + `Closure::bind()` |
| Anonymous classes | ❌ | Source extraction | Source extraction (v4.2) |
| Custom handlers | ❌ | `registerFactory()` | `register()` |

## Why These Decisions Matter

Several of these solutions are non-obvious:

1. **ReflectionReference for identity**: PHP doesn't expose reference identity directly. Using `ReflectionReference::fromArrayElement()` to get a unique ID for variable references is not well-documented.

2. **Early placeholder registration**: The insight that you must register a placeholder *before* recursing (not after) to handle cycles correctly is a common source of bugs in graph serialization.

3. **Scope keyword tracking**: Knowing which keywords (`$this`, `self`, `static`, `parent`) affect closure binding and must be tracked during tokenization requires deep understanding of PHP's scoping rules.

## Prior Art Statement

Serializor was first released on September 5, 2024, implementing the architectural patterns described above. The design decisions represent original solutions developed to address fundamental challenges in PHP closure and object graph serialization.

Similar architectural choices subsequently appeared in Opis/Closure v4.0.0 (December 28, 2024). This document serves to establish the timeline and technical lineage of these approaches.

---

*For performance comparisons between serialization libraries, see [benchmark.php](benchmark.php).*
