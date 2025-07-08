<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Database\MySql\MySqlClient;
use Rcalicdan\FiberAsync\Database\MySql\PreparedStatement;
use Rcalicdan\FiberAsync\Exceptions\ConnectionException;

// --- IMPORTANT: CONFIGURE YOUR MYSQL CONNECTION HERE ---
const DB_HOST = '127.0.0.1';
const DB_PORT = 3309;
const DB_USER = 'root';
const DB_PASS = 'Reymart1234'; // Your root password, if you have one
const DB_NAME = 'yo'; // A database that can be created or used
// ----------------------------------------------------

// --- A Simple Test Runner ---
$testCounter = 0;
$passedCounter = 0;
$failedTests = [];

function runTest(string $description, callable $testLogic): bool
{
    global $testCounter, $passedCounter, $failedTests;
    $testCounter++;
    echo "==================================================\n";
    echo "[TEST {$testCounter}] {$description}\n";
    echo "--------------------------------------------------\n";

    try {
        $startTime = microtime(true);
        AsyncLoop::run($testLogic);
        $duration = microtime(true) - $startTime;
        echo "\n\033[32m[PASS]\033[0m Test completed in " . number_format($duration, 4) . "s\n";
        $passedCounter++;
        return true;
    } catch (\Throwable $e) {
        echo "\n\n\033[31m[FAIL]\033[0m {$description}\n";
        echo "    REASON: " . get_class($e) . " - " . $e->getMessage() . "\n";
        echo "    IN FILE: " . $e->getFile() . " ON LINE " . $e->getLine() . "\n";
        $failedTests[] = $description;
        return false;
    } finally {
        Async::reset();
        AsyncLoop::reset();
        echo "==================================================\n\n";
    }
}

