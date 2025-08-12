<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\AsyncPDO;

/**
 * Comprehensive test for AsyncPDO using SQLite in-memory database
 */
function testAsyncPDO(): void
{
    echo "Starting AsyncPDO tests with SQLite in-memory database...\n\n";

    // Database configuration for SQLite in-memory
    $dbConfig = [
        'driver' => 'sqlite',
        'database' => 'file::memory:?cache=shared',
        'username' => '',
        'password' => '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    ];

    // Initialize AsyncPDO with a small pool for testing
    AsyncPDO::init($dbConfig, 3);

    try {
        // Test 1: Basic table creation and data insertion
        echo "Test 1: Creating table and inserting data...\n";
        
        // Create users table
        $createTableResult = await(AsyncPDO::execute("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                age INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        "));
        echo "✓ Table created successfully\n";

        // Insert test data with small delays to simulate real-world conditions
        echo "  Inserting users with simulated network delays...\n";
        $insertResults = await(all([
            function () {
                await(delay(0.1)); // 100ms delay
                return await(AsyncPDO::execute(
                    "INSERT INTO users (name, email, age) VALUES (?, ?, ?)",
                    ['John Doe', 'john@example.com', 30]
                ));
            },
            function () {
                await(delay(0.05)); // 50ms delay
                return await(AsyncPDO::execute(
                    "INSERT INTO users (name, email, age) VALUES (?, ?, ?)",
                    ['Jane Smith', 'jane@example.com', 25]
                ));
            },
            function () {
                await(delay(0.15)); // 150ms delay
                return await(AsyncPDO::execute(
                    "INSERT INTO users (name, email, age) VALUES (?, ?, ?)",
                    ['Bob Johnson', 'bob@example.com', 35]
                ));
            }
        ]));
        echo "✓ Inserted " . array_sum($insertResults) . " users\n\n";

        // Test 2: Query all users
        echo "Test 2: Querying all users...\n";
        $allUsers = await(AsyncPDO::query("SELECT * FROM users ORDER BY id"));
        echo "✓ Found " . count($allUsers) . " users:\n";
        foreach ($allUsers as $user) {
            echo "  - {$user['name']} ({$user['email']}) - Age: {$user['age']}\n";
        }
        echo "\n";

        // Test 3: Fetch single user with delay simulation
        echo "Test 3: Fetching single user with simulated processing delay...\n";
        $singleUser = await(async(function () {
            await(delay(0.2)); // Simulate processing time
            return await(AsyncPDO::fetchOne(
                "SELECT * FROM users WHERE email = ?",
                ['john@example.com']
            ));
        })());
        if ($singleUser) {
            echo "✓ Found user: {$singleUser['name']}\n\n";
        }

        // Test 4: Fetch scalar value
        echo "Test 4: Getting user count...\n";
        $userCount = await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM users"));
        echo "✓ Total users: {$userCount}\n\n";

        // Test 5: Transaction test with processing delays
        echo "Test 5: Testing transactions with simulated processing...\n";
        $transactionResult = await(AsyncPDO::transaction(function ($pdo) {
            // Simulate some business logic processing
            await(delay(0.1));
            
            // Insert a new user within transaction
            $stmt = $pdo->prepare("INSERT INTO users (name, email, age) VALUES (?, ?, ?)");
            $stmt->execute(['Alice Wilson', 'alice@example.com', 28]);
            
            // Simulate more processing
            await(delay(0.05));
            
            // Update existing user within same transaction
            $stmt = $pdo->prepare("UPDATE users SET age = ? WHERE email = ?");
            $stmt->execute([31, 'john@example.com']);
            
            return "Transaction completed successfully";
        }));
        echo "✓ {$transactionResult}\n\n";

        // Verify transaction results
        $updatedCount = await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM users"));
        $johnAge = await(AsyncPDO::fetchValue(
            "SELECT age FROM users WHERE email = ?",
            ['john@example.com']
        ));
        echo "✓ User count after transaction: {$updatedCount}\n";
        echo "✓ John's updated age: {$johnAge}\n\n";

        // Test 6: Concurrent operations with staggered delays
        echo "Test 6: Testing concurrent operations with different processing times...\n";
        $startTime = microtime(true);
        $concurrentResults = await(all([
            function () {
                await(delay(0.1)); // Simulate slow query
                return await(AsyncPDO::query("SELECT COUNT(*) as count FROM users WHERE age > ?", [25]));
            },
            function () {
                await(delay(0.05)); // Simulate medium query
                return await(AsyncPDO::query("SELECT AVG(age) as avg_age FROM users"));
            },
            function () {
                await(delay(0.03)); // Simulate fast query
                return await(AsyncPDO::query("SELECT MIN(age) as min_age, MAX(age) as max_age FROM users"));
            },
        ]));
        $endTime = microtime(true);

        echo "✓ Users over 25: {$concurrentResults[0][0]['count']}\n";
        echo "✓ Average age: " . round($concurrentResults[1][0]['avg_age'], 2) . "\n";
        echo "✓ Age range: {$concurrentResults[2][0]['min_age']} - {$concurrentResults[2][0]['max_age']}\n";
        echo "✓ Concurrent execution completed in " . round(($endTime - $startTime) * 1000, 2) . "ms\n\n";

        // Test 7: Using run() with database operations and delays
        echo "Test 7: Testing with run() helper and processing delays...\n";
        $finalResults = await(AsyncPDO::run(function ($pdo) {
            // Simulate setup time
            await(delay(0.05));
            
            // Create a products table for additional testing
            $pdo->exec("
                CREATE TABLE products (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    price DECIMAL(10,2),
                    category VARCHAR(100)
                )
            ");

            // Insert some products with processing delays
            $stmt = $pdo->prepare("INSERT INTO products (name, price, category) VALUES (?, ?, ?)");
            $products = [
                ['Laptop', 999.99, 'Electronics'],
                ['Book', 29.99, 'Education'],
                ['Coffee Mug', 15.50, 'Kitchen']
            ];

            foreach ($products as $product) {
                await(delay(0.02)); // Simulate processing each product
                $stmt->execute($product);
            }

            // Simulate analysis time
            await(delay(0.1));

            // Return summary
            $stmt = $pdo->query("SELECT category, COUNT(*) as count, AVG(price) as avg_price FROM products GROUP BY category");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }));

        echo "✓ Products created and analyzed:\n";
        foreach ($finalResults as $result) {
            echo "  - {$result['category']}: {$result['count']} items, avg price: $" . 
                 number_format($result['avg_price'], 2) . "\n";
        }
        echo "\n";

        // Test 8: Error handling
        echo "Test 8: Testing error handling...\n";
        try {
            await(AsyncPDO::query("SELECT * FROM non_existent_table"));
        } catch (Exception $e) {
            echo "✓ Error correctly caught: " . substr($e->getMessage(), 0, 50) . "...\n";
        }

        // Test 9: Race transactions with realistic processing delays
        echo "\nTest 9: Testing race transactions with processing delays...\n";
        
        // First, let's add a simple inventory table
        await(AsyncPDO::execute("
            CREATE TABLE inventory (
                id INTEGER PRIMARY KEY,
                item VARCHAR(255),
                quantity INTEGER
            )
        "));
        
        await(AsyncPDO::execute(
            "INSERT INTO inventory (id, item, quantity) VALUES (1, 'Widget', 5)"
        ));

        echo "  Starting race between two inventory reservation transactions...\n";
        $raceStartTime = microtime(true);

        // Create racing transactions that try to reserve inventory
        $raceResult = await(AsyncPDO::raceTransactions([
            // Transaction 1: Try to reserve 3 widgets (slower processing)
            function ($pdo) {
                $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = 1");
                $stmt->execute();
                $current = $stmt->fetch()['quantity'];
                
                if ($current >= 3) {
                    // Simulate business logic processing time
                    await(delay(0.02)); // 20ms processing
                    echo "    Transaction 1: Processing reservation for 3 widgets...\n";
                    await(delay(0.03)); // Additional 30ms processing
                    
                    $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - 3 WHERE id = 1");
                    $stmt->execute();
                    return "Reserved 3 widgets (Transaction 1 won!)";
                }
                throw new Exception("Not enough inventory");
            },
            
            // Transaction 2: Try to reserve 4 widgets (faster processing)
            function ($pdo) {
                $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = 1");
                $stmt->execute();
                $current = $stmt->fetch()['quantity'];
                
                if ($current >= 4) {
                    // Simulate faster business logic
                    await(delay(0.01)); // 10ms processing
                    echo "    Transaction 2: Processing reservation for 4 widgets...\n";
                    await(delay(0.01)); // Additional 10ms processing
                    
                    $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - 4 WHERE id = 1");
                    $stmt->execute();
                    return "Reserved 4 widgets (Transaction 2 won!)";
                }
                throw new Exception("Not enough inventory");
            },

            // Transaction 3: Try to reserve 2 widgets (medium processing)
            function ($pdo) {
                $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = 1");
                $stmt->execute();
                $current = $stmt->fetch()['quantity'];
                
                if ($current >= 2) {
                    // Simulate medium processing time
                    await(delay(0.015)); // 15ms processing
                    echo "    Transaction 3: Processing reservation for 2 widgets...\n";
                    await(delay(0.02)); // Additional 20ms processing
                    
                    $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - 2 WHERE id = 1");
                    $stmt->execute();
                    return "Reserved 2 widgets (Transaction 3 won!)";
                }
                throw new Exception("Not enough inventory");
            }
        ]));

        $raceEndTime = microtime(true);
        echo "✓ Race transaction result: {$raceResult}\n";
        echo "✓ Race completed in " . round(($raceEndTime - $raceStartTime) * 1000, 2) . "ms\n";
        
        // Check final inventory
        $finalInventory = await(AsyncPDO::fetchValue(
            "SELECT quantity FROM inventory WHERE id = 1"
        ));
        echo "✓ Final inventory: {$finalInventory} widgets\n\n";

        // Test 10: Complex concurrent workflow with delays
        echo "Test 10: Complex concurrent workflow simulation...\n";
        $workflowStartTime = microtime(true);
        
        $workflowResults = await(all([
            // Simulate user analytics
            function () {
                await(delay(0.1)); // Simulate complex analytics
                $userStats = await(AsyncPDO::query("
                    SELECT 
                        AVG(age) as avg_age,
                        COUNT(*) as total_users,
                        MIN(age) as youngest,
                        MAX(age) as oldest
                    FROM users
                "));
                return ['type' => 'user_analytics', 'data' => $userStats[0]];
            },
            
            // Simulate product inventory check
            function () {
                await(delay(0.05)); // Simulate inventory processing
                $productStats = await(AsyncPDO::query("
                    SELECT 
                        category,
                        COUNT(*) as product_count,
                        SUM(price) as total_value
                    FROM products 
                    GROUP BY category
                "));
                return ['type' => 'inventory_check', 'data' => $productStats];
            },
            
            // Simulate system health check
            function () {
                await(delay(0.08)); // Simulate health check processing
                $tableCount = await(AsyncPDO::fetchValue("
                    SELECT COUNT(*) FROM sqlite_master WHERE type='table'
                "));
                return ['type' => 'system_health', 'data' => ['table_count' => $tableCount]];
            }
        ]));
        
        $workflowEndTime = microtime(true);
        
        echo "✓ Workflow completed in " . round(($workflowEndTime - $workflowStartTime) * 1000, 2) . "ms\n";
        foreach ($workflowResults as $result) {
            echo "  - {$result['type']}: " . json_encode($result['data']) . "\n";
        }
        echo "\n";

        echo "🎉 All tests completed successfully!\n";

    } catch (Exception $e) {
        echo "❌ Test failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    } finally {
        // Clean up
        AsyncPDO::reset();
        echo "\n✓ AsyncPDO reset completed\n";
    }
}

// Main execution
echo "AsyncPDO Test Suite\n";
echo "==================\n\n";

// Run the test using the run() helper
run(function () {
    await(resolve(testAsyncPDO()));
});

echo "\nTest suite finished.\n";