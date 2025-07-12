<?php

require 'vendor/autoload.php';

require_once __DIR__.'/src/Helpers/async_helper.php';
require_once __DIR__.'/src/Helpers/loop_helper.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\FiberAsync\Database\Transaction;

$client = new MySQLClient([
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
]);

run(async(function () use ($client) {
    try {
        await($client->connect());

        await($client->query('CREATE TABLE IF NOT EXISTS accounts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, balance DECIMAL(10, 2) NOT NULL)'));
        await($client->query('TRUNCATE TABLE accounts'));
        await($client->query("INSERT INTO accounts (name, balance) VALUES ('Alice', 1000.00), ('Bob', 500.00)"));

        echo "Initial Balances:\n";
        print_r(await($client->query('SELECT * FROM accounts')));
        echo "--------------------------\n";

        $transaction = await($client->beginTransaction());

        // We will now attempt a transfer of 2000, which should fail.
        await($transaction->execute(async(function (Transaction $tx) {
            $fromAccount = 'Alice';
            $toAccount = 'Bob';
            $transferAmount = 2000.00; // This amount should fail

            echo "Attempting to transfer {$transferAmount} from {$fromAccount} to {$toAccount}...\n";

            // Step 1: ATOMICALLY deduct from the source account.
            // The WHERE clause is the key: it ensures we only update if funds are sufficient.
            $sqlDeduct = 'UPDATE accounts SET balance = balance - ? WHERE name = ? AND balance >= ?';
            $deductStmt = await($tx->prepare($sqlDeduct));

            /** @var OkPacket $deductResult */
            $deductResult = await($deductStmt->execute([$transferAmount, $fromAccount, $transferAmount]));
            await($deductStmt->close());

            // Step 2: Check if the deduction was successful.
            // If `affectedRows` is 0, it means the WHERE condition failed.
            if ($deductResult->affectedRows !== 1) {
                // This is the correct way to check for insufficient funds atomically.
                throw new RuntimeException("Insufficient funds for '{$fromAccount}'. Transfer cancelled.");
            }
            echo "Successfully deducted from {$fromAccount}.\n"; // This line won't be reached

            // Step 3: ATOMICALLY add to the destination account.
            $sqlAdd = 'UPDATE accounts SET balance = balance + ? WHERE name = ?';
            $addStmt = await($tx->prepare($sqlAdd));
            /** @var OkPacket $addResult */
            $addResult = await($addStmt->execute([$transferAmount, $toAccount]));
            await($addStmt->close());

            if ($addResult->affectedRows !== 1) {
                // This would happen if the 'to' account doesn't exist.
                throw new RuntimeException("User '{$toAccount}' not found. Transfer cancelled.");
            }
            echo "Successfully added to {$toAccount}.\n";
        })));

        echo "Transaction committed successfully!\n";

    } catch (Throwable $e) {
        // Now, the error from our insufficient funds check will be caught here.
        echo 'TRANSACTION FAILED: '.$e->getMessage()."\n";
    } finally {
        echo "--------------------------\n";
        echo "Final Balances (should be unchanged):\n";
        print_r(await($client->query('SELECT * FROM accounts')));

        await($client->close());
    }
}));
