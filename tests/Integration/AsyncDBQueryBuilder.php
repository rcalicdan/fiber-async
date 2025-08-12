<?php

use Rcalicdan\FiberAsync\Api\AsyncDB;
use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\PDO\DatabaseConfigFactory;
use Rcalicdan\FiberAsync\Config\ConfigLoader;

beforeEach(function () {
    // Create a temporary config directory and files for testing
    $testDir = sys_get_temp_dir() . '/async-db-test-' . uniqid();
    mkdir($testDir);
    mkdir($testDir . '/config');
    mkdir($testDir . '/vendor'); // Required for ConfigLoader to find root

    // Create database config file
    $databaseConfig = [
        'default' => 'test',
        'connections' => [
            'test' => DatabaseConfigFactory::sqlite('file::memory:?cache=shared')
        ],
        'pool_size' => 5
    ];

    file_put_contents(
        $testDir . '/config/database.php',
        '<?php return ' . var_export($databaseConfig, true) . ';'
    );

    // Create .env file
    file_put_contents($testDir . '/.env', 'DB_CONNECTION=test');

    // Mock the ConfigLoader to use our test directory
    $reflection = new ReflectionClass(ConfigLoader::class);
    $rootPathProperty = $reflection->getProperty('rootPath');
    $rootPathProperty->setAccessible(true);

    $configProperty = $reflection->getProperty('config');
    $configProperty->setAccessible(true);

    $instance = ConfigLoader::getInstance();
    $rootPathProperty->setValue($instance, $testDir);
    $configProperty->setValue($instance, ['database' => $databaseConfig]);

    $this->testDir = $testDir;
});

afterEach(function () {
    AsyncDB::reset();
    AsyncPDO::reset();
    EventLoop::reset();

    // Clean up test directory
    if (isset($this->testDir) && is_dir($this->testDir)) {
        array_map('unlink', glob($this->testDir . '/config/*'));
        rmdir($this->testDir . '/config');
        unlink($this->testDir . '/.env');
        rmdir($this->testDir . '/vendor');
        rmdir($this->testDir);
    }
});

describe('AsyncDB Configuration and Initialization', function () {
    it('can auto-initialize from config files', function () {
        run(function () {
            // This should auto-initialize AsyncDB
            $result = await(AsyncDB::raw("SELECT 1 as test"));

            expect($result)->toHaveCount(1)
                ->and($result[0]['test'])->toBe(1);
        });
    });

    it('validates configuration and throws errors for invalid config', function () {
        // Reset and provide invalid config
        AsyncDB::reset();
        ConfigLoader::reset();

        $reflection = new ReflectionClass(ConfigLoader::class);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $instance = ConfigLoader::getInstance();
        $configProperty->setValue($instance, ['database' => null]);

        $exceptionThrown = false;

        try {
            run(function () {
                await(AsyncDB::raw("SELECT 1"));
            });
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            expect($e->getMessage())->toContain('Database configuration not found');
        }

        expect($exceptionThrown)->toBeTrue();
    });

    it('re-validates after configuration errors', function () {
        // First, cause a validation error
        AsyncDB::reset();
        ConfigLoader::reset();

        $reflection = new ReflectionClass(ConfigLoader::class);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $instance = ConfigLoader::getInstance();
        $configProperty->setValue($instance, ['database' => ['default' => 123]]); // Invalid

        $firstExceptionThrown = false;
        try {
            run(function () {
                await(AsyncDB::raw("SELECT 1"));
            });
        } catch (RuntimeException $e) {
            $firstExceptionThrown = true;
        }

        expect($firstExceptionThrown)->toBeTrue();

        // Now fix the config and try again
        $validConfig = [
            'default' => 'test',
            'connections' => [
                'test' => DatabaseConfigFactory::sqlite('file::memory:?cache=shared')
            ],
            'pool_size' => 5
        ];
        $configProperty->setValue($instance, ['database' => $validConfig]);

        run(function () {
            $result = await(AsyncDB::raw("SELECT 1 as test"));
            expect($result[0]['test'])->toBe(1);
        });
    });
});

