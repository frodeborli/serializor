<?php

declare(strict_types=1);

namespace Tests\Transformers;

use Serializor\Codec;
use Serializor\Stasis;
use Serializor\TransformerInterface;

/**
 * Tests for custom transformer patterns.
 *
 * Demonstrates the PDO-like placeholder pattern: objects that hold resources
 * (like database connections) can be serialized as placeholders and recreated
 * during unserialization. This is useful for IPC scenarios where workers need
 * to establish their own connections.
 */

/**
 * A fake PDO-like class that simulates a database connection.
 * In real usage, this would wrap an actual PDO resource.
 */
class FakePDO
{
    private string $dsn;
    private string $username;
    private string $password;
    private bool $connected = false;

    public function __construct(string $dsn, string $username, string $password)
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->connect();
    }

    private function connect(): void
    {
        // Simulate connection establishment
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function query(string $sql): array
    {
        if (!$this->connected) {
            throw new \RuntimeException('Not connected');
        }
        // Simulate query result
        return ['executed' => $sql];
    }
}

/**
 * Custom transformer that handles FakePDO serialization.
 *
 * Instead of trying to serialize the connection, it stores the connection
 * parameters and recreates the connection during unserialization.
 */
class FakePDOTransformer implements TransformerInterface
{
    public function transforms(mixed $value): bool
    {
        return $value instanceof FakePDO;
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() === FakePDO::class;
    }

    public function transform(mixed $value): mixed
    {
        if (!($value instanceof FakePDO)) {
            return false;
        }

        $frozen = new Stasis(FakePDO::class);
        // Store only what we need to recreate the connection
        $frozen->p['dsn'] = $value->getDsn();
        $frozen->p['username'] = $value->getUsername();
        // In real usage, password might be retrieved from a secure store
        // on the receiving end rather than serialized
        $frozen->p['password'] = 'reconnect-password';

        return $frozen;
    }

    public function resolve(mixed $value): mixed
    {
        if (!($value instanceof Stasis) || $value->getClassName() !== FakePDO::class) {
            return false;
        }

        // Create a new connection on the receiving end
        return new FakePDO(
            $value->p['dsn'],
            $value->p['username'],
            $value->p['password']
        );
    }
}

/**
 * A class that holds a FakePDO connection as a property.
 */
class DatabaseService
{
    private FakePDO $connection;
    private string $tableName;

    public function __construct(FakePDO $connection, string $tableName)
    {
        $this->connection = $connection;
        $this->tableName = $tableName;
    }

    public function query(string $sql): array
    {
        return $this->connection->query($sql);
    }

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }
}

// ============================================================================
// CUSTOM TRANSFORMER TESTS
// ============================================================================

test('custom transformer handles FakePDO serialization', function () {
    $pdo = new FakePDO('sqlite::memory:', 'user', 'pass');

    $codec = new Codec('secret', [
        new FakePDOTransformer(),
        ...(\Serializor::getDefaultTransformers()),
    ]);

    $serialized = $codec->serialize($pdo);
    $restored = $codec->unserialize($serialized);

    expect($restored)->toBeInstanceOf(FakePDO::class);
    expect($restored->isConnected())->toBeTrue();
    expect($restored->getDsn())->toBe('sqlite::memory:');
    expect($restored->getUsername())->toBe('user');
});

test('custom transformer in nested object structure', function () {
    $pdo = new FakePDO('mysql:host=localhost', 'admin', 'secret');
    $service = new DatabaseService($pdo, 'users');

    $codec = new Codec('secret', [
        new FakePDOTransformer(),
        ...(\Serializor::getDefaultTransformers()),
    ]);

    $serialized = $codec->serialize($service);
    $restored = $codec->unserialize($serialized);

    expect($restored)->toBeInstanceOf(DatabaseService::class);
    expect($restored->isConnected())->toBeTrue();
    expect($restored->getTableName())->toBe('users');
    expect($restored->query('SELECT * FROM users'))->toBe(['executed' => 'SELECT * FROM users']);
});

test('custom transformer with closure capturing resource-like object', function () {
    $pdo = new FakePDO('pgsql:host=127.0.0.1', 'postgres', 'pg_pass');

    $closure = function (string $table) use ($pdo) {
        return $pdo->query("SELECT * FROM {$table}");
    };

    $codec = new Codec('secret', [
        new FakePDOTransformer(),
        ...(\Serializor::getDefaultTransformers()),
    ]);

    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    // The closure should work with the recreated connection
    $result = $restored('orders');
    expect($result)->toBe(['executed' => 'SELECT * FROM orders']);
});

test('same FakePDO instance shared across multiple paths', function () {
    $pdo = new FakePDO('sqlite:test.db', 'user', 'pass');

    $data = [
        'service1' => new DatabaseService($pdo, 'users'),
        'service2' => new DatabaseService($pdo, 'orders'),
        'direct' => $pdo,
    ];

    $codec = new Codec('secret', [
        new FakePDOTransformer(),
        ...(\Serializor::getDefaultTransformers()),
    ]);

    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    // All references should point to the same recreated instance
    // (though it's a new connection, the object identity is preserved)
    expect($restored['service1']->isConnected())->toBeTrue();
    expect($restored['service2']->isConnected())->toBeTrue();
    expect($restored['direct']->isConnected())->toBeTrue();
});

test('custom transformer registered after default transformers', function () {
    // Transformers are checked in order, so custom ones should come first
    // if they need to override default behavior
    $pdo = new FakePDO('oracle:dbname=xe', 'system', 'oracle');

    // Put custom transformer first
    $codec = new Codec('secret', [
        new FakePDOTransformer(),
        ...(\Serializor::getDefaultTransformers()),
    ]);

    $serialized = $codec->serialize($pdo);
    $restored = $codec->unserialize($serialized);

    expect($restored->getDsn())->toBe('oracle:dbname=xe');
});

test('array of FakePDO connections', function () {
    $connections = [
        'primary' => new FakePDO('mysql:host=primary', 'user', 'pass'),
        'replica' => new FakePDO('mysql:host=replica', 'user', 'pass'),
    ];

    $codec = new Codec('secret', [
        new FakePDOTransformer(),
        ...(\Serializor::getDefaultTransformers()),
    ]);

    $serialized = $codec->serialize($connections);
    $restored = $codec->unserialize($serialized);

    expect($restored['primary']->getDsn())->toBe('mysql:host=primary');
    expect($restored['replica']->getDsn())->toBe('mysql:host=replica');
    expect($restored['primary']->isConnected())->toBeTrue();
    expect($restored['replica']->isConnected())->toBeTrue();
});
