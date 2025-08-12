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
                ->and($users[0]['name'])->toBe('Jane Smith');
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
                ->and($user['name'])->toBe('Bob Wilson');
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
            await(AsyncPDO::execute("CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance REAL)"));
            await(AsyncPDO::execute("INSERT INTO accounts (id, balance) VALUES (1, 1000.0)"));

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
            await(AsyncPDO::execute("CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance REAL)"));
            await(AsyncPDO::execute("INSERT INTO accounts (id, balance) VALUES (1, 1000.0)"));

            try {
                await(AsyncPDO::transaction(function ($pdo) {
                    $pdo->prepare("UPDATE accounts SET balance = balance - 100 WHERE id = 1")->execute();
                    throw new Exception("Simulated error");
                }));
            } catch (Exception $e) {
                // Expected
            }

            $balance = await(AsyncPDO::fetchValue("SELECT balance FROM accounts WHERE id = 1"));
            expect((float)$balance)->toBe(1000.0);
        });
    });
});

describe('AsyncPDO Concurrency', function () {
    it('executes operations concurrently and faster than sequential', function () {
        run(function () {
            await(AsyncPDO::execute("CREATE TABLE test_table (id INTEGER PRIMARY KEY, value VARCHAR(255))"));
            await(AsyncPDO::execute("INSERT INTO test_table (value) VALUES ('test1')"));
            await(AsyncPDO::execute("INSERT INTO test_table (value) VALUES ('test2')"));

            $startTime = microtime(true);
            $results = await(all([
                function () {
                    await(delay(0.1));
                    return await(AsyncPDO::query("SELECT COUNT(*) as count FROM test_table"));
                },
                function () {
                    await(delay(0.1));
                    return await(AsyncPDO::fetchValue("SELECT MAX(id) FROM test_table"));
                }
            ]));
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;

            expect($results[0][0]['count'])->toBe(2)
                ->and($results[1])->toBe(2);
            expect($executionTime)->toBeLessThan(150)->and($executionTime)->toBeGreaterThan(90);
        });
    });
});


describe('AsyncPDO Performance', function () {
    it('shows performance improvement with concurrent operations', function () {
        run(function () {
            await(AsyncPDO::execute("CREATE TABLE performance_test (id INTEGER PRIMARY KEY, value INTEGER)"));
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
                function () { await(delay(0.05)); return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [0])); },
                function () { await(delay(0.05)); return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [20])); },
                function () { await(delay(0.05)); return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [40])); },
                function () { await(delay(0.05)); return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [60])); },
                function () { await(delay(0.05)); return await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM performance_test WHERE value > ?", [80])); }
            ]));
            $concurrentTime = (microtime(true) - $concurrentStart) * 1000;

            expect($sequentialResults)->toEqual($concurrentResults);
            expect($concurrentTime)->toBeLessThan($sequentialTime * 0.4);
        });
    });

    /**
     * This is the new test case.
     */
    it('maintains concurrency with CPU-intensive database queries', function () {
        run(function () {
            await(AsyncPDO::execute("CREATE TABLE compute_test (id INTEGER PRIMARY KEY, payload TEXT, numeric_value INTEGER)"));
            $promises = [];
            for ($i = 1; $i <= 200; $i++) {
                $promises[] = AsyncPDO::execute(
                    "INSERT INTO compute_test (payload, numeric_value) VALUES (?, ?)",
                    [uniqid('payload_', true), $i]
                );
            }
            await(all($promises));

            $cpuHeavyQuery = "SELECT SUM(LENGTH(payload) * numeric_value * ?) FROM compute_test WHERE numeric_value % ? = 0";

            $sequentialStart = microtime(true);
            $sequentialResults = [];
            for ($i = 0; $i < 3; $i++) {
                await(delay(0.02)); // Simulate other PHP work happening
                $sequentialResults[] = await(AsyncPDO::fetchValue($cpuHeavyQuery, [$i + 2, $i + 3]));
            }
            $sequentialTime = (microtime(true) - $sequentialStart) * 1000;

            // 4. Run concurrently and measure time.
            $concurrentStart = microtime(true);
            $concurrentResults = await(all([
                function () use ($cpuHeavyQuery) {
                    await(delay(0.02));
                    return await(AsyncPDO::fetchValue($cpuHeavyQuery, [2, 3]));
                },
                function () use ($cpuHeavyQuery) {
                    await(delay(0.02));
                    return await(AsyncPDO::fetchValue($cpuHeavyQuery, [3, 4]));
                },
                function () use ($cpuHeavyQuery) {
                    await(delay(0.02));
                    return await(AsyncPDO::fetchValue($cpuHeavyQuery, [4, 5]));
                }
            ]));
            $concurrentTime = (microtime(true) - $concurrentStart) * 1000;

            expect($sequentialResults)->toEqual($concurrentResults);
            expect($concurrentTime)->toBeLessThan($sequentialTime * 0.5); // At least 50% faster
        });
    });
});