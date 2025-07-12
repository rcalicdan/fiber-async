<?php

require 'vendor/autoload.php';

// Include your async helpers and new Enum
require_once __DIR__.'/src/Helpers/async_helper.php';
require_once __DIR__.'/src/Helpers/loop_helper.php';
require_once __DIR__.'/src/Database/TransactionIsolationLevel.php'; // Make sure this path is correct

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\TransactionIsolationLevel;

// -- Your database credentials --
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    'debug' => false,
];

/**
 * The main test function. It simulates a race condition to show the effect of an isolation level.
 *
 * @param  MySQLClient  $readerClient  The client whose transaction we are testing.
 * @param  MySQLClient  $writerClient  The client that makes a concurrent change.
 * @param  TransactionIsolationLevel  $level  The isolation level to test.
 * @param  int|float  $expectedSecondReadValue  The value we expect to see on the second read.
 */
function testIsolationLevel(
    MySQLClient $readerClient,
    MySQLClient $writerClient,
    TransactionIsolationLevel $level,
    int|float $expectedSecondReadValue
): Rcalicdan\FiberAsync\Contracts\PromiseInterface {
    return async(function () use ($readerClient, $writerClient, $level, $expectedSecondReadValue) {
        echo "=========================================================\n";
        echo 'Testing Isolation Level: '.$level->value."\n";
        echo "=========================================================\n";

        $readerClient->setTransactionIsolationLevel($level);

        // 1. Reader starts a transaction
        $readerTx = await($readerClient->beginTransaction());
        echo "[Reader] Transaction started.\n";

        // 2. Reader performs its FIRST read. The price should be 1000.
        $firstReadResult = await($readerTx->query('SELECT price FROM products WHERE id = 1'));
        $firstPrice = $firstReadResult[0]['price'];
        echo "[Reader] First read: Price is {$firstPrice}.\n";
        assert($firstPrice == 1000, 'First read should be 1000');

        // 3. Writer concurrently updates the price. This is committed instantly.
        echo "[Writer] Updating price to 1200...\n";
        await($writerClient->query('UPDATE products SET price = 1200 WHERE id = 1'));
        echo "[Writer] Price updated and committed.\n";

        // Add a small delay to ensure the server processes the write
        await(delay(0.1));

        // 4. Reader performs its SECOND read inside the SAME transaction.
        $secondReadResult = await($readerTx->query('SELECT price FROM products WHERE id = 1'));
        $secondPrice = $secondReadResult[0]['price'];
        echo "[Reader] Second read: Price is {$secondPrice}.\n";

        // 5. THIS IS THE CRITICAL ASSERTION
        assert(
            $secondPrice == $expectedSecondReadValue,
            "Assertion failed for {$level->value}. Expected {$expectedSecondReadValue}, got {$secondPrice}"
        );
        echo "SUCCESS: The behavior for {$level->value} is correct.\n";

        // 6. Reader commits its transaction
        await($readerTx->commit());
        echo "[Reader] Transaction committed.\n\n";
    })();
}

$start_time = microtime(true);
run(async(function () use ($dbConfig) {
    // We need two independent connections to the database
    $readerClient = new MySQLClient($dbConfig);
    $writerClient = new MySQLClient($dbConfig);

    try {
        // Connect both clients concurrently
        await(all([
            $readerClient->connect(),
            $writerClient->connect(),
        ]));
        echo "Both clients connected successfully.\n";

        // Setup the test table
        await($writerClient->query('DROP TABLE IF EXISTS products'));
        await($writerClient->query('CREATE TABLE products (id INT PRIMARY KEY, name VARCHAR(255), price DECIMAL(10, 2))'));
        await($writerClient->query("INSERT INTO products VALUES (1, 'Laptop', 1000.00)"));

        // --- TEST 1: READ COMMITTED ---
        // This level is "weaker" and allows non-repeatable reads.
        // We expect the second read to see the new, committed price of 1200.
        await(testIsolationLevel($readerClient, $writerClient, TransactionIsolationLevel::ReadCommitted, 1200.00));

        // Reset the price for the next test
        await($writerClient->query('UPDATE products SET price = 1000 WHERE id = 1'));

        // --- TEST 2: REPEATABLE READ ---
        // This is MySQL's default. It is "stronger" and prevents non-repeatable reads.
        // We expect the second read to see the price from its original transaction snapshot: 1000.
        await(testIsolationLevel($readerClient, $writerClient, TransactionIsolationLevel::RepeatableRead, 1000.00));

    } catch (Throwable $e) {
        echo "\nAN ERROR OCCURRED:\n";
        echo "=========================================================\n";
        echo $e->getMessage()."\n";
        echo 'In '.$e->getFile().':'.$e->getLine()."\n";
    } finally {
        // Cleanup
        echo "\nCleaning up...\n";
        await($writerClient->query('DROP TABLE IF EXISTS products'));
        $readerClient->close();
        $writerClient->close();
        echo "Test complete.\n";
    }
}));
$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Total execution time: {$execution_time} seconds\n";