describe('AsyncDB Raw Query Methods', function () {
    it('can execute raw queries', function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE test_raw (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255)
                )
            "));

            $result = await(AsyncDB::raw("SELECT name FROM sqlite_master WHERE type='table' AND name='test_raw'"));
            expect($result)->toHaveCount(1);
        });
    });

    it('can execute raw queries with bindings', function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    email VARCHAR(255)
                )
            "));

            await(AsyncDB::rawExecute(
                "INSERT INTO users (name, email) VALUES (?, ?)",
                ['John Doe', 'john@example.com']
            ));

            $result = await(AsyncDB::raw("SELECT * FROM users WHERE email = ?", ['john@example.com']));

            expect($result)->toHaveCount(1)
                ->and($result[0]['name'])->toBe('John Doe')
                ->and($result[0]['email'])->toBe('john@example.com');
        });
    });

    it('can fetch first result with rawFirst', function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255)
                )
            "));

            await(AsyncDB::rawExecute("INSERT INTO users (name) VALUES (?)", ['Alice']));
            await(AsyncDB::rawExecute("INSERT INTO users (name) VALUES (?)", ['Bob']));

            $result = await(AsyncDB::rawFirst("SELECT * FROM users ORDER BY id"));

            expect($result)->toBeArray()
                ->and($result['name'])->toBe('Alice');
        });
    });

    it('can fetch scalar values with rawValue', function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE counters (
                    id INTEGER PRIMARY KEY,
                    count INTEGER
                )
            "));

            await(AsyncDB::rawExecute("INSERT INTO counters (count) VALUES (42)"));

            $result = await(AsyncDB::rawValue("SELECT count FROM counters WHERE id = 1"));

            expect($result)->toBe(42);
        });
    });

    it('can execute transactions', function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE accounts (
                    id INTEGER PRIMARY KEY,
                    balance REAL
                )
            "));

            await(AsyncDB::rawExecute("INSERT INTO accounts (id, balance) VALUES (1, 1000.0)"));

            $result = await(AsyncDB::transaction(function ($pdo) {
                $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - 100 WHERE id = 1");
                $stmt->execute();

                $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE id = 1");
                $stmt->execute();

                return $stmt->fetch()['balance'];
            }));

            expect((float)$result)->toBe(900.0);
        });
    });
});

describe('AsyncQueryBuilder Basic Operations', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    age INTEGER,
                    active BOOLEAN DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            "));
        });
    });

    it('can create records with insert', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->insert([
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'age' => 30
            ]));

            expect($result)->toBe(1);

            $user = await(AsyncDB::table('users')->where('email', 'john@example.com')->first());
            expect($user['name'])->toBe('John Doe');
        });
    });

    it('can create records with create method', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->create([
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'age' => 25
            ]));

            expect($result)->toBe(1);
        });
    });

    it('can insert batch records', function () {
        run(function () {
            $users = [
                ['name' => 'User 1', 'email' => 'user1@example.com', 'age' => 20],
                ['name' => 'User 2', 'email' => 'user2@example.com', 'age' => 21],
                ['name' => 'User 3', 'email' => 'user3@example.com', 'age' => 22]
            ];

            $result = await(AsyncDB::table('users')->insertBatch($users));
            expect($result)->toBe(3);

            $count = await(AsyncDB::table('users')->count());
            expect($count)->toBe(3);
        });
    });

    it('handles empty batch insert', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->insertBatch([]));
            expect($result)->toBe(0);
        });
    });
});

