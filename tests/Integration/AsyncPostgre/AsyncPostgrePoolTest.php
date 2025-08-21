<?php

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\PostgreSQL\AsyncPostgreSQLPool;
use Rcalicdan\FiberAsync\Promise\Promise;

function connection_id($connection): int
{
    return spl_object_id($connection);
}

/**
 * Check if PostgreSQL is available for connection
 */
function isPostgreSQLAvailable(): bool
{
    if (! function_exists('pg_connect')) {
        return false;
    }

    $config = [
        'host' => getenv('POSTGRES_HOST') ?: 'testdb',
        'user' => getenv('POSTGRES_USER') ?: 'testuser',
        'dbname' => getenv('POSTGRES_DB') ?: 'testdb',
        'password' => getenv('POSTGRES_PASSWORD') ?: 'testpass',
    ];

    $connectionString = implode(' ', array_map(
        function ($k, $v) {
            return "$k=$v";
        },
        array_keys($config),
        array_values($config)
    ));

    $connection = @pg_connect($connectionString);
    if ($connection !== false) {
        pg_close($connection);

        return true;
    }

    return false;
}

describe('AsyncPostgreSQLPool Initialization and Configuration', function () {
    it('initializes with a valid configuration', function () {
        $config = [
            'host' => 'localhost',
            'username' => 'test_user',
            'database' => 'test_db',
            'password' => 'test_pass',
            'port' => 5432,
        ];
        $pool = new AsyncPostgreSQLPool($config, 5);
        $stats = $pool->getStats();

        expect($stats['max_size'])->toBe(5);
        expect($stats['config_validated'])->toBeTrue();
        expect($stats['active_connections'])->toBe(0);
        expect($stats['pooled_connections'])->toBe(0);
        expect($stats['waiting_requests'])->toBe(0);

        $pool->close();
    });

    it('throws an exception for empty configuration', function () {
        expect(fn () => new AsyncPostgreSQLPool([], 5))
            ->toThrow(InvalidArgumentException::class, 'Database configuration cannot be empty')
        ;
    });

    it('throws an exception for missing required fields', function () {
        expect(fn () => new AsyncPostgreSQLPool(['host' => 'localhost'], 5))
            ->toThrow(InvalidArgumentException::class, "Missing required database configuration field: 'username'")
        ;
    });

    it('throws an exception for empty host', function () {
        expect(fn () => new AsyncPostgreSQLPool([
            'host' => '',
            'username' => 'test',
            'database' => 'test',
        ], 5))
            ->toThrow(InvalidArgumentException::class, "Database configuration field 'host' cannot be empty")
        ;
    });

    it('throws an exception for empty database', function () {
        expect(fn () => new AsyncPostgreSQLPool([
            'host' => 'localhost',
            'username' => 'test',
            'database' => '',
        ], 5))
            ->toThrow(InvalidArgumentException::class, "Database configuration field 'database' cannot be empty")
        ;
    });

    it('throws an exception for invalid port', function () {
        expect(fn () => new AsyncPostgreSQLPool([
            'host' => 'localhost',
            'username' => 'test',
            'database' => 'test',
            'port' => -1,
        ], 5))
            ->toThrow(InvalidArgumentException::class, 'Database port must be a positive integer')
        ;
    });

    it('throws an exception for invalid sslmode', function () {
        expect(fn () => new AsyncPostgreSQLPool([
            'host' => 'localhost',
            'username' => 'test',
            'database' => 'test',
            'sslmode' => 'invalid_mode',
        ], 5))
            ->toThrow(InvalidArgumentException::class, 'Invalid sslmode value')
        ;
    });

    it('accepts valid sslmode values', function () {
        $validModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

        foreach ($validModes as $mode) {
            $config = [
                'host' => 'localhost',
                'username' => 'test',
                'database' => 'test',
                'sslmode' => $mode,
            ];

            // Test that the constructor doesn't throw an exception
            $pool = null;

            try {
                $pool = new AsyncPostgreSQLPool($config, 5);
                expect($pool)->toBeInstanceOf(AsyncPostgreSQLPool::class);
            } finally {
                if ($pool) {
                    $pool->close();
                }
            }
        }
    });
});

