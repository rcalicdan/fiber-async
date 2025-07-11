<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Transaction;

$client = new MySQLClient([
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    'debug' => false,
]);

run(function () use ($client) {

    /** @var ?Transaction $transaction */
    $transaction = null;

    try {
        await($client->connect());
        echo "Successfully connected to the database.\n";

        await($client->query("
            CREATE TABLE IF NOT EXISTS accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL
            )
        "));
        await($client->query("TRUNCATE TABLE accounts"));
        await($client->query("INSERT INTO accounts (name, balance) VALUES ('Alice', 1000.00), ('Bob', 1000.00)"));
        echo "Starting transaction...\n";
        $transaction = await($client->beginTransaction());

        echo "Deducting 100 from Alice...\n";
        await($transaction->query("UPDATE accounts SET balance = balance - 200 WHERE name = 'Alice'"));

        echo "Adding 100 to Bob...\n";
        await($transaction->query("UPDATE accounts SET balance = balance + 200 WHERE name = 'Bob'"));

        // You can even uncomment this to test the rollback
        throw new \Exception("Something went wrong!");

        // ** COMMIT THE TRANSACTION **
        echo "Committing transaction...\n";
        await($transaction->commit());

        echo "Transaction committed successfully!\n";
    } catch (\Throwable $e) {
        echo "An error occurred: " . $e->getMessage() . "\n";
        if ($transaction && $transaction->isActive()) {
            echo "Rolling back transaction...\n";
            await($transaction->rollback());
            echo "Transaction rolled back.\n";
        }
    } finally {
        echo "Closing connection.\n";
        await($client->close());
    }

    // You can reconnect to verify the final state
    await($client->connect());
    $finalState = await($client->query("SELECT * FROM accounts"));
    echo "Final account balances:\n";
    print_r($finalState);
    await($client->close());
});