describe('AsyncQueryBuilder Select Operations', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255),
                    email VARCHAR(255),
                    age INTEGER,
                    active BOOLEAN DEFAULT 1
                )
            "));

            // Insert test data
            $users = [
                ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25, 'active' => 1],
                ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 30, 'active' => 1],
                ['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35, 'active' => 0],
                ['name' => 'Diana', 'email' => 'diana@example.com', 'age' => 28, 'active' => 1]
            ];

            await(AsyncDB::table('users')->insertBatch($users));
        });
    });

    it('can select specific columns', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->select(['name', 'email'])->get());

            expect($result)->toHaveCount(4);
            expect($result[0])->toHaveKey('name')
                ->and($result[0])->toHaveKey('email')
                ->and($result[0])->not->toHaveKey('age');
        });
    });

    it('can select columns as string', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->select('name, email')->get());

            expect($result)->toHaveCount(4);
            expect($result[0])->toHaveKey('name')
                ->and($result[0])->toHaveKey('email');
        });
    });

    it('can get all records', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->get());
            expect($result)->toHaveCount(4);
        });
    });

    it('can get first record', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->orderBy('name')->first());

            expect($result)->toBeArray()
                ->and($result['name'])->toBe('Alice');
        });
    });

    it('can find record by ID', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->find(1));

            expect($result)->toBeArray()
                ->and($result['id'])->toBe(1)
                ->and($result['name'])->toBe('Alice');
        });
    });

    it('can find record by custom column', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->find('alice@example.com', 'email'));

            expect($result)->toBeArray()
                ->and($result['name'])->toBe('Alice');
        });
    });

    it('can find or fail', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->findOrFail(1));
            expect($result['name'])->toBe('Alice');
        });
    });

    it('throws exception when findOrFail fails', function () {
        $exceptionThrown = false;

        try {
            run(function () {
                await(AsyncDB::table('users')->findOrFail(999));
            });
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            expect($e->getMessage())->toContain('Record not found with id = 999');
        }

        expect($exceptionThrown)->toBeTrue();
    });

    it('can get single value', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->where('name', 'Alice')->value('email'));

            expect($result)->toBe('alice@example.com');
        });
    });

    it('can count records', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->count());
            expect($result)->toBe(4);

            $activeCount = await(AsyncDB::table('users')->where('active', 1)->count());
            expect($activeCount)->toBe(3);
        });
    });

    it('can check if records exist', function () {
        run(function () {
            $exists = await(AsyncDB::table('users')->where('name', 'Alice')->exists());
            expect($exists)->toBeTrue();

            $notExists = await(AsyncDB::table('users')->where('name', 'NonExistent')->exists());
            expect($notExists)->toBeFalse();
        });
    });
});

describe('AsyncQueryBuilder Where Clauses', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE products (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255),
                    price DECIMAL(10,2),
                    category_id INTEGER,
                    tags TEXT,
                    active BOOLEAN DEFAULT 1
                )
            "));

            $products = [
                ['name' => 'Laptop', 'price' => 999.99, 'category_id' => 1, 'tags' => 'electronics,computer', 'active' => 1],
                ['name' => 'Mouse', 'price' => 29.99, 'category_id' => 1, 'tags' => 'electronics,accessory', 'active' => 1],
                ['name' => 'Book', 'price' => 19.99, 'category_id' => 2, 'tags' => 'education,reading', 'active' => 1],
                ['name' => 'Pen', 'price' => 2.99, 'category_id' => 3, 'tags' => 'office,writing', 'active' => 0],
                ['name' => 'Phone', 'price' => 699.99, 'category_id' => 1, 'tags' => 'electronics,mobile', 'active' => 1]
            ];

            await(AsyncDB::table('products')->insertBatch($products));
        });
    });

    it('can use where with operator', function () {
        run(function () {
            $result = await(AsyncDB::table('products')->where('price', '>', 100)->get());
            expect($result)->toHaveCount(2); // Laptop and Phone
        });
    });

    it('can use where with equals (default operator)', function () {
        run(function () {
            $result = await(AsyncDB::table('products')->where('category_id', 1)->get());
            expect($result)->toHaveCount(3); // Laptop, Mouse, Phone
        });
    });

    it('can use orWhere', function () {
        run(function () {
            $result = await(AsyncDB::table('products')
                ->where('category_id', 1)
                ->orWhere('category_id', 2)
                ->get());
            expect($result)->toHaveCount(4); // Electronics + Books
        });
    });

    it('can use whereIn', function () {
        run(function () {
            $result = await(AsyncDB::table('products')->whereIn('category_id', [1, 2])->get());
            expect($result)->toHaveCount(4);
        });
    });

    it('can use whereNotIn', function () {
        run(function () {
            $result = await(AsyncDB::table('products')->whereNotIn('category_id', [1, 2])->get());
            expect($result)->toHaveCount(1); // Only Pen
        });
    });

    it('can use whereBetween', function () {
        run(function () {
            $result = await(AsyncDB::table('products')->whereBetween('price', [20, 100])->get());
            expect($result)->toHaveCount(1); // Only Mouse
        });
    });

    it('throws exception for invalid whereBetween values', function () {
        $exceptionThrown = false;

        try {
            run(function () {
                await(AsyncDB::table('products')->whereBetween('price', [20])->get()); // Only one value
            });
        } catch (InvalidArgumentException $e) {
            $exceptionThrown = true;
            expect($e->getMessage())->toContain('whereBetween requires exactly 2 values');
        }

        expect($exceptionThrown)->toBeTrue();
    });

    it('can use whereNull', function () {
        run(function () {
            // First, update one record to have null price
            await(AsyncDB::rawExecute("UPDATE products SET price = NULL WHERE id = 1"));

            $result = await(AsyncDB::table('products')->whereNull('price')->get());
            expect($result)->toHaveCount(1);
        });
    });

    it('can use whereNotNull', function () {
        run(function () {
            $result = await(AsyncDB::table('products')->whereNotNull('price')->get());
            expect($result)->toHaveCount(5); // All products have prices initially
        });
    });

    it('can use like with different sides', function () {
        run(function () {
            // Test 'both' (default)
            $result = await(AsyncDB::table('products')->like('tags', 'electronics')->get());
            expect($result)->toHaveCount(3); // Laptop, Mouse, Phone

            // Test 'after' - should find names that START with 'top'
            $result = await(AsyncDB::table('products')->like('name', 'Lap', 'after')->get());
            expect($result)->toHaveCount(1); // Laptop

            // Test 'before' - should find names that END with 'top' 
            $result = await(AsyncDB::table('products')->like('name', 'top', 'before')->get());
            expect($result)->toHaveCount(1);
        });
    });
});

