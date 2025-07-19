<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncPDO;
use Rcalicdan\FiberAsync\Database\DatabaseConfigFactory;

// Test script for racing transactions with non-blocking delays
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

    AsyncPDO::init($dbConfig, 10);

    // Setup test table
    run(function () {
        await(AsyncPDO::execute("DROP TABLE IF EXISTS race_test"));
        await(AsyncPDO::execute("
            CREATE TABLE race_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_name VARCHAR(255),
                value DECIMAL(10,2),
                timestamp DATETIME,
                status VARCHAR(50) DEFAULT 'COMPLETED'
            )
        "));
        echo "Test table created successfully\n";
    });

    // Test 1: Race with non-blocking delays
    echo "\n=== Test 1: Racing transactions with non-blocking delays ===\n";
    echo "Expected: Transaction A wins at 0.1s, B and C get cancelled before completing\n\n";

    $startTime = microtime(true);

    $result1 = run(function () use ($startTime) {
        return await(AsyncPDO::raceTransactions([
            // Fast winner - 0.1 seconds
            function ($pdo) use ($startTime) {
                $elapsed = number_format((microtime(true) - $startTime) * 1000, 0);
                echo "[{$elapsed}ms] Transaction A: Starting (0.1s - should win)\n";

                delay(0.1); // Non-blocking 100ms delay

                $elapsed = number_format((microtime(true) - $startTime) * 1000, 0);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp, status) VALUES (?, ?, ?, ?)");
                $stmt->execute(['Transaction A - Fast Winner', 100, date('Y-m-d H:i:s.u'), 'FAST_WINNER']);

                echo "[{$elapsed}ms] Transaction A: ✅ Completed and won!\n";
                return "Transaction A won the race!";
            },

            // Medium speed - should be cancelled at 0.1s
            function ($pdo) use ($startTime) {
                $elapsed = number_format((microtime(true) - $startTime) * 1000, 0);
                echo "[{$elapsed}ms] Transaction B: Starting (0.2s - should be cancelled)\n";

                delay(0.2); // This should be cancelled before completing

                // This should NOT execute if cancellation works
                $elapsed = number_format((microtime(true) - $startTime) * 1000, 0);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp, status) VALUES (?, ?, ?, ?)");
                $stmt->execute(['Transaction B - Should Not Exist', 200, date('Y-m-d H:i:s.u'), 'CANCELLED_TOO_LATE']);

                echo "[{$elapsed}ms] Transaction B: ❌ THIS SHOULD NOT PRINT!\n";
                return "Transaction B should have been cancelled";
            },

            // Slow - should definitely be cancelled
            function ($pdo) use ($startTime) {
                $elapsed = number_format((microtime(true) - $startTime) * 1000, 0);
                echo "[{$elapsed}ms] Transaction C: Starting (0.3s - should be cancelled)\n";

                delay(0.3);

                // This should definitely NOT execute
                $elapsed = number_format((microtime(true) - $startTime) * 1000, 0);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp, status) VALUES (?, ?, ?, ?)");
                $stmt->execute(['Transaction C - Should Not Exist', 300, date('Y-m-d H:i:s.u'), 'CANCELLED_TOO_LATE']);

                echo "[{$elapsed}ms] Transaction C: ❌ THIS SHOULD NOT PRINT!\n";
                return "Transaction C should have been cancelled";
            }
        ]));
    });

    $totalTime = (microtime(true) - $startTime) * 1000;
    echo "\n⏱️  Total race time: " . number_format($totalTime, 0) . "ms\n";
    echo "🏆 Race winner result: " . $result1 . "\n\n";

    // Check database results
    $committedRecords = run(fn() => await(AsyncPDO::query("SELECT * FROM race_test ORDER BY id")));
    echo "📊 Records committed to database:\n";
    if (empty($committedRecords)) {
        echo "   ❌ NO RECORDS FOUND\n";
    } else {
        foreach ($committedRecords as $record) {
            echo "   ✅ ID: {$record['id']}, Name: '{$record['transaction_name']}', Status: {$record['status']}\n";
        }
    }

    // Analysis
    echo "\n📈 Cancellation Analysis:\n";
    if (count($committedRecords) === 1) {
        echo "   ✅ PERFECT: Only winner committed\n";
    } else {
        echo "   ❌ PROBLEM: " . count($committedRecords) . " records committed\n";
    }

    if ($totalTime < 150) { // Should be ~100ms if cancellation works
        echo "   ✅ TIMING GOOD: Race completed in ~100ms\n";
    } else {
        echo "   ❌ TIMING BAD: Race took too long (" . number_format($totalTime, 0) . "ms)\n";
    }

    // Test 2: Very close race
    echo "\n=== Test 2: Very close race timing ===\n";

    $startTime2 = microtime(true);

    $result2 = run(function () use ($startTime2) {
        return await(AsyncPDO::raceTransactions([
            // Super fast
            function ($pdo) use ($startTime2) {
                $elapsed = number_format((microtime(true) - $startTime2) * 1000, 0);
                echo "[{$elapsed}ms] Transaction D: Starting (0.05s)\n";

                delay(0.05); // 50ms

                $elapsed = number_format((microtime(true) - $startTime2) * 1000, 0);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp, status) VALUES (?, ?, ?, ?)");
                $stmt->execute(['Transaction D - Super Fast', 400, date('Y-m-d H:i:s.u'), 'SUPER_FAST']);

                echo "[{$elapsed}ms] Transaction D: ✅ Super fast completed!\n";
                return ['type' => 'super_fast', 'time' => '50ms'];
            },

            // Almost as fast
            function ($pdo) use ($startTime2) {
                $elapsed = number_format((microtime(true) - $startTime2) * 1000, 0);
                echo "[{$elapsed}ms] Transaction E: Starting (0.06s)\n";

                delay(0.06); // 60ms - should lose by 10ms

                $elapsed = number_format((microtime(true) - $startTime2) * 1000, 0);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp, status) VALUES (?, ?, ?, ?)");
                $stmt->execute(['Transaction E - Should Not Exist', 500, date('Y-m-d H:i:s.u'), 'ALMOST_FAST']);

                echo "[{$elapsed}ms] Transaction E: ❌ Should not complete!\n";
                return ['type' => 'almost_fast', 'time' => '60ms'];
            },

            // Slower
            function ($pdo) use ($startTime2) {
                $elapsed = number_format((microtime(true) - $startTime2) * 1000, 0);
                echo "[{$elapsed}ms] Transaction F: Starting (0.1s)\n";

                delay(0.1); // 100ms

                $elapsed = number_format((microtime(true) - $startTime2) * 1000, 0);
                $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp, status) VALUES (?, ?, ?, ?)");
                $stmt->execute(['Transaction F - Should Not Exist', 600, date('Y-m-d H:i:s.u'), 'SLOWER']);

                echo "[{$elapsed}ms] Transaction F: ❌ Should not complete!\n";
                return ['type' => 'slower', 'time' => '100ms'];
            }
        ]));
    });

    $totalTime2 = (microtime(true) - $startTime2) * 1000;
    echo "\n⏱️  Close race time: " . number_format($totalTime2, 0) . "ms\n";
    echo "🏆 Close race winner: " . json_encode($result2) . "\n";

    // Test 3: Race with failure
    echo "\n=== Test 3: Race with one transaction failing ===\n";

    try {
        $result3 = run(function () {
            return await(AsyncPDO::raceTransactions([
                // Fast but fails
                function ($pdo) {
                    echo "Transaction G: Starting (0.03s - fast but will fail)\n";

                    delay(0.03); // 30ms

                    // This will cause an error
                    $pdo->exec("INVALID SQL STATEMENT");

                    echo "Transaction G: ❌ Should not reach here\n";
                    return "Should fail";
                },

                // Medium speed - should win
                function ($pdo) {
                    echo "Transaction H: Starting (0.07s - should win after G fails)\n";

                    delay(0.07); // 70ms

                    $stmt = $pdo->prepare("INSERT INTO race_test (transaction_name, value, timestamp, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute(['Transaction H - Winner After Failure', 700, date('Y-m-d H:i:s.u'), 'WINNER_AFTER_FAIL']);

                    echo "Transaction H: ✅ Won after failure!\n";
                    return "Won after G failed";
                },

                // Slow - should be cancelled
                function ($pdo) {
                    echo "Transaction I: Starting (0.15s - should be cancelled)\n";

                    delay(0.15); // 150ms

                    echo "Transaction I: ❌ Should not complete!\n";
                    return "Should be cancelled";
                }
            ]));
        });

        echo "🏆 Failure race result: " . $result3 . "\n";
    } catch (Exception $e) {
        echo "💥 Race failed as expected: " . $e->getMessage() . "\n";
    }

    // Final results
    $finalRecords = run(fn() => await(AsyncPDO::query("SELECT * FROM race_test ORDER BY id")));
    echo "\n📊 Final database state:\n";
    foreach ($finalRecords as $record) {
        echo "   • ID: {$record['id']}, Name: '{$record['transaction_name']}', Status: {$record['status']}\n";
    }

    echo "\n🎯 Overall Results:\n";
    $expectedCount = 3; // Should have exactly 3 winners
    if (count($finalRecords) === $expectedCount) {
        echo "   ✅ SUCCESS: Perfect cancellation - exactly {$expectedCount} winners!\n";
    } else {
        echo "   ❌ ISSUE: Expected {$expectedCount} records, got " . count($finalRecords) . "\n";
    }

    AsyncPDO::reset();
    echo "\n=== Non-blocking delay tests completed! ===\n";
}

// Run the tests
testRacingTransactions();