describe('AsyncPostgreSQLPool Connection Management (No Database)', function () {
    beforeEach(function () {
        $this->config = [
            'host' => 'nonexistent-host-12345',  // Use obviously fake host
            'username' => 'test_user',
            'database' => 'test_db',
            'password' => 'test_pass',
            'port' => 5432,
        ];
    });

    afterEach(function () {
        if (isset($this->pool)) {
            $this->pool->close();
        }
        EventLoop::reset();
    });

    it('handles connection creation failures gracefully', function () {
        run(function () {
            $pool = new AsyncPostgreSQLPool($this->config, 3);

            try {
                $connection = await($pool->get());
                expect(false)->toBeTrue('Connection should have failed');
            } catch (RuntimeException $e) {
                expect($e->getMessage())->toContain('PostgreSQL Connection failed');

                // Verify stats are correct after failure
                $stats = $pool->getStats();
                expect($stats['active_connections'])->toBe(0);
            }

            $pool->close();
        });
    });

    it('maintains correct statistics during failed operations', function () {
        run(function () {
            $pool = new AsyncPostgreSQLPool($this->config, 3);
            $statsBefore = $pool->getStats();

            expect($statsBefore['active_connections'])->toBe(0)
                ->and($statsBefore['pooled_connections'])->toBe(0)
                ->and($statsBefore['waiting_requests'])->toBe(0)
                ->and($statsBefore['max_size'])->toBe(3)
            ;

            // Try to get a connection (will fail)
            try {
                await($pool->get());
            } catch (RuntimeException $e) {
                // Expected - connection should fail
            }

            $statsAfter = $pool->getStats();
            expect($statsAfter['active_connections'])->toBe(0);

            $pool->close();
        });
    });

    it('properly closes and rejects waiting promises on failed connections', function () {
        run(function () {
            $pool = new AsyncPostgreSQLPool($this->config, 1);

            // Start a connection attempt that will fail
            $waitingPromise = $pool->get();
            await(delay(0));

            // Close the pool before the promise can be rejected naturally
            $pool->close();

            $exceptionThrown = false;

            try {
                await($waitingPromise);
            } catch (RuntimeException $e) {
                $exceptionThrown = true;
                // Could be either connection failure or pool closing
                expect($e->getMessage())->toMatch('/PostgreSQL Connection failed|Pool is being closed/');
            }

            expect($exceptionThrown)->toBeTrue();

            // Verify stats are reset
            $stats = $pool->getStats();
            expect($stats['active_connections'])->toBe(0)
                ->and($stats['pooled_connections'])->toBe(0)
                ->and($stats['waiting_requests'])->toBe(0)
            ;
        });
    });
});

describe('AsyncPostgreSQLPool Configuration Builder', function () {
    it('builds connection string correctly with minimal config', function () {
        $config = [
            'host' => 'localhost',
            'username' => 'testuser',
            'database' => 'testdb',
        ];

        $pool = new AsyncPostgreSQLPool($config, 1);
        expect($pool)->toBeInstanceOf(AsyncPostgreSQLPool::class);
        $pool->close();
    });

    it('builds connection string correctly with full config', function () {
        $config = [
            'host' => 'db.example.com',
            'username' => 'admin',
            'database' => 'production',
            'password' => 'secret123',
            'port' => 5432,
            'sslmode' => 'require',
            'connect_timeout' => 30,
        ];

        $pool = new AsyncPostgreSQLPool($config, 1);
        expect($pool)->toBeInstanceOf(AsyncPostgreSQLPool::class);
        $pool->close();
    });
});

