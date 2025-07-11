<?php

require 'vendor/autoload.php';

// Include your async helpers and the Enum
require_once __DIR__ . '/src/Helpers/async_helper.php';
require_once __DIR__ . '/src/Helpers/loop_helper.php';
require_once __DIR__ . '/src/Database/TransactionIsolationLevel.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\TransactionIsolationLevel;

// -- Your database credentials --
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    // Set debug to false for a clean benchmark output
    'debug' => false,
];

// Number of transactions to run for each test
const ITERATIONS = 1000;

run(async(function () use ($dbConfig) {
    // We only need a single client for this test
    $client = new MySQLClient($dbConfig);
    
    try {
        await($client->connect());
        echo "Client connected successfully. Starting benchmarks...\n";

        // Setup the test table
        await($client->query("DROP TABLE IF EXISTS benchmark_table"));
        await($client->query("CREATE TABLE benchmark_table (id INT, val INT)"));

        // ===================================================================
        // BENCHMARK 1: The "Raw Query" Method
        // ===================================================================
        echo "\nRunning " . ITERATIONS . " transactions using RAW QUERIES...\n";
        $startTimeRaw = microtime(true);

        for ($i = 0; $i < ITERATIONS; $i++) {
            // Manually execute the two commands to start the transaction
            await($client->query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED"));
            await($client->query("START TRANSACTION"));
            
            // Perform a trivial write operation
            await($client->query("INSERT INTO benchmark_table VALUES ($i, $i)"));

            // Manually commit
            await($client->query("COMMIT"));
        }

        $endTimeRaw = microtime(true);
        $durationRaw = $endTimeRaw - $startTimeRaw;
        $avgRaw = ($durationRaw / ITERATIONS) * 1000; // Average time in milliseconds

        printf("Raw Query Method finished in: %.4f seconds\n", $durationRaw);
        printf("Average per transaction:      %.4f ms\n", $avgRaw);
        
        // Clean the table for the next run
        await($client->query("TRUNCATE TABLE benchmark_table"));

        // ===================================================================
        // BENCHMARK 2: The "Transaction Helper" Method
        // ===================================================================
        echo "\nRunning " . ITERATIONS . " transactions using the TRANSACTION HELPER...\n";
        $startTimeHelper = microtime(true);

        for ($i = 0; $i < ITERATIONS; $i++) {
            // Use your new, elegant API. It does the same two queries internally.
            $transaction = await($client->beginTransaction(TransactionIsolationLevel::ReadCommitted));

            // Perform a trivial write operation
            await($transaction->query("INSERT INTO benchmark_table VALUES ($i, $i)"));

            // Commit using the transaction object
            await($transaction->commit());
        }

        $endTimeHelper = microtime(true);
        $durationHelper = $endTimeHelper - $startTimeHelper;
        $avgHelper = ($durationHelper / ITERATIONS) * 1000; // Average time in milliseconds
        
        printf("Transaction Helper finished in: %.4f seconds\n", $durationHelper);
        printf("Average per transaction:        %.4f ms\n", $avgHelper);

    } catch (\Throwable $e) {
        echo "\nAN ERROR OCCURRED: " . $e->getMessage() . "\n";
    } finally {
        // Cleanup
        echo "\nCleaning up...\n";
        if (isset($client)) {
            await($client->query("DROP TABLE IF EXISTS benchmark_table"));
            $client->close();
        }
        echo "Benchmark complete.\n";
    }
}));