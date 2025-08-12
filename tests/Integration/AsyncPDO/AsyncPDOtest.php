<?php

use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\PDO\DatabaseConfigFactory;

beforeEach(function () {
    $dbConfig = DatabaseConfigFactory::sqlite('file::memory:?cache=shared');
    AsyncPDO::init($dbConfig, 5);
});

afterEach(function () {
    AsyncPDO::reset();
    EventLoop::reset();
});

describe('AsyncPDO Basic Operations', function () {
    it('can create tables and insert data', function () {
        $result = run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    age INTEGER
                )
            "));

            $insertResult = await(AsyncPDO::execute(
                "INSERT INTO users (name, email, age) VALUES (?, ?, ?)",
                ['John Doe', 'john@example.com', 30]
            ));

            return $insertResult;
        });

        expect($result)->toBe(1);
    });

    it('can query data', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    age INTEGER
                )
            "));

            await(AsyncPDO::execute(
                "INSERT INTO users (name, email, age) VALUES (?, ?, ?)",
                ['Jane Smith', 'jane@example.com', 25]
            ));

            $users = await(AsyncPDO::query("SELECT * FROM users"));

            expect($users)->toHaveCount(1)
                ->and($users[0]['name'])->toBe('Jane Smith')
                ->and($users[0]['email'])->toBe('jane@example.com')
                ->and($users[0]['age'])->toBe(25);
        });
    });

    it('can fetch single row', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL
                )
            "));

            await(AsyncPDO::execute(
                "INSERT INTO users (name, email) VALUES (?, ?)",
                ['Bob Wilson', 'bob@example.com']
            ));

            $user = await(AsyncPDO::fetchOne(
                "SELECT * FROM users WHERE email = ?",
                ['bob@example.com']
            ));

            expect($user)->toBeArray()
                ->and($user['name'])->toBe('Bob Wilson')
                ->and($user['email'])->toBe('bob@example.com');
        });
    });

    it('can fetch scalar values', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL
                )
            "));

            await(AsyncPDO::execute("INSERT INTO users (name) VALUES ('User 1')"));
            await(AsyncPDO::execute("INSERT INTO users (name) VALUES ('User 2')"));

            $count = await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM users"));

            expect($count)->toBe(2);
        });
    });
});

describe('AsyncPDO Transactions', function () {
    it('can execute successful transactions', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE accounts (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    balance REAL
                )
            "));

            await(AsyncPDO::execute(
                "INSERT INTO accounts (id, name, balance) VALUES (1, 'Account A', 1000.0)"
            ));

            $result = await(AsyncPDO::transaction(function ($pdo) {
                $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - 100 WHERE id = 1");
                $stmt->execute();

                $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE id = 1");
                $stmt->execute();

                return $stmt->fetch()['balance'];
            }));

            expect((float)$result)->toBe(900.0);
        });
    });

    it('can rollback failed transactions', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE accounts (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    balance REAL
                )
            "));

            await(AsyncPDO::execute(
                "INSERT INTO accounts (id, name, balance) VALUES (1, 'Account A', 1000.0)"
            ));

            try {
                await(AsyncPDO::transaction(function ($pdo) {
                    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - 100 WHERE id = 1");
                    $stmt->execute();

                    // Force an error
                    throw new Exception("Simulated error");
                }));
            } catch (Exception $e) {
                // Expected exception
            }

            $balance = await(AsyncPDO::fetchValue("SELECT balance FROM accounts WHERE id = 1"));
            expect((float)$balance)->toBe(1000.0);
        });
    });
});

