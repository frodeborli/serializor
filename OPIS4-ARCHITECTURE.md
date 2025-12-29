# Design Notes: Closure Serialization Architecture

This document describes the structural decisions in Serializor and compares them with other PHP closure serialization libraries.

## Timeline

| Date | Event |
|------|-------|
| January 27, 2023 | Opis/Closure v3.6.3 released |
| **September 5, 2024** | **Serializor v1.0.0 released** |
| December 28, 2024 | Opis/Closure v4.0.0 released ("complete rewrite") |
| January 7, 2025 | Opis v4.2.0 adds anonymous class support |

## Structural Decisions

### 1. Direct Serialization vs Wrapper Classes

**Traditional approach** (Opis v3, Laravel): Require wrapping closures in a special class before serialization.

**Serializor's approach**: Transform the entire object graph, handling closures wherever they appear without requiring wrapper classes. This enables serializing closures stored in typed/readonly properties.

**Opis v4**: Adopted the same direct serialization approach.

### 2. Optimistic Serialization

**Serializor's approach**: Try native PHP serialization first. Only fall back to graph transformation if serialization fails:

```php
public function serialize(mixed &$value): string
{
    try {
        $result = \serialize($value);  // Try native first
    } catch (Throwable $e) {
        // Only transform if native serialization fails
        $result = $this->transform($v, [], null);
        $result = \serialize(new Box($result, $this->shortcuts));
    }
    return $result;
}
```

This means:
- Data without closures serializes at native PHP speed
- No transformation overhead when not needed
- Graceful fallback when encountering non-serializable values

**Opis v4**: Always transforms the entire object graph, regardless of whether it contains closures.

### 3. Reference Identity Tracking

**The problem**: Object graphs can contain cycles and shared references. These must be detected and preserved.

**Previous approach** (Opis v3): `SplObjectStorage` for object identity only.

**Serializor's approach**: Combine `WeakMap` for object identity with `ReflectionReference::getId()` for reference identity. This tracks not just objects, but any PHP reference (including array references).

**Opis v4**: Uses the same `WeakMap` + `ReflectionReference` combination.

### 4. Frozen Object Representation

**Serializor's approach**: The `Stasis` class represents a frozen object state, with a callback system for deferred resolution:

- `Stasis::$p` holds serialized properties
- `Stasis::whenResolved()` registers callbacks for circular reference resolution
- `Stasis::setInstance()` fires callbacks when the object is fully resolved

This callback system enables setting readonly properties even when circular references exist.

**Opis v4**: Uses a `Box` class for frozen representation, but without the callback system. Their approach requires classes to implement `__unserialize()` for readonly property support.

### 5. Pluggable Transformer Architecture

**Serializor's approach**: Interface-based transformers that can be added at runtime:

```php
interface TransformerInterface {
    public function transforms(mixed $value): bool;
    public function resolves(Stasis $value): bool;
    public function transform(mixed $value): mixed;
    public function resolve(mixed $value): mixed;
}
```

Built-in transformers handle closures, anonymous classes, WeakReference, WeakMap, and SplObjectStorage. Users can add custom transformers.

**Opis v4**: Uses callback registration instead of interfaces, but same structural concept.

### 6. Readonly Property Restoration

**The challenge**: PHP 8.1+ readonly properties can only be initialized once. During unserialization, how do you set them when using `newInstanceWithoutConstructor()`?

**Serializor's approach**:
- Create object via `newInstanceWithoutConstructor()` (properties uninitialized)
- Use `Closure::bind()` to enter correct class scope
- Use `ReflectionProperty::setValue()` on uninitialized properties
- For circular references: defer via `whenResolved()` callbacks

This works generically without requiring class modifications.

**Opis v4**: Same basic technique, but the lack of a callback system means readonly properties require the class to implement `__unserialize()`.

### 7. Anonymous Class Serialization

**Serializor's approach**: Extract class source code via tokenization, serialize the code, and reconstruct by evaluating the source. Introduced in v1.0.0 (September 2024).

**Opis v4**: Added the same approach in v4.2.0 (January 2025).

### 8. WeakReference Semantics

**The problem**: `WeakReference` and `WeakMap` have "weak" semantics - targets should be garbage collected if not strongly referenced elsewhere.

**Serializor's approach**: Track which objects are strongly vs weakly referenced during serialization. Mark weak references as "dead" if their target isn't strongly referenced elsewhere in the serialized data.

**Opis v4**: Basic `WeakReference` support without preserving weak semantics.

## Summary of Structural Choices

| Decision | Opis v3 | Serializor (Sep 2024) | Opis v4 (Dec 2024) |
|----------|---------|----------------------|-------------------|
| Direct serialize (no wrappers) | ❌ | ✅ | ✅ |
| Optimistic serialization | ❌ | ✅ | ❌ |
| Object identity | `SplObjectStorage` | `WeakMap` | `WeakMap` |
| Reference identity | ❌ | `ReflectionReference` | `ReflectionReference` |
| Frozen representation | ❌ | `Stasis` + callbacks | `Box` (no callbacks) |
| Readonly without `__unserialize` | ❌ | ✅ | ❌ |
| Anonymous classes | ❌ | ✅ | ✅ (v4.2) |
| WeakRef semantics | ❌ | ✅ | ❌ |

## Observations

Opis v4 shares several structural decisions with Serializor:
- Direct serialization API
- `WeakMap` + `ReflectionReference` for identity tracking
- Frozen object representation pattern
- Anonymous class source extraction

Some differences exist:
- Opis always transforms; Serializor uses optimistic serialization
- Opis lacks the callback system for deferred resolution
- Opis requires `__unserialize` for readonly properties; Serializor works generically

These differences suggest Opis understood the high-level architecture but made different trade-offs in implementation details.

## Prior Art Statement

Serializor was first released on September 5, 2024. The structural decisions documented here represent original approaches to PHP closure and object graph serialization.

---

*For performance comparisons, see [benchmark.php](benchmark.php).*
