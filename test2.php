<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;

$client = new MySQLClient([
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    'debug' => false,
]);

run(async(function () use ($client) {
    try {
        await($client->connect());
        echo "Successfully connected.\n";

        await($client->query('CREATE TABLE IF NOT EXISTS accounts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, balance DECIMAL(10, 2) NOT NULL)'));
        await($client->query('TRUNCATE TABLE accounts'));
        await($client->query("INSERT INTO accounts (name, balance) VALUES ('Alice', 1000.00), ('Bob', 1000.00)"));

        $transaction = await($client->beginTransaction());

        $result = await($transaction->execute(async(function ($tx) {
            echo "Executing transaction logic...\n";

            await($tx->query("UPDATE accounts SET balance = balance - 100 WHERE name = 'Alice'"));
            echo "Deducted 100 from Alice.\n";

            await($tx->query("UPDATE accounts SET balance = balance + 100 WHERE name = 'Bob'"));
            echo "Added 100 to Bob.\n";

            throw new Exception('Simulated error in transaction');

            return 'Transfer completed.';
        })));

        echo "Transaction result: $result\n";
    } catch (Throwable $e) {
        echo 'A top-level error occurred: '.$e->getMessage()."\n";
    } finally {
        echo "Closing connection.\n";
        await($client->close());
    }

    await($client->connect());
    $finalState = await($client->query('SELECT * FROM accounts'));
    echo "Final account balances:\n";
    print_r($finalState);
    await($client->close());
}));