describe('AsyncPDO Concurrency', function () {
    it('executes operations concurrently and faster than sequential', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE test_table (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    value VARCHAR(255)
                )
            "));

            await(AsyncPDO::execute("INSERT INTO test_table (value) VALUES ('test1')"));
            await(AsyncPDO::execute("INSERT INTO test_table (value) VALUES ('test2')"));
            await(AsyncPDO::execute("INSERT INTO test_table (value) VALUES ('test3')"));

            $startTime = microtime(true);

      
            $results = await(all([
                function () {
                    await(delay(0.1));
                    return await(AsyncPDO::query("SELECT COUNT(*) as count FROM test_table"));
                },
                function () {
                    await(delay(0.1));
                    return await(AsyncPDO::fetchValue("SELECT MAX(id) FROM test_table"));
                },
                function () {
                    await(delay(0.1));
                    return await(AsyncPDO::fetchOne("SELECT * FROM test_table WHERE id = 1"));
                }
            ]));

            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000; 


            expect($results[0][0]['count'])->toBe(3)
                ->and($results[1])->toBe(3)
                ->and($results[2]['value'])->toBe('test1');

            // Verify concurrency - should complete in ~100ms, not ~300ms
            expect($executionTime)->toBeLessThan(200) 
                ->and($executionTime)->toBeGreaterThan(90); 
        });
    });

    it('handles multiple concurrent database connections', function () {
        run(function () {
            // Setup
            await(AsyncPDO::execute("
                CREATE TABLE concurrent_test (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id INTEGER,
                    timestamp REAL
                )
            "));

            $baseTime = microtime(true);
            $startTime = microtime(true);

            $insertPromises = [];
            for ($i = 1; $i <= 10; $i++) {
                $insertPromises[] = function () use ($i, $baseTime) {
                    await(delay(0.01 * $i)); 
                    return await(AsyncPDO::execute(
                        "INSERT INTO concurrent_test (thread_id, timestamp) VALUES (?, ?)",
                        [$i, $baseTime + (0.01 * $i)] 
                    ));
                };
            }

            $insertResults = await(all($insertPromises));
            $endTime = microtime(true);

            expect(array_sum($insertResults))->toBe(10);

            $executionTime = ($endTime - $startTime) * 1000;
            expect($executionTime)->toBeLessThan(500);


            $count = await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM concurrent_test"));
            expect($count)->toBe(10);

            $threadIds = await(AsyncPDO::query("SELECT DISTINCT thread_id FROM concurrent_test ORDER BY thread_id"));
            expect($threadIds)->toHaveCount(10);
            expect($threadIds[0]['thread_id'])->toBe(1);
            expect($threadIds[9]['thread_id'])->toBe(10);
        });
    });

    it('maintains data consistency under concurrent access', function () {
        run(function () {
            // Setup counter table
            await(AsyncPDO::execute("
            CREATE TABLE counter (
                id INTEGER PRIMARY KEY,
                value INTEGER
            )"));

            await(AsyncPDO::run(function (PDO $pdo) {
                $pdo->exec('PRAGMA journal_mode=WAL;');
                $pdo->exec('PRAGMA busy_timeout=5000;'); // 
            }));

            await(AsyncPDO::execute("INSERT INTO counter (id, value) VALUES (1, 0)"));

            $incrementPromises = [];
            for ($i = 0; $i < 3; $i++) {
                $incrementPromises[] = function () use ($i) {
                    return await(AsyncPDO::transaction(function ($pdo) use ($i) {
                        $maxRetries = 3;
                        $attempt = 0;

                        while ($attempt < $maxRetries) {
                            try {
                                await(delay(0.001 * ($i + 1)));

                                $stmt = $pdo->prepare("UPDATE counter SET value = value + 1 WHERE id = 1");
                                $stmt->execute();
                                $stmt = $pdo->prepare("SELECT value FROM counter WHERE id = 1");
                                $stmt->execute();
                                return $stmt->fetch()['value'];
                            } catch (PDOException $e) {
                                if (str_contains($e->getMessage(), 'database table is locked') && $attempt < $maxRetries - 1) {
                                    $attempt++;
                                    await(delay(0.01 * $attempt));
                                    continue;
                                }
                                throw $e;
                            }
                        }
                    }));
                };
            }

            await(all($incrementPromises));

            $finalValue = await(AsyncPDO::fetchValue("SELECT value FROM counter WHERE id = 1"));
            expect($finalValue)->toBe(3);
        });
    });
});

describe('AsyncPDO Race Transactions', function () {
    it('can handle racing transactions correctly', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE inventory (
                    id INTEGER PRIMARY KEY,
                    item VARCHAR(255),
                    quantity INTEGER
                )
            "));

            await(AsyncPDO::execute(
                "INSERT INTO inventory (id, item, quantity) VALUES (1, 'Widget', 10)"
            ));

            $raceResult = await(AsyncPDO::raceTransactions([
                function ($pdo) {
                    $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = 1");
                    $stmt->execute();
                    $current = $stmt->fetch()['quantity'];

                    if ($current >= 5) {
                        await(delay(0.03)); 
                        $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - 5 WHERE id = 1");
                        $stmt->execute();
                        return "Reserved 5 items";
                    }
                    throw new Exception("Not enough inventory");
                },

                function ($pdo) {
                    $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = 1");
                    $stmt->execute();
                    $current = $stmt->fetch()['quantity'];

                    if ($current >= 3) {
                        await(delay(0.01)); 
                        $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - 3 WHERE id = 1");
                        $stmt->execute();
                        return "Reserved 3 items";
                    }
                    throw new Exception("Not enough inventory");
                }
            ]));

            // Verify one transaction won
            expect($raceResult)->toBeIn(['Reserved 5 items', 'Reserved 3 items']);

            $finalQuantity = await(AsyncPDO::fetchValue("SELECT quantity FROM inventory WHERE id = 1"));

            if ($raceResult === 'Reserved 5 items') {
                expect($finalQuantity)->toBe(5); 
            } else {
                expect($finalQuantity)->toBe(7); 
            }
        });
    });
});