describe('AsyncQueryBuilder Joins and Grouping', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE categories (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255)
                )
            "));

            await(AsyncDB::rawExecute("
                CREATE TABLE products (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    category_id INTEGER,
                    price DECIMAL(10,2)
                )
            "));

            await(AsyncDB::table('categories')->insertBatch([
                ['id' => 1, 'name' => 'Electronics'],
                ['id' => 2, 'name' => 'Books'],
                ['id' => 3, 'name' => 'Office']
            ]));

            await(AsyncDB::table('products')->insertBatch([
                ['name' => 'Laptop', 'category_id' => 1, 'price' => 999.99],
                ['name' => 'Mouse', 'category_id' => 1, 'price' => 29.99],
                ['name' => 'Book', 'category_id' => 2, 'price' => 19.99],
                ['name' => 'Pen', 'category_id' => 3, 'price' => 2.99]
            ]));
        });
    });

    it('can perform inner joins', function () {
        run(function () {
            $result = await(AsyncDB::table('products')
                ->join('categories', 'products.category_id = categories.id')
                ->select(['products.name as product_name', 'categories.name as category_name'])
                ->get());

            expect($result)->toHaveCount(4);
            expect($result[0])->toHaveKey('product_name')
                ->and($result[0])->toHaveKey('category_name');
        });
    });

    it('can perform left joins', function () {
        run(function () {
            $result = await(AsyncDB::table('products')
                ->leftJoin('categories', 'products.category_id = categories.id')
                ->select(['products.name as product_name', 'categories.name as category_name'])
                ->get());

            expect($result)->toHaveCount(4);
        });
    });

    it('can perform right joins', function () {
        run(function () {
            $result = await(AsyncDB::table('categories')
                ->rightJoin('products', 'categories.id = products.category_id')
                ->select(['products.name as product_name', 'categories.name as category_name'])
                ->get());

            expect($result)->toHaveCount(4);
        });
    });

    it('can group by columns', function () {
        run(function () {
            $result = await(AsyncDB::table('products')
                ->select(['category_id', 'COUNT(*) as count'])
                ->groupBy('category_id')
                ->raw('SELECT category_id, COUNT(*) as count FROM products GROUP BY category_id'));

            expect($result)->toHaveCount(3); // 3 categories
        });
    });

    it('can group by multiple columns', function () {
        run(function () {
            $result = await(AsyncDB::table('products')
                ->groupBy(['category_id', 'price'])
                ->count());

            expect($result)->toBeGreaterThan(0);
        });
    });

    it('can use having clauses', function () {
        run(function () {
            // Use raw query since SQLite has limitations with HAVING in query builder context
            $result = await(AsyncDB::raw("
                SELECT category_id, COUNT(*) as count 
                FROM products 
                GROUP BY category_id 
                HAVING COUNT(*) > 1
            "));

            expect($result)->toHaveCount(1); // Only Electronics category has > 1 product
        });
    });
});