describe('AsyncPostgreSQLPool State Management', function () {
    beforeEach(function () {
        $this->config = [
            'host' => 'nonexistent-host-12345',  // Use obviously fake host
            'username' => 'test_user',
            'database' => 'test_db',
        ];
        $this->pool = new AsyncPostgreSQLPool($this->config, 2);
    });

    afterEach(function () {
        $this->pool->close();
        EventLoop::reset();
    });

    it('tracks last connection properly when connections fail', function () {
        expect($this->pool->getLastConnection())->toBeNull();

        run(function () {
            try {
                await($this->pool->get());
            } catch (RuntimeException $e) {
                // Expected - connection should fail
            }
            // Last connection should still be null since connection failed
            expect($this->pool->getLastConnection())->toBeNull();
        });
    });

    it('handles multiple concurrent failed connection attempts', function () {
        $pool = $this->pool;
        $results = [];
        $exceptions = [];

        run(function () use ($pool, &$results, &$exceptions) {
            $promises = [];

            for ($i = 0; $i < 3; $i++) {
                $promises[] = async(function () use (&$results, &$exceptions, $pool, $i) {
                    try {
                        $connection = await($pool->get());
                        $results[$i] = 'success';
                    } catch (RuntimeException $e) {
                        $results[$i] = 'failed';
                        $exceptions[$i] = $e->getMessage();
                    }
                });
            }

            await(all($promises));
        });

        expect(count($results))->toBe(3);
        // All should fail since we're using a fake host
        foreach ($results as $result) {
            expect($result)->toBe('failed');
        }

        // All should have connection failure messages
        expect(count($exceptions))->toBe(3);
        foreach ($exceptions as $message) {
            expect($message)->toContain('PostgreSQL Connection failed');
        }
    });
});

describe('AsyncPostgreSQLPool Integration Tests', function () {
    it('performs real database operations when PostgreSQL is available', function () {
        // Check if PostgreSQL is available
        if (! isPostgreSQLAvailable()) {
            $this->markTestSkipped('PostgreSQL not available');

            return;
        }

        run(function () {
            $config = [
                'host' => getenv('POSTGRES_HOST') ?: 'localhost',
                'username' => getenv('POSTGRES_USER') ?: 'postgres',
                'database' => getenv('POSTGRES_DB') ?: 'test',
                'password' => getenv('POSTGRES_PASSWORD') ?: '',
                'port' => (int) (getenv('POSTGRES_PORT') ?: 5432),
            ];

            $pool = new AsyncPostgreSQLPool($config, 2);

            $connection = await($pool->get());
            expect($connection)->not->toBeNull();

            // Simple query test
            $result = pg_query($connection, 'SELECT 1 as test');
            expect($result)->not->toBeFalse();

            $row = pg_fetch_assoc($result);
            expect($row['test'])->toBe('1');

            $pool->release($connection);

            $stats = $pool->getStats();
            expect($stats['pooled_connections'])->toBe(1);

            $pool->close();
        });
    });

    it('handles connection reuse properly when PostgreSQL is available', function () {
        // Check if PostgreSQL is available
        if (! isPostgreSQLAvailable()) {
            $this->markTestSkipped('PostgreSQL not available');

            return;
        }

        run(function () {
            $config = [
                'host' => getenv('POSTGRES_HOST') ?: 'localhost',
                'username' => getenv('POSTGRES_USER') ?: 'postgres',
                'database' => getenv('POSTGRES_DB') ?: 'test',
                'password' => getenv('POSTGRES_PASSWORD') ?: '',
                'port' => (int) (getenv('POSTGRES_PORT') ?: 5432),
            ];

            $pool = new AsyncPostgreSQLPool($config, 2);

            // Get and release first connection
            $connection1 = await($pool->get());
            $id1 = connection_id($connection1);
            $pool->release($connection1);

            // Get second connection - should reuse the first one
            $connection2 = await($pool->get());
            $id2 = connection_id($connection2);
            $pool->release($connection2);

            expect($id2)->toBe($id1);
            expect($pool->getStats()['pooled_connections'])->toBe(1);

            $pool->close();
        });
    });
});