describe('AsyncPDO Connection Management', function () {
    it('properly manages connection pool', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE pool_test (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    data VARCHAR(255)
                )
            "));

            $result = await(AsyncPDO::run(function ($pdo) {
                expect($pdo)->toBeInstanceOf(PDO::class);

                $stmt = $pdo->prepare("INSERT INTO pool_test (data) VALUES (?)");
                $stmt->execute(['test data']);

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM pool_test");
                $stmt->execute();

                return $stmt->fetchColumn();
            }));

            expect($result)->toBe(1);
        });
    });
});

describe('AsyncPDO Error Handling', function () {
    it('handles database errors gracefully', function () {
        run(function () {
            $exceptionThrown = false;

            try {
                await(AsyncPDO::query("SELECT * FROM non_existent_table"));
            } catch (PDOException $e) {
                $exceptionThrown = true;
                expect($e->getMessage())->toContain('no such table');
            }

            expect($exceptionThrown)->toBeTrue();
        });
    });

    it('handles initialization errors', function () {
        AsyncPDO::reset();

        $exceptionThrown = false;

        try {
            run(function () {
                await(AsyncPDO::query("SELECT 1"));
            });
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            expect($e->getMessage())->toContain('AsyncPDO has not been initialized');
        }

        expect($exceptionThrown)->toBeTrue();

        // Reinitialize with proper config for other tests
        $dbConfig = DatabaseConfigFactory::sqlite('file::memory:?cache=shared');
        AsyncPDO::init($dbConfig, 5);
    });
});

describe('AsyncPDO Performance', function () {
    it('shows performance improvement with concurrent operations', function () {
        run(function () {
            await(AsyncPDO::execute("
                CREATE TABLE performance_test (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    value INTEGER
                )
            "));

            for ($i = 1; $i <= 100; $i++) {
                await(AsyncPDO::execute("INSERT INTO performance_test (value) VALUES (?)", [$i]));
            }

            $sequentialStart = microtime(true);
            $sequentialResults = [];
            for ($i = 0; $i < 5; $i++) {
                await(delay(0.05));
                $sequentialResults[] = await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [$i * 20]));
            }
            $sequentialTime = (microtime(true) - $sequentialStart) * 1000;

            $concurrentStart = microtime(true);
            $concurrentResults = await(all([
                function () {
                    await(delay(0.05));
                    return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [0]));
                },
                function () {
                    await(delay(0.05));
                    return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [20]));
                },
                function () {
                    await(delay(0.05));
                    return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [40]));
                },
                function () {
                    await(delay(0.05));
                    return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [60]));
                },
                function () {
                    await(delay(0.05));
                    return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [80]));
                }
            ]));
            $concurrentTime = (microtime(true) - $concurrentStart) * 1000;

            expect($sequentialResults)->toEqual($concurrentResults);
            expect($concurrentTime)->toBeLessThan($sequentialTime * 0.4); // At least 60% faster
            expect($sequentialTime)->toBeGreaterThan(240); // Sequential should take ~250ms
            expect($concurrentTime)->toBeLessThan(100); // Concurrent should take ~50ms + overhead
        });
    });
});