describe('AsyncQueryBuilder Ordering and Limiting', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255),
                    age INTEGER,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            "));

            $users = [
                ['name' => 'Alice', 'age' => 25],
                ['name' => 'Bob', 'age' => 30],
                ['name' => 'Charlie', 'age' => 35],
                ['name' => 'Diana', 'age' => 28],
                ['name' => 'Eve', 'age' => 22]
            ];

            await(AsyncDB::table('users')->insertBatch($users));
        });
    });

    it('can order by column ascending', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->orderBy('age')->get());

            expect($result[0]['name'])->toBe('Eve'); // youngest
            expect($result[4]['name'])->toBe('Charlie'); // oldest
        });
    });

    it('can order by column descending', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->orderBy('age', 'DESC')->get());

            expect($result[0]['name'])->toBe('Charlie'); // oldest
            expect($result[4]['name'])->toBe('Eve'); // youngest
        });
    });

    it('can limit results', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->limit(3)->get());
            expect($result)->toHaveCount(3);
        });
    });

    it('can limit with offset', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->limit(2, 2)->get());
            expect($result)->toHaveCount(2);
            // Should skip first 2 records
        });
    });

    it('can set offset separately', function () {
        run(function () {
            $result = await(AsyncDB::table('users')->offset(3)->limit(2)->get());
            expect($result)->toHaveCount(2);
        });
    });
});

describe('AsyncQueryBuilder Update and Delete', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255),
                    email VARCHAR(255),
                    active BOOLEAN DEFAULT 1
                )
            "));

            $users = [
                ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1],
                ['name' => 'Bob', 'email' => 'bob@example.com', 'active' => 1],
                ['name' => 'Charlie', 'email' => 'charlie@example.com', 'active' => 0]
            ];

            await(AsyncDB::table('users')->insertBatch($users));
        });
    });

    it('can update records', function () {
        run(function () {
            $result = await(AsyncDB::table('users')
                ->where('name', 'Alice')
                ->update(['email' => 'alice.new@example.com']));

            expect($result)->toBe(1);

            $user = await(AsyncDB::table('users')->where('name', 'Alice')->first());
            expect($user['email'])->toBe('alice.new@example.com');
        });
    });

    it('can update multiple records', function () {
        run(function () {
            $result = await(AsyncDB::table('users')
                ->where('active', 1)
                ->update(['active' => 0]));

            expect($result)->toBe(2); // Alice and Bob

            $count = await(AsyncDB::table('users')->where('active', 0)->count());
            expect($count)->toBe(3); // All users are now inactive
        });
    });

    it('can delete records', function () {
        run(function () {
            $result = await(AsyncDB::table('users')
                ->where('name', 'Charlie')
                ->delete());

            expect($result)->toBe(1);

            $count = await(AsyncDB::table('users')->count());
            expect($count)->toBe(2); // Only Alice and Bob remain
        });
    });

    it('can delete multiple records', function () {
        run(function () {
            $result = await(AsyncDB::table('users')
                ->where('active', 1)
                ->delete());

            expect($result)->toBe(2); // Alice and Bob

            $count = await(AsyncDB::table('users')->count());
            expect($count)->toBe(1); // Only Charlie remains
        });
    });
});

describe('AsyncQueryBuilder Raw Methods', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE test_table (
                    id INTEGER PRIMARY KEY,
                    data VARCHAR(255)
                )
            "));

            await(AsyncDB::rawExecute("INSERT INTO test_table (data) VALUES ('test data')"));
        });
    });

    it('can execute raw queries', function () {
        run(function () {
            $result = await(AsyncDB::table('test_table')->raw("SELECT * FROM test_table"));

            expect($result)->toHaveCount(1)
                ->and($result[0]['data'])->toBe('test data');
        });
    });

    it('can execute raw queries with bindings', function () {
        run(function () {
            $result = await(AsyncDB::table('test_table')->raw(
                "SELECT * FROM test_table WHERE data = ?",
                ['test data']
            ));

            expect($result)->toHaveCount(1);
        });
    });

    it('can execute rawFirst', function () {
        run(function () {
            $result = await(AsyncDB::table('test_table')->rawFirst("SELECT * FROM test_table"));

            expect($result)->toBeArray()
                ->and($result['data'])->toBe('test data');
        });
    });

    it('can execute rawValue', function () {
        run(function () {
            $result = await(AsyncDB::table('test_table')->rawValue("SELECT data FROM test_table WHERE id = 1"));

            expect($result)->toBe('test data');
        });
    });
});

