<?php

require 'vendor/autoload.php';

// Include your async helpers
require_once __DIR__.'/src/Helpers/async_helper.php';
require_once __DIR__.'/src/Helpers/loop_helper.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;

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
        echo "Successfully connected.\n";

        await($client->query('DROP TABLE IF EXISTS autocommit_test'));
        await($client->query('CREATE TABLE autocommit_test (id INT, message VARCHAR(255))'));
        echo "Table created.\n";

        $initialState = await($client->getAutoCommit());
        echo 'Initial autocommit state: '.($initialState ? 'ON' : 'OFF')."\n";
        echo "--------------------------\n";

        echo "Setting autocommit to OFF...\n";
        await($client->setAutoCommit(false));
        $newState = await($client->getAutoCommit());
        echo 'Current autocommit state: '.($newState ? 'ON' : 'OFF')."\n";

        echo "Executing first INSERT (id = 1)...\n";
        await($client->query("INSERT INTO autocommit_test VALUES (1, 'This should be saved')"));

        echo "Committing transaction...\n";
        await($client->commit());

        $data = await($client->query('SELECT * FROM autocommit_test'));
        echo 'Data after first commit: '.count($data)." row(s)\n";

        echo "Executing second INSERT (id = 2)...\n";
        await($client->query("INSERT INTO autocommit_test VALUES (2, 'This should be rolled back')"));

        echo "Rolling back transaction...\n";
        await($client->rollback());

        echo "--------------------------\n";

    } catch (Throwable $e) {
        echo 'An error occurred: '.$e->getMessage()."\n";
    } finally {
        if ($client) {
            $finalData = await($client->query('SELECT * FROM autocommit_test'));
            echo "Final data in table:\n";
            print_r($finalData);

            // IMPORTANT: It's good practice to restore the autocommit state if the connection
            // is going to be reused (e.g., in a connection pool).
            echo "Restoring autocommit to ON...\n";
            await($client->setAutoCommit(true));

            $client->close();
        }
    }
}));
