<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncPDO;
use Rcalicdan\FiberAsync\Database\DatabaseConfigFactory;

// Test script for racing transactions
function testRacingTransactions()
{
    // Initialize AsyncPDO with SQLite for testing
    $dbConfig = DatabaseConfigFactory::sqlite(':memory:');
    AsyncPDO::init($dbConfig, 5);

    // Setup test table
    run(function () {
        await(AsyncPDO::execute("
            CREATE TABLE race_test (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_name TEXT,
                value INTEGER,
                timestamp TEXT
            )
        "));
        
        echo "Test table created successfully\n";
    });

    // Test 1: Race transactions with different speeds
    echo "\n=== Test 1: Racing transactions with different completion times ===\n";
    
    $result1 = run(function () {
        return AsyncPDO::raceTransactions([
            // Fast transaction (should win)
            function ($pdo) {
                echo "Transaction A: Starting (fast)\n";
                usleep(100000); // 0.1 second
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Transaction A (Winner)', 100, date('Y-m-d H:i:s')]);
                echo "Transaction A: Completed first!\n";
                return "Transaction A won the race!";
            },
            
            // Medium transaction
            function ($pdo) {
                echo "Transaction B: Starting (medium)\n";
                usleep(200000); // 0.2 seconds
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Transaction B (Loser)', 200, date('Y-m-d H:i:s')]);
                echo "Transaction B: Completed second\n";
                return "Transaction B finished";
            },
            
            // Slow transaction
            function ($pdo) {
                echo "Transaction C: Starting (slow)\n";
                usleep(300000); // 0.3 seconds
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Transaction C (Loser)', 300, date('Y-m-d H:i:s')]);
                echo "Transaction C: Completed last\n";
                return "Transaction C finished";
            }
        ]);
    });
    
    echo "Race winner result: " . $result1 . "\n";

    // Verify only winner was committed
    $committedRecords = run(function () {
        return AsyncPDO::query("SELECT * FROM race_test ORDER BY id");
    });
    
    echo "Records in database after race:\n";
    foreach ($committedRecords as $record) {
        echo "- ID: {$record['id']}, Name: {$record['transaction_name']}, Value: {$record['value']}\n";
    }

    // Test 2: Race with one transaction that fails
    echo "\n=== Test 2: Racing transactions where one fails ===\n";
    
    try {
        $result2 = run(function () {
            return AsyncPDO::raceTransactions([
                // Fast successful transaction
                function ($pdo) {
                    echo "Transaction D: Starting (fast & successful)\n";
                    usleep(50000); // 0.05 seconds
                    $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                    $stmt->execute(['Transaction D (Winner)', 400, date('Y-m-d H:i:s')]);
                    echo "Transaction D: Completed successfully!\n";
                    return "Transaction D won!";
                },
                
                // Fast but failing transaction
                function ($pdo) {
                    echo "Transaction E: Starting (fast but will fail)\n";
                    usleep(30000); // 0.03 seconds - faster but will fail
                    // This will fail due to invalid SQL
                    $pdo->exec("INVALID SQL STATEMENT");
                    return "Transaction E should not succeed";
                },
                
                // Slow transaction
                function ($pdo) {
                    echo "Transaction F: Starting (slow)\n";
                    usleep(200000); // 0.2 seconds
                    $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                    $stmt->execute(['Transaction F (Loser)', 600, date('Y-m-d H:i:s')]);
                    echo "Transaction F: Completed\n";
                    return "Transaction F finished";
                }
            ]);
        });
        
        echo "Race winner result: " . $result2 . "\n";
    } catch (Exception $e) {
        echo "Race failed as expected due to transaction error: " . $e->getMessage() . "\n";
    }

    // Verify database state
    $finalRecords = run(function () {
        return AsyncPDO::query("SELECT * FROM race_test ORDER BY id");
    });
    
    echo "Final records in database:\n";
    foreach ($finalRecords as $record) {
        echo "- ID: {$record['id']}, Name: {$record['transaction_name']}, Value: {$record['value']}\n";
    }

    // Test 3: Race with business logic
    echo "\n=== Test 3: Racing business transactions ===\n";
    
    $result3 = run(function () {
        return AsyncPDO::raceTransactions([
            // Inventory reservation transaction
            function ($pdo) {
                echo "Inventory Transaction: Reserving items...\n";
                usleep(80000); // 0.08 seconds
                
                // Simulate inventory check and reservation
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Inventory Reserved', 10, date('Y-m-d H:i:s')]);
                
                echo "Inventory Transaction: Items reserved successfully!\n";
                return ['type' => 'inventory', 'reserved_items' => 10];
            },
            
            // Payment processing transaction
            function ($pdo) {
                echo "Payment Transaction: Processing payment...\n";
                usleep(120000); // 0.12 seconds
                
                // Simulate payment processing
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Payment Processed', 99.99, date('Y-m-d H:i:s')]);
                
                echo "Payment Transaction: Payment processed successfully!\n";
                return ['type' => 'payment', 'amount' => 99.99];
            }
        ]);
    });
    
    echo "Business race winner: " . json_encode($result3) . "\n";

    // Cleanup
    AsyncPDO::reset();
    echo "\n=== All tests completed! ===\n";
}

// Run the tests
testRacingTransactions();