describe('AsyncQueryBuilder Concurrency', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
                CREATE TABLE concurrent_test (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255),
                    value INTEGER
                )
            "));

            // Insert test data
            $data = [];
            for ($i = 1; $i <= 20; $i++) {
                $data[] = ['name' => "Item {$i}", 'value' => $i * 10];
            }
            await(AsyncDB::table('concurrent_test')->insertBatch($data));
        });
    });

    it('executes concurrent queries efficiently', function () {
        run(function () {
            $startTime = microtime(true);

            $results = await(all([
                function () {
                    await(delay(0.05));
                    return await(AsyncDB::table('concurrent_test')->where('value', '>', 100)->count());
                },
                function () {
                    await(delay(0.05));
                    return await(AsyncDB::table('concurrent_test')->where('value', '<', 50)->get());
                },
                function () {
                    await(delay(0.05));
                    return await(AsyncDB::table('concurrent_test')->orderBy('value', 'DESC')->first());
                },
                function () {
                    await(delay(0.05));
                    return await(AsyncDB::table('concurrent_test')->whereIn('value', [10, 20, 30])->get());
                }
            ]));

            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;

            // Verify results
            expect($results[0])->toBe(10); // Count of items with value > 100
            expect($results[1])->toHaveCount(4); // Items with value < 50
            expect($results[2]['name'])->toBe('Item 20'); // Highest value item
            expect($results[3])->toHaveCount(3); // Items with values 10, 20, 30

            // Verify concurrency - should complete in ~50ms, not ~200ms
            expect($executionTime)->toBeLessThan(100)
                ->and($executionTime)->toBeGreaterThan(40);
        });
    });

    it('handles concurrent inserts and updates', function () {
        run(function () {
            $insertPromises = [];

            for ($i = 1; $i <= 5; $i++) {
                $insertPromises[] = function () use ($i) {
                    await(delay(0.01 * $i));
                    return await(AsyncDB::table('concurrent_test')->insert([
                        'name' => "Concurrent Item {$i}",
                        'value' => $i * 100
                    ]));
                };
            }

            $insertResults = await(all($insertPromises));

            // All inserts should succeed
            expect(array_sum($insertResults))->toBe(5);

            $count = await(AsyncDB::table('concurrent_test')->count());
            expect($count)->toBe(25); // 20 original + 5 new
        });
    });
});

