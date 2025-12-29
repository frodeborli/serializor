# Serializor - Claude Code Instructions

## Project Overview

Serializor is a PHP library for serializing closures, anonymous classes, and other typically non-serializable values. It's designed for IPC scenarios like worker pools where tasks need to be passed between processes.

## Architecture

- `Serializor.php` - Main entry point, singleton pattern, default transformers
- `src/Codec.php` - Core serialization engine with reference tracking
- `src/Stasis.php` - Frozen object representation during serialization
- `src/Transformers/` - Transformer implementations for special types:
  - `ClosureTransformer.php` - Closure source extraction and reconstruction
  - `WeakReferenceTransformer.php` - WeakReference with correct weak semantics
  - `WeakMapTransformer.php` - WeakMap with weak key semantics
  - `SplObjectStorageTransformer.php` - SplObjectStorage preservation
  - `AnonymousClassTransformer.php` - Anonymous class handling

## Key Design Decisions

### Weak Reference Semantics
WeakReference and WeakMap keys are only preserved if the target object is strongly referenced elsewhere in the serialized data. Objects only reachable through weak references are marked as "dead" during serialization.

### Closure Source Extraction
Closures are serialized by extracting their source code from the original file. Magic constants (`__FILE__`, `__DIR__`, `__LINE__`, etc.) are replaced with their actual values.

**Same-line closures**: Multiple closures on the same line are disambiguated by their parameter names and use variable names. If they cannot be distinguished, serialization throws `SerializerError`.

### Custom Transformers
Implement `TransformerInterface` with:
- `transforms(mixed $value): bool` - Check if transformer handles this value
- `resolves(Stasis $value): bool` - Check if transformer can restore this Stasis
- `transform(mixed $value): Stasis` - Convert to Stasis for serialization
- `resolve(Stasis $value): mixed` - Restore from Stasis

## Testing

```bash
vendor/bin/pest
```

Test files:
- `tests/AdversarialTest.php` - Security and edge cases
- `tests/Transformers/ClosureTransformerTest.php` - Comprehensive closure tests
- `tests/Transformers/CustomTransformerTest.php` - Custom transformer patterns

## Code Style

- PHP 8.2+ with strict types
- No over-engineering - keep solutions minimal
- Prefer explicit errors over silent incorrect behavior

## Use github cli

Use github cli to check for issues, pull requests and such every time a new session is started. Keep composer.json up to date with respect to dependencies and supported PHP version; we support all PHP versions that are not end of life according to www.php.net/supported-versions.php.
