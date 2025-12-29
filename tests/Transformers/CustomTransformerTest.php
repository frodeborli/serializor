<?php

declare(strict_types=1);

namespace Tests\Transformers;

use Serializor\Codec;
use Serializor\ObjectStasis;
use Serializor\Stasis;

/**
 * Tests for custom Stasis factory patterns.
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
 * Custom Stasis subclass for FakePDO that stores connection parameters
 * and recreates the connection during unserialization.
 */
final class FakePDOStasis extends Stasis
{
    private string $dsn;
    private string $username;
    private string $password;

    private function __construct() {}

    public function __serialize(): array
    {
        return [
            'd' => $this->dsn,
            'u' => $this->username,
            'p' => $this->password,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->dsn = $data['d'];
        $this->username = $data['u'];
        $this->password = $data['p'];
    }

    public function getClassName(): string
    {
        return FakePDO::class;
    }

    public static function fromFakePDO(FakePDO $pdo): FakePDOStasis
    {
        $stasis = new FakePDOStasis();
        $stasis->dsn = $pdo->getDsn();
        $stasis->username = $pdo->getUsername();
        // In real usage, password might be retrieved from a secure store
        $stasis->password = 'reconnect-password';
        return $stasis;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        // Create a new connection on the receiving end
        $result = new FakePDO($this->dsn, $this->username, $this->password);
        $this->setInstance($result);
        return $result;
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

// Register the custom factory for FakePDO
Stasis::registerFactory(FakePDO::class, function (object $value): ?Stasis {
    if (!($value instanceof FakePDO)) {
        return null;
    }
    return FakePDOStasis::fromFakePDO($value);
});

// ============================================================================
// CUSTOM STASIS FACTORY TESTS
// ============================================================================

test('custom Stasis factory handles FakePDO serialization', function () {
    $pdo = new FakePDO('sqlite::memory:', 'user', 'pass');

    $codec = new Codec();

    $serialized = $codec->serialize($pdo);
    $restored = $codec->unserialize($serialized);

    expect($restored)->toBeInstanceOf(FakePDO::class);
    expect($restored->isConnected())->toBeTrue();
    expect($restored->getDsn())->toBe('sqlite::memory:');
    expect($restored->getUsername())->toBe('user');
});

test('custom Stasis factory in nested object structure', function () {
    $pdo = new FakePDO('mysql:host=localhost', 'admin', 'secret');
    $service = new DatabaseService($pdo, 'users');

    $codec = new Codec();

    $serialized = $codec->serialize($service);
    $restored = $codec->unserialize($serialized);

    expect($restored)->toBeInstanceOf(DatabaseService::class);
    expect($restored->isConnected())->toBeTrue();
    expect($restored->getTableName())->toBe('users');
    expect($restored->query('SELECT * FROM users'))->toBe(['executed' => 'SELECT * FROM users']);
});

test('custom Stasis factory with closure capturing resource-like object', function () {
    $pdo = new FakePDO('pgsql:host=127.0.0.1', 'postgres', 'pg_pass');

    $closure = function (string $table) use ($pdo) {
        return $pdo->query("SELECT * FROM {$table}");
    };

    $codec = new Codec();

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

    $codec = new Codec();

    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    // All references should point to the same recreated instance
    expect($restored['service1']->isConnected())->toBeTrue();
    expect($restored['service2']->isConnected())->toBeTrue();
    expect($restored['direct']->isConnected())->toBeTrue();
});

test('array of FakePDO connections', function () {
    $connections = [
        'primary' => new FakePDO('mysql:host=primary', 'user', 'pass'),
        'replica' => new FakePDO('mysql:host=replica', 'user', 'pass'),
    ];

    $codec = new Codec();

    $serialized = $codec->serialize($connections);
    $restored = $codec->unserialize($serialized);

    expect($restored['primary']->getDsn())->toBe('mysql:host=primary');
    expect($restored['replica']->getDsn())->toBe('mysql:host=replica');
    expect($restored['primary']->isConnected())->toBeTrue();
    expect($restored['replica']->isConnected())->toBeTrue();
});
