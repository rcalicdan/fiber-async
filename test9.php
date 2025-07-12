<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Result;

$client = new MySQLClient([
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    'debug' => false,
]);
$start_time = microtime(true);
run(async(function () use ($client) {
    try {
        await($client->connect());
        echo "Successfully connected.\n";

        // Setup a test table
        await($client->query('DROP TABLE IF EXISTS fetch_test'));
        await($client->query('CREATE TABLE fetch_test (id INT, message VARCHAR(255))'));
        await($client->query("INSERT INTO fetch_test VALUES (1, 'Hello'), (2, 'World'), (3, 'Test')"));
        echo "Test table created and populated.\n";

        // // This query now returns a Promise that resolves to your new Result object
        // /** @var Result $result */
        $result = await($client->query('SELECT * FROM fetch_test'));

        echo "\nResult object received. It contains ".$result->getRowCount()." rows.\n";

        // =================================================================
        // METHOD 1: Using `foreach` (Proves backward compatibility)
        // =================================================================
        echo "\n--- 1. Testing with `foreach` loop ---\n";
        // Because your Result class implements IteratorAggregate, this works perfectly.
        foreach ($result as $row) {
            echo "ID: {$row['id']}, Message: {$row['message']}\n";
        }

        // =================================================================
        // METHOD 2: Using `fetchAllAssoc()`
        // =================================================================
        echo "\n--- 2. Testing with `fetchAllAssoc()` ---\n";
        // This is useful when you want all rows as a simple array immediately.
        $allRows = $result->fetchAllAssoc();
        echo 'Fetched '.count($allRows)." rows at once.\n";
        echo 'The last row is: ';
        print_r(end($allRows)); // Print the last row to show we have the data

        // =================================================================
        // METHOD 3: Using `fetchAssoc()` in a `while` loop
        // =================================================================
        echo "\n--- 3. Testing with `fetchAssoc()` row-by-row ---\n";
        // This pattern is great for processing one row at a time to save memory.

        // We get a fresh result object to demonstrate iterating from the beginning.
        /** @var Result $result2 */
        $result2 = await($client->query('SELECT * FROM fetch_test ORDER BY id DESC'));

        while ($row = $result2->fetchAssoc()) {
            echo "Fetched row -> ID: {$row['id']}, Message: {$row['message']}\n";
        }
        echo "fetchAssoc() returned null, loop has finished correctly.\n";

    } catch (Throwable $e) {
        echo "\nAN ERROR OCCURRED: ".$e->getMessage()."\n";
        echo 'In '.$e->getFile().' on line '.$e->getLine()."\n";
    } finally {
        if ($client) {
            echo "\nCleaning up...\n";
            await($client->query('DROP TABLE IF EXISTS fetch_test'));
            $client->close();
        }
    }
}));

$microtime = microtime(true) - $start_time;
echo "Time taken: {$microtime} seconds\n";