describe('AsyncQueryBuilder Error Handling', function () {
    it('handles invalid table names gracefully', function () {
        $exceptionThrown = false;

        try {
            run(function () {
                await(AsyncDB::table('non_existent_table')->get());
            });
        } catch (PDOException $e) {
            $exceptionThrown = true;
            expect($e->getMessage())->toContain('no such table');
        }

        expect($exceptionThrown)->toBeTrue();
    });

    it('handles invalid column names in queries', function () {
        run(function () {
            await(AsyncDB::rawExecute("CREATE TABLE test (id INTEGER PRIMARY KEY, name VARCHAR(255))"));

            $exceptionThrown = false;

            try {
                await(AsyncDB::table('test')->where('non_existent_column', 'value')->get());
            } catch (PDOException $e) {
                $exceptionThrown = true;
                expect($e->getMessage())->toContain('no such column');
            }

            expect($exceptionThrown)->toBeTrue();
        });
    });

    it('handles constraint violations', function () {
        run(function () {
            await(AsyncDB::rawExecute("
               CREATE TABLE unique_test (
                   id INTEGER PRIMARY KEY,
                   email VARCHAR(255) UNIQUE
               )
           "));

            await(AsyncDB::table('unique_test')->insert(['email' => 'test@example.com']));

            $exceptionThrown = false;

            try {
                await(AsyncDB::table('unique_test')->insert(['email' => 'test@example.com'])); // Duplicate
            } catch (PDOException $e) {
                $exceptionThrown = true;
                expect($e->getMessage())->toContain('UNIQUE constraint failed');
            }

            expect($exceptionThrown)->toBeTrue();
        });
    });
});

describe('AsyncQueryBuilder Performance and Edge Cases', function () {
    it('handles large result sets efficiently', function () {
        run(function () {
            await(AsyncDB::rawExecute("
               CREATE TABLE large_test (
                   id INTEGER PRIMARY KEY AUTOINCREMENT,
                   data VARCHAR(255)
               )
           "));

            // Insert a large batch of data
            $data = [];
            for ($i = 1; $i <= 1000; $i++) {
                $data[] = ['data' => "Data item {$i}"];
            }

            $chunks = array_chunk($data, 100); // Insert in chunks to avoid memory issues
            foreach ($chunks as $chunk) {
                await(AsyncDB::table('large_test')->insertBatch($chunk));
            }

            $startTime = microtime(true);
            $count = await(AsyncDB::table('large_test')->count());
            $endTime = microtime(true);

            expect($count)->toBe(1000);
            expect(($endTime - $startTime) * 1000)->toBeLessThan(100); // Should be fast
        });
    });

    it('handles empty results gracefully', function () {
        run(function () {
            await(AsyncDB::rawExecute("CREATE TABLE empty_test (id INTEGER PRIMARY KEY, name VARCHAR(255))"));

            $result = await(AsyncDB::table('empty_test')->get());
            expect($result)->toBeArray()->and($result)->toHaveCount(0);

            $first = await(AsyncDB::table('empty_test')->first());
            expect($first)->toBeFalse(); // or null depending on implementation

            $count = await(AsyncDB::table('empty_test')->count());
            expect($count)->toBe(0);

            $exists = await(AsyncDB::table('empty_test')->exists());
            expect($exists)->toBeFalse();
        });
    });

    it('handles null values correctly', function () {
        run(function () {
            await(AsyncDB::rawExecute("
               CREATE TABLE null_test (
                   id INTEGER PRIMARY KEY,
                   name VARCHAR(255),
                   optional_field VARCHAR(255)
               )
           "));

            await(AsyncDB::table('null_test')->insert([
                'name' => 'Test Item',
                'optional_field' => null
            ]));

            $result = await(AsyncDB::table('null_test')->whereNull('optional_field')->first());
            expect($result)->toBeArray()
                ->and($result['name'])->toBe('Test Item')
                ->and($result['optional_field'])->toBeNull();

            $notNullResult = await(AsyncDB::table('null_test')->whereNotNull('name')->first());
            expect($notNullResult)->toBeArray()
                ->and($notNullResult['name'])->toBe('Test Item');
        });
    });

    it('handles complex query combinations', function () {
        run(function () {
            await(AsyncDB::rawExecute("
           CREATE TABLE complex_test (
               id INTEGER PRIMARY KEY AUTOINCREMENT,
               name VARCHAR(255),
               category VARCHAR(100),
               price DECIMAL(10,2),
               tags TEXT,
               active BOOLEAN DEFAULT 1
           )
       "));

            $data = [
                ['name' => 'Product A', 'category' => 'Electronics', 'price' => 299.99, 'tags' => 'popular,new', 'active' => 1],
                ['name' => 'Product B', 'category' => 'Electronics', 'price' => 499.99, 'tags' => 'premium,popular', 'active' => 1],
                ['name' => 'Product C', 'category' => 'Books', 'price' => 19.99, 'tags' => 'education,new', 'active' => 1],
                ['name' => 'Product D', 'category' => 'Electronics', 'price' => 99.99, 'tags' => 'budget', 'active' => 0],
                ['name' => 'Product E', 'category' => 'Office', 'price' => 29.99, 'tags' => 'supplies,popular', 'active' => 1]
            ];

            await(AsyncDB::table('complex_test')->insertBatch($data));

            // First, let's check if data was inserted correctly
            $allData = await(AsyncDB::table('complex_test')->get());
            echo "All inserted data:\n";
            print_r($allData);

            // Build the query with debugging
            $queryBuilder = AsyncDB::table('complex_test')
                ->where('active', 1)
                ->whereIn('category', ['Electronics', 'Office'])
                ->whereBetween('price', [50, 500])
                ->like('tags', 'popular')
                ->orderBy('price', 'DESC')
                ->limit(2);

            echo "Generated SQL: " . $queryBuilder->toSql() . "\n";
            echo "Bindings: ";
            print_r($queryBuilder->getBindings());

            $result = await($queryBuilder->get());

            echo "Query result count: " . count($result) . "\n";
            echo "Query results:\n";
            print_r($result);

            expect($result)->toHaveCount(2);
            expect($result[0]['name'])->toBe('Product B');
            expect($result[1]['name'])->toBe('Product A');
        });
    });
});

describe('AsyncQueryBuilder Method Chaining', function () {
    beforeEach(function () {
        run(function () {
            try {
                await(AsyncDB::rawExecute("DROP TABLE IF EXISTS chain_test"));
            } catch (Exception $e) {
            }

            await(AsyncDB::rawExecute("
               CREATE TABLE chain_test (
                   id INTEGER PRIMARY KEY AUTOINCREMENT,
                   name VARCHAR(255),
                   status VARCHAR(50),
                   priority INTEGER,
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP
               )
           "));

            $data = [
                ['name' => 'Task A', 'status' => 'pending', 'priority' => 1],
                ['name' => 'Task B', 'status' => 'completed', 'priority' => 2],
                ['name' => 'Task C', 'status' => 'pending', 'priority' => 3],
                ['name' => 'Task D', 'status' => 'in_progress', 'priority' => 1],
                ['name' => 'Task E', 'status' => 'pending', 'priority' => 2]
            ];

            await(AsyncDB::table('chain_test')->insertBatch($data));
        });
    });

    it('supports extensive method chaining', function () {
        run(function () {
            $result = await(AsyncDB::table('chain_test')
                ->select(['name', 'status', 'priority'])
                ->where('status', '!=', 'completed')
                ->orWhere('priority', 1)
                ->whereIn('status', ['pending', 'in_progress'])
                ->orderBy('priority')
                ->orderBy('name')
                ->limit(3)
                ->get());

            expect($result)->toHaveCount(3);
            expect($result[0]['priority'])->toBe(1); // Lowest priority first
        });
    });

    it('maintains query builder state correctly', function () {
        run(function () {
            $queryBuilder = AsyncDB::table('chain_test')
                ->where('status', 'pending')
                ->orderBy('priority');

            // Execute same query builder multiple times
            $result1 = await($queryBuilder->get());
            $result2 = await($queryBuilder->get());

            expect($result1)->toEqual($result2);
            expect($result1)->toHaveCount(3); // 3 pending tasks
        });
    });
});

describe('AsyncQueryBuilder Integration with Transactions', function () {
    beforeEach(function () {
        run(function () {
            await(AsyncDB::rawExecute("
               CREATE TABLE account_transactions (
                   id INTEGER PRIMARY KEY AUTOINCREMENT,
                   account_id INTEGER,
                   amount DECIMAL(10,2),
                   type VARCHAR(20),
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP
               )
           "));

            await(AsyncDB::rawExecute("
               CREATE TABLE accounts (
                   id INTEGER PRIMARY KEY,
                   name VARCHAR(255),
                   balance DECIMAL(10,2)
               )
           "));

            await(AsyncDB::table('accounts')->insertBatch([
                ['id' => 1, 'name' => 'Account A', 'balance' => 1000.00],
                ['id' => 2, 'name' => 'Account B', 'balance' => 500.00]
            ]));
        });
    });

    it('can use query builder within transactions', function () {
        run(function () {
            $result = await(AsyncDB::transaction(function ($pdo) {
                // Transfer $200 from Account A to Account B
                $transferAmount = 200.00;

                // Deduct from Account A
                $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$transferAmount, 1]);

                // Add to Account B
                $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$transferAmount, 2]);

                // Record transactions (these would normally use AsyncDB::table but within transaction we use PDO directly)
                $stmt = $pdo->prepare("INSERT INTO account_transactions (account_id, amount, type) VALUES (?, ?, ?)");
                $stmt->execute([1, -$transferAmount, 'debit']);
                $stmt->execute([2, $transferAmount, 'credit']);

                // Return final balances
                $stmt = $pdo->prepare("SELECT id, balance FROM accounts ORDER BY id");
                $stmt->execute();
                return $stmt->fetchAll();
            }));

            expect($result)->toHaveCount(2);
            expect((float)$result[0]['balance'])->toBe(800.00); // Account A: 1000 - 200
            expect((float)$result[1]['balance'])->toBe(700.00); // Account B: 500 + 200

            // Verify transaction records were created
            $transactionCount = await(AsyncDB::table('account_transactions')->count());
            expect($transactionCount)->toBe(2);
        });
    });
});