// --- Helper function to set up the database and table ---
$setupLogic = Async::async(function () {
    echo "  -> Connecting to server (without database selected)...\n";
    $client = new MySqlClient(DB_HOST, DB_PORT, DB_USER, DB_PASS, '');
    Async::await($client->connect());
    echo "  -> [OK] Initial connection successful.\n";
    echo "  -> Sending: CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`\n";
    Async::await($client->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`"));
    echo "  -> Sending: USE `" . DB_NAME . "`\n";
    Async::await($client->query("USE `" . DB_NAME . "`"));
    echo "  -> Sending: DROP TABLE IF EXISTS `fiber_async_test`\n";
    Async::await($client->query("DROP TABLE IF EXISTS `fiber_async_test`"));
    echo "  -> Sending: CREATE TABLE `fiber_async_test`\n";
    Async::await($client->query("
        CREATE TABLE `fiber_async_test` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL
        )
    "));
    echo "  -> Sending: INSERT INTO `fiber_async_test`\n";
    Async::await($client->query("INSERT INTO `fiber_async_test` (name) VALUES ('alice'), ('bob')"));
    echo "  -> Closing setup connection...\n";
    Async::await($client->close());
    echo "  -> [OK] Database setup complete.\n";
});
echo "Running database setup...\n";
AsyncLoop::run($setupLogic);
echo "-----------------------\n\n";


// --- Test Case 1: Successful Connection ---
runTest(
    "Successful Connection",
    Async::async(function () {
        $client = new MySqlClient(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
        echo "  -> Attempting to connect...\n";
        Async::await($client->connect());
        echo "  -> [OK] Connection successful.\n";
        echo "  -> Attempting to close...\n";
        Async::await($client->close());
        echo "  -> [OK] Client closed.";
    })
);

// --- Test Case 2: Concurrent Queries (The most important test!) ---
runTest(
    "Concurrent Queries (Proves Non-Blocking I/O)",
    Async::async(function () {
        $client = new MySqlClient(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
        Async::await($client->connect());
        echo "  -> [OK] Connected. Starting two queries concurrently.\n";
        echo "     - Task 1: A fast query (`SELECT 1`)\n";
        echo "     - Task 2: A slow query (`SELECT SLEEP(1)`)\n";

        $query1 = Async::async(function() use ($client) {
            echo "    -> [Task 1] Sending query...\n";
            $result = $client->query("SELECT 1 AS result");
            echo "    -> [Task 1] Awaiting result...\n";
            return Async::await($result);
        });

        $query2 = Async::async(function() use ($client) {
            echo "    -> [Task 2] Sending query...\n";
            $result = $client->query("SELECT SLEEP(1), 2 AS result");
            echo "    -> [Task 2] Awaiting result...\n";
            return Async::await($result);
        });
        
        echo "  -> Awaiting Async::all() to resolve both promises...\n";
        [$result1, $result2] = Async::await(Async::all([$query1, $query2]));

        echo "  -> [OK] Both queries finished.\n";
        echo "  -> Query 1 Result: " . json_encode($result1) . "\n";
        echo "  -> Query 2 Result: " . json_encode($result2) . "\n";

        assert($result1[0]['result'] === '1', "Result 1 should be 1.");
        assert($result2[0]['result'] === '2', "Result 2 should be 2.");
        
        Async::await($client->close());
        echo "  -> [INFO] If the time for this test is ~1 second, concurrency is working!";
    })
);


// --- Test Case 3: Prepare & Execute Statement ---
runTest(
    "Prepare & Execute Statement",
    Async::async(function () {
        $client = new MySqlClient(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
        Async::await($client->connect());
        echo "  -> [OK] Connected.\n";
        echo "  -> Preparing statement: SELECT name FROM fiber_async_test WHERE id = ?\n";

        /** @var PreparedStatement $stmt */
        $stmt = Async::await($client->prepare("SELECT name FROM fiber_async_test WHERE id = ?"));
        assert($stmt->paramCount === 1, "Statement should expect 1 parameter.");
        echo "  -> [OK] Statement prepared with ID: {$stmt->id}\n";
        
        echo "  -> Executing with id = 2...\n";
        $result = Async::await($stmt->execute([2]));
        
        echo "  -> [OK] Execution finished. Result: " . json_encode($result) . "\n";
        assert($result[0]['name'] === 'bob', "Name should be bob.");

        Async::await($client->close());
    })
);

// --- Test Case 4: Concurrent Prepared Statement Executions ---
runTest(
    "Concurrent Prepared Statement Executions",
    Async::async(function() {
        $client = new MySqlClient(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
        Async::await($client->connect());
        echo "  -> [OK] Connected. Preparing statement...\n";
        
        $stmt = Async::await($client->prepare("SELECT ? AS value"));
        echo "  -> [OK] Statement prepared. Executing concurrently with different params...\n";

        $exec1 = $stmt->execute(['first']);
        $exec2 = $stmt->execute(['second']);

        echo "  -> Awaiting Async::all() to resolve both executions...\n";
        [$result1, $result2] = Async::await(Async::all([$exec1, $exec2]));
        
        echo "  -> [OK] Both executions finished.\n";
        echo "  -> Exec 1 Result: " . json_encode($result1) . "\n";
        echo "  -> Exec 2 Result: " . json_encode($result2) . "\n";

        assert($result1[0]['value'] === 'first');
        assert($result2[0]['value'] === 'second');
        
        Async::await($client->close());
    })
);

// --- Test Case 5: SQL Syntax Error Handling ---
runTest(
    "SQL Syntax Error Handling",
    Async::async(function () {
        $client = new MySqlClient(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME);
        Async::await($client->connect());
        echo "  -> [OK] Connected. Sending query with intentional syntax error...\n";

        $caughtException = null;
        try {
            Async::await($client->query("SELEKT * FROM `fiber_async_test`"));
        } catch (ConnectionException $e) {
            echo "  -> [OK] Correctly caught exception: " . $e->getMessage() . "\n";
            $caughtException = $e;
        }

        assert($caughtException instanceof ConnectionException, "A ConnectionException should have been thrown.");
        assert(str_contains($caughtException->getMessage(), 'syntax'), "Error message should mention syntax error.");
        
        Async::await($client->close());
    })
);


// --- Final Summary ---
echo "\n\n--- TEST SUMMARY ---\n";
if ($passedCounter === $testCounter) {
    echo "\033[32mAll {$testCounter} MySQL client tests passed successfully!\033[0m\n";
} else {
    $failedCount = $testCounter - $passedCounter;
    echo "\033[31m{$passedCounter} passed, {$failedCount} failed.\033[0m\n";
    echo "Failed tests:\n";
    foreach($failedTests as $testName) {
        echo "  - {$testName}\n";
    }
}