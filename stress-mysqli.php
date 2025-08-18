<?php
// realistic_async_workflow_stress_test.php - Fixed Version

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncMySQLi;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

// Test configuration constants
const TEST_SCENARIOS = [
    'light_load' => ['concurrent_users' => 10, 'operations_per_user' => 5],
    'medium_load' => ['concurrent_users' => 25, 'operations_per_user' => 8],
    'heavy_load' => ['concurrent_users' => 50, 'operations_per_user' => 10],
    'extreme_load' => ['concurrent_users' => 100, 'operations_per_user' => 12]
];

const ROUNDS_PER_SCENARIO = 2;
const CONNECTION_POOL_SIZE = 50;
const DELAY_BETWEEN_ROUNDS = 2.0;

// Database configuration
const DB_DRIVER = 'mysql';
const DB_HOST = 'localhost';
const DB_NAME = 'yo';
const DB_USER = 'hey';
const DB_PASS = '1234';
const DB_PORT = 3306;

// Realistic test data generators
class TestDataGenerator
{
    private static array $firstNames = ['John', 'Jane', 'Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry'];
    private static array $lastNames = ['Smith', 'Johnson', 'Brown', 'Davis', 'Wilson', 'Miller', 'Garcia', 'Martinez', 'Anderson', 'Taylor'];
    private static array $cities = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'San Jose'];
    private static array $products = [
        ['name' => 'Laptop Pro 15"', 'price' => 1299.99, 'category' => 'Electronics'],
        ['name' => 'Wireless Headphones', 'price' => 199.99, 'category' => 'Electronics'],
        ['name' => 'Coffee Maker', 'price' => 89.99, 'category' => 'Kitchen'],
        ['name' => 'Running Shoes', 'price' => 129.99, 'category' => 'Sports'],
        ['name' => 'Office Chair', 'price' => 299.99, 'category' => 'Furniture'],
        ['name' => 'Smartphone', 'price' => 799.99, 'category' => 'Electronics'],
        ['name' => 'Yoga Mat', 'price' => 29.99, 'category' => 'Sports'],
        ['name' => 'Blender', 'price' => 79.99, 'category' => 'Kitchen']
    ];

    public static function generateUser(): array
    {
        return [
            'first_name' => self::$firstNames[array_rand(self::$firstNames)],
            'last_name' => self::$lastNames[array_rand(self::$lastNames)],
            'email' => uniqid('user_') . '@example.com',
            'city' => self::$cities[array_rand(self::$cities)],
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    public static function getRandomProduct(): array
    {
        return self::$products[array_rand(self::$products)];
    }

    public static function generateOrderData(int $userId): array
    {
        $itemCount = rand(1, 3);
        $total = 0;
        $items = [];

        for ($i = 0; $i < $itemCount; $i++) {
            $product = self::getRandomProduct();
            $quantity = rand(1, 2);
            $price = $product['price'];
            $subtotal = $price * $quantity;
            $total += $subtotal;

            $items[] = [
                'product_name' => $product['name'],
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => $subtotal
            ];
        }

        return [
            'user_id' => $userId,
            'total' => $total,
            'status' => 'pending',
            'items' => $items,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
}

// Database schema setup
function setupTestDatabase()
{
    echo "Setting up test database schema...\n";

    return async(function () {
        $setupQueries = [
            "DROP TABLE IF EXISTS order_items",
            "DROP TABLE IF EXISTS orders",
            "DROP TABLE IF EXISTS users",

            "CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                city VARCHAR(50) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_email (email),
                INDEX idx_city (city)
            )",

            "CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            )",

            "CREATE TABLE order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                product_name VARCHAR(100) NOT NULL,
                quantity INT NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                subtotal DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                INDEX idx_order_id (order_id),
                INDEX idx_product_name (product_name)
            )"
        ];

        foreach ($setupQueries as $query) {
            await(AsyncMySQLi::execute($query));
        }

        echo "Database schema created successfully.\n\n";
        return true;
    })();
}

// Realistic workflow simulations
class WorkflowSimulations
{
    // Simulate user registration workflow
    public static function simulateUserRegistration()
    {
        return AsyncMySQLi::transaction(function (mysqli $mysqli) {
            $userData = TestDataGenerator::generateUser();

            // Prepare the insert statement
            $stmt = $mysqli->prepare(
                "INSERT INTO users (first_name, last_name, email, city, created_at) 
         VALUES (?, ?, ?, ?, ?)"
            );

            // In MySQLi, you bind parameters with type hints ('s' for string)
            // All of these are strings, so we use 'sssss'
            $stmt->bind_param(
                'sssss',
                $userData['first_name'],
                $userData['last_name'],
                $userData['email'],
                $userData['city'],
                $userData['created_at']
            );

            // Execute the statement
            $stmt->execute();

            // In MySQLi, you use the insert_id property on the connection object
            return $mysqli->insert_id;
        });
    }

    // Simulate e-commerce order workflow
    public static function simulateOrderWorkflow(int $userId)
    {
        return AsyncMySQLi::transaction(function (mysqli $mysqli) use ($userId) {
            // Verify user exists
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE id = ?");
            // In MySQLi, you bind parameters with type hints ('i' for integer)
            $stmt->bind_param('i', $userId);
            $stmt->execute();

            $result = $stmt->get_result(); // Get the result object
            if (!$result->fetch_assoc()) { // Fetch from the result object
                throw new Exception("User not found");
            }

            $orderData = TestDataGenerator::generateOrderData($userId);

            $stmt = $mysqli->prepare(
                "INSERT INTO orders (user_id, total, status, created_at) 
         VALUES (?, ?, ?, ?)"
            );

            $stmt->bind_param(
                'idss',
                $orderData['user_id'],
                $orderData['total'],
                $orderData['status'],
                $orderData['created_at']
            );
            $stmt->execute();

            $orderId = $mysqli->insert_id;

            foreach ($orderData['items'] as $item) {
                $stmt = $mysqli->prepare(
                    "INSERT INTO order_items (order_id, product_name, quantity, price, subtotal) 
             VALUES (?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    'isidd',
                    $orderId,
                    $item['product_name'],
                    $item['quantity'],
                    $item['price'],
                    $item['subtotal']
                );
                $stmt->execute();
            }

            // The return value remains the same
            return [
                'order_id' => $orderId,
                'total' => $orderData['total'],
                'items_count' => count($orderData['items'])
            ];
        });
    }

    // Simulate analytics/reporting queries
    public static function simulateAnalyticsQueries()
    {
        return async(function () {
            $queries = [
                // Daily sales summary
                AsyncMySQLi::query("
                    SELECT DATE(created_at) as date, 
                           COUNT(*) as orders_count,
                           SUM(total) as daily_revenue
                    FROM orders 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY date DESC
                "),

                // Top customers
                AsyncMySQLi::query("
                    SELECT u.first_name, u.last_name, u.email, u.city,
                           COUNT(o.id) as order_count,
                           SUM(o.total) as total_spent
                    FROM users u
                    JOIN orders o ON u.id = o.user_id
                    GROUP BY u.id
                    ORDER BY total_spent DESC
                    LIMIT 10
                "),

                // Order status distribution
                AsyncMySQLi::query("
                    SELECT status, COUNT(*) as count,
                           ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM orders), 2) as percentage
                    FROM orders
                    GROUP BY status
                ")
            ];

            return await(Promise::all($queries));
        })();
    }

    // Simulate heavy read operations
    public static function simulateHeavyReadOperations()
    {
        return async(function () {
            $readOperations = [];

            // Multiple concurrent search operations
            for ($i = 0; $i < 3; $i++) {
                $readOperations[] = AsyncMySQLi::query("
                    SELECT u.*, COUNT(o.id) as order_count
                    FROM users u
                    LEFT JOIN orders o ON u.id = o.user_id
                    GROUP BY u.id
                    HAVING order_count >= 0
                    ORDER BY order_count DESC
                    LIMIT 20
                ");
            }

            return await(Promise::all($readOperations));
        })();
    }

    // Simulate inventory update workflow
    public static function simulateInventoryUpdates()
    {
        return AsyncMySQLi::transaction(function (mysqli $mysqli) {
            // Simulate updating order statuses
            $statuses = ['processing', 'shipped', 'delivered'];
            $newStatus = $statuses[array_rand($statuses)];

            $stmt = $mysqli->prepare("
        UPDATE orders 
        SET status = ? 
        WHERE status = 'pending' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        LIMIT 5
    ");

            // In MySQLi, you bind parameters with type hints ('s' for string)
            $stmt->bind_param('s', $newStatus);

            // Execute the statement
            $stmt->execute();

            // In MySQLi, you use the affected_rows property on the statement or connection
            return $stmt->affected_rows;
        });
    }
}

// Individual user simulation
function simulateUserSession(int $sessionId, int $operationsCount)
{
    return async(function () use ($sessionId, $operationsCount) {
        $results = [
            'session_id' => $sessionId,
            'operations' => [],
            'total_time' => 0,
            'errors' => 0
        ];

        $sessionStart = microtime(true);

        // Create some initial users to reference
        $createdUsers = [];

        for ($op = 1; $op <= $operationsCount; $op++) {
            $operationStart = microtime(true);

            try {
                // Mix of different operations to simulate realistic usage
                $operation = rand(1, 100);

                if ($operation <= 30) {
                    // 30% - User registration
                    $userId = await(WorkflowSimulations::simulateUserRegistration());
                    $createdUsers[] = $userId;
                    $results['operations'][] = ['type' => 'user_registration', 'user_id' => $userId];
                } elseif ($operation <= 65 && !empty($createdUsers)) {
                    // 35% - Order workflow (if we have users)
                    $userId = $createdUsers[array_rand($createdUsers)];
                    $orderResult = await(WorkflowSimulations::simulateOrderWorkflow($userId));
                    $results['operations'][] = ['type' => 'order_workflow', 'result' => $orderResult];
                } elseif ($operation <= 80) {
                    // 15% - Analytics queries
                    await(WorkflowSimulations::simulateAnalyticsQueries());
                    $results['operations'][] = ['type' => 'analytics_queries'];
                } elseif ($operation <= 95) {
                    // 15% - Heavy read operations
                    await(WorkflowSimulations::simulateHeavyReadOperations());
                    $results['operations'][] = ['type' => 'heavy_reads'];
                } else {
                    // 5% - Inventory updates
                    $updatedCount = await(WorkflowSimulations::simulateInventoryUpdates());
                    $results['operations'][] = ['type' => 'inventory_updates', 'updated' => $updatedCount];
                }

                $operationTime = (microtime(true) - $operationStart) * 1000;
                $results['operations'][count($results['operations']) - 1]['time_ms'] = $operationTime;
            } catch (Exception $e) {
                $results['errors']++;
                $results['operations'][] = [
                    'type' => 'error',
                    'message' => $e->getMessage(),
                    'time_ms' => (microtime(true) - $operationStart) * 1000
                ];
            }
        }

        $results['total_time'] = (microtime(true) - $sessionStart) * 1000;

        return $results;
    })();
}

// Main stress testing function
function runRealisticStressTest(string $scenarioName, array $config)
{
    echo "===========================================\n";
    echo "SCENARIO: " . strtoupper($scenarioName) . "\n";
    echo "Concurrent Users: " . $config['concurrent_users'] . "\n";
    echo "Operations per User: " . $config['operations_per_user'] . "\n";
    echo "===========================================\n";

    return async(function () use ($scenarioName, $config) {
        $roundResults = [];

        for ($round = 1; $round <= ROUNDS_PER_SCENARIO; $round++) {
            echo "  Round $round: Simulating realistic database workflows...\n";

            if ($round > 1) {
                echo "  Waiting " . DELAY_BETWEEN_ROUNDS . " seconds before next round...\n";
                await(delay(DELAY_BETWEEN_ROUNDS));
            }

            $memoryStart = getMemoryUsage();
            $startTime = microtime(true);

            // Create user sessions
            $sessionPromises = [];
            for ($i = 1; $i <= $config['concurrent_users']; $i++) {
                $sessionPromises[] = simulateUserSession($i, $config['operations_per_user']);
            }

            // Execute all sessions concurrently
            $sessionResults = await(Promise::all($sessionPromises));

            $endTime = microtime(true);
            $memoryEnd = getMemoryUsage();

            // Analyze results
            $totalOperations = $config['concurrent_users'] * $config['operations_per_user'];
            $totalTime = ($endTime - $startTime) * 1000;
            $totalErrors = array_sum(array_column($sessionResults, 'errors'));
            $successfulOperations = $totalOperations - $totalErrors;
            $avgSessionTime = array_sum(array_column($sessionResults, 'total_time')) / count($sessionResults);
            $operationsPerSecond = ($successfulOperations / ($totalTime / 1000));
            $errorRate = ($totalErrors / $totalOperations) * 100;

            // Operation type breakdown
            $operationTypes = [];
            foreach ($sessionResults as $session) {
                foreach ($session['operations'] as $op) {
                    if ($op['type'] !== 'error') {
                        $operationTypes[$op['type']] = ($operationTypes[$op['type']] ?? 0) + 1;
                    }
                }
            }

            echo "    - Total Time: " . number_format($totalTime, 1) . "ms\n";
            echo "    - Avg Session Time: " . number_format($avgSessionTime, 1) . "ms\n";
            echo "    - Operations/Second: " . number_format($operationsPerSecond, 2) . "\n";
            echo "    - Error Rate: " . number_format($errorRate, 2) . "%\n";
            echo "    - Memory: {$memoryEnd['current_mb']}MB (Peak: {$memoryEnd['peak_mb']}MB)\n";
            echo "    - Operation Types: " . json_encode($operationTypes) . "\n";

            $roundResults[] = [
                'round' => $round,
                'total_time_ms' => $totalTime,
                'avg_session_time_ms' => $avgSessionTime,
                'operations_per_second' => $operationsPerSecond,
                'error_rate' => $errorRate,
                'memory_mb' => $memoryEnd['current_mb'],
                'peak_memory_mb' => $memoryEnd['peak_mb'],
                'operation_types' => $operationTypes,
                'total_operations' => $totalOperations,
                'successful_operations' => $successfulOperations,
                'concurrent_users' => $config['concurrent_users']
            ];

            echo "\n";
        }

        return $roundResults;
    })();
}

// Memory tracking function
function getMemoryUsage(): array
{
    return [
        'current' => memory_get_usage(true),
        'peak' => memory_get_peak_usage(true),
        'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
    ];
}

echo "=== Realistic AsyncMySQLi Workflow Stress Test ===\n\n";

// Initialize AsyncMySQLi
AsyncMySQLi::init([
    'driver'   => DB_DRIVER,
    'host'     => DB_HOST,
    'database' => DB_NAME,
    'username' => DB_USER,
    'password' => DB_PASS,
    'port'     => DB_PORT,
], CONNECTION_POOL_SIZE);

run(function () {
    // Setup test database
    await(setupTestDatabase());

    echo "Starting realistic workflow stress test...\n";
    echo "Scenarios: " . implode(', ', array_keys(TEST_SCENARIOS)) . "\n";
    echo "Connection pool size: " . CONNECTION_POOL_SIZE . "\n\n";

    $allResults = [];

    // Run each test scenario
    foreach (TEST_SCENARIOS as $scenarioName => $config) {
        $scenarioResults = await(runRealisticStressTest($scenarioName, $config));
        $allResults[$scenarioName] = $scenarioResults;
    }

    // Generate comprehensive report
    echo "===========================================\n";
    echo "COMPREHENSIVE PERFORMANCE ANALYSIS\n";
    echo "===========================================\n";

    $summaryTable = sprintf(
        "| %-12s | %-5s | %-8s | %-12s | %-10s | %-10s | %-10s |\n",
        "Scenario",
        "Users",
        "Ops/User",
        "Avg Ops/Sec",
        "Avg Time(s)",
        "Error Rate",
        "Peak Mem"
    );

    echo $summaryTable;
    echo str_repeat("-", strlen($summaryTable)) . "\n";

    foreach ($allResults as $scenarioName => $rounds) {
        $avgOpsPerSec = array_sum(array_column($rounds, 'operations_per_second')) / count($rounds);
        $avgTime = array_sum(array_column($rounds, 'total_time_ms')) / count($rounds) / 1000;
        $avgErrorRate = array_sum(array_column($rounds, 'error_rate')) / count($rounds);
        $avgPeakMemory = array_sum(array_column($rounds, 'peak_memory_mb')) / count($rounds);

        printf(
            "| %-12s | %-5d | %-8d | %-12s | %-10s | %-10s | %-8sMB |\n",
            $scenarioName,
            $rounds[0]['concurrent_users'],
            $rounds[0]['total_operations'] / $rounds[0]['concurrent_users'],
            number_format($avgOpsPerSec, 1),
            number_format($avgTime, 2),
            number_format($avgErrorRate, 2) . "%",
            number_format($avgPeakMemory, 1)
        );
    }

    echo "\n=== ASYNC PERFORMANCE INSIGHTS ===\n";

    // Calculate concurrency efficiency
    foreach ($allResults as $scenarioName => $rounds) {
        $users = $rounds[0]['concurrent_users'];
        $avgOpsPerSec = array_sum(array_column($rounds, 'operations_per_second')) / count($rounds);
        $efficiency = $avgOpsPerSec / $users;

        echo "• $scenarioName: " . number_format($efficiency, 2) . " ops/sec per concurrent user\n";
    }

    // Check for true async behavior
    echo "\n• Async Behavior Validation:\n";
    foreach ($allResults as $scenarioName => $rounds) {
        $avgSessionTime = array_sum(array_column($rounds, 'avg_session_time_ms')) / count($rounds);
        $avgTotalTime = array_sum(array_column($rounds, 'total_time_ms')) / count($rounds);
        $concurrencyRatio = $avgSessionTime / $avgTotalTime;

        if ($concurrencyRatio > 0.8) {
            echo "  - $scenarioName: TRUE ASYNC ✓ (ratio: " . number_format($concurrencyRatio, 2) . ")\n";
        } else {
            echo "  - $scenarioName: LIMITED CONCURRENCY ⚠ (ratio: " . number_format($concurrencyRatio, 2) . ")\n";
        }
    }

    echo "\n=== Test Complete ===\n";
    echo "This test validates true async behavior through:\n";
    echo "• Complex transaction workflows\n";
    echo "• Concurrent database operations\n";
    echo "• Mixed read/write workloads\n";
    echo "• Connection pool utilization\n";
    echo "• Real-world error handling\n\n";
});

AsyncMySQLi::reset();
