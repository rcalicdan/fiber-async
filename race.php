<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncPDO;
use Rcalicdan\FiberAsync\Database\DatabaseConfigFactory;

// Test script for racing transactions
function testRacingTransactions()
{
    // Initialize AsyncPDO with MySQL
    $dbConfig = DatabaseConfigFactory::mysql([
        'host'     => '127.0.0.1',
        'database' => 'yo',
        'username' => 'root',
        'password' => 'Reymart1234',
        'port'     => 3309,
    ]);

    AsyncPDO::init($dbConfig, 5);

    // Setup test table
    run(function () {
        await(AsyncPDO::execute("DROP TABLE IF EXISTS race_test"));
        await(AsyncPDO::execute("
            CREATE TABLE race_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_name VARCHAR(255),
                value DECIMAL(10,2),
                timestamp DATETIME
            )
        "));
        echo "Test table created successfully\n";
    });

    // Test 1: Race transactions with different speeds
    echo "\n=== Test 1: Racing transactions with different completion times ===\n";

    $result1 = run(function () {
        return await(AsyncPDO::raceTransactions([
            function ($pdo) {
                echo "Transaction A: Starting (fast)\n";
                usleep(100000);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Transaction A (Winner)', 100, date('Y-m-d H:i:s')]);
                echo "Transaction A: Completed first!\n";
                return "Transaction A won the race!";
            },
            function ($pdo) {
                echo "Transaction B: Starting (medium)\n";
                usleep(200000);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Transaction B (Loser)', 200, date('Y-m-d H:i:s')]);
                echo "Transaction B: Completed second\n";
                return "Transaction B finished";
            },
            function ($pdo) {
                echo "Transaction C: Starting (slow)\n";
                usleep(300000);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Transaction C (Loser)', 300, date('Y-m-d H:i:s')]);
                echo "Transaction C: Completed last\n";
                return "Transaction C finished";
            }
        ]));
    });

    echo "Race winner result: " . $result1 . "\n";

    // Check results
    $committedRecords = run(fn() => await(AsyncPDO::query("SELECT * FROM race_test ORDER BY id")));
    echo "Records in database after race:\n";
    foreach ($committedRecords as $record) {
        echo "- ID: {$record['id']}, Name: {$record['transaction_name']}, Value: {$record['value']}\n";
    }

    // Test 2: One transaction fails
    echo "\n=== Test 2: Racing transactions where one fails ===\n";

    try {
        $result2 = run(function () {
            return await(AsyncPDO::raceTransactions([
                function ($pdo) {
                    echo "Transaction D: Starting (fast & successful)\n";
                    usleep(50000);
                    $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                    $stmt->execute(['Transaction D (Winner)', 400, date('Y-m-d H:i:s')]);
                    echo "Transaction D: Completed successfully!\n";
                    return "Transaction D won!";
                },
                function ($pdo) {
                    echo "Transaction E: Starting (fast but will fail)\n";
                    usleep(30000);
                    $pdo->exec("INVALID SQL STATEMENT");
                    return "Transaction E should not succeed";
                },
                function ($pdo) {
                    echo "Transaction F: Starting (slow)\n";
                    usleep(200000);
                    $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                    $stmt->execute(['Transaction F (Loser)', 600, date('Y-m-d H:i:s')]);
                    echo "Transaction F: Completed\n";
                    return "Transaction F finished";
                }
            ]));
        });

        echo "Race winner result: " . $result2 . "\n";
    } catch (Exception $e) {
        echo "Race failed as expected due to transaction error: " . $e->getMessage() . "\n";
    }

    // Final check
    $finalRecords = run(fn() => await(AsyncPDO::query("SELECT * FROM race_test ORDER BY id")));
    echo "Final records in database:\n";
    foreach ($finalRecords as $record) {
        echo "- ID: {$record['id']}, Name: {$record['transaction_name']}, Value: {$record['value']}\n";
    }

    // Test 3: Business logic race
    echo "\n=== Test 3: Racing business transactions ===\n";

    $result3 = run(function () {
        return await(AsyncPDO::raceTransactions([
            function ($pdo) {
                echo "Inventory Transaction: Reserving items...\n";
                usleep(80000);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Inventory Reserved', 10, date('Y-m-d H:i:s')]);
                echo "Inventory Transaction: Items reserved successfully!\n";
                return ['type' => 'inventory', 'reserved_items' => 10];
            },
            function ($pdo) {
                echo "Payment Transaction: Processing payment...\n";
                usleep(120000);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp) VALUES (?, ?, ?)");
                $stmt->execute(['Payment Processed', 99.99, date('Y-m-d H:i:s')]);
                echo "Payment Transaction: Payment processed successfully!\n";
                return ['type' => 'payment', 'amount' => 99.99];
            }
        ]));
    });

    echo "Business race winner: " . json_encode($result3) . "\n";

    AsyncPDO::reset();
    echo "\n=== All tests completed! ===\n";
}

// Run tests
testRacingTransactions();
