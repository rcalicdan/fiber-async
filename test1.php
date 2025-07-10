<?php

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;

// The `run` function starts the event loop and executes our async code.
run(function () {
    // Your database connection parameters
    $connectionParams = [
        'host'     => '127.0.0.1',
        'port'     => 3309,
        'user'     => 'root',
        'password' => 'Reymart1234',
        'database' => 'yo',
        'debug'    => false, // Set to true for verbose logging
    ];

    $client = new MySQLClient($connectionParams);

    try {
        // 1. Connect to the database
        echo "Connecting...\n";
        await($client->connect());
        echo "Connection successful.\n\n";

        // 2. Define the SQL to fetch all users
        // It's good practice to list columns instead of using SELECT *
        $sql = 'SELECT id, name, email FROM users';
        echo "Executing query: {$sql}\n";
        
        // 3. Execute the query using the simpler `query()` method
        // This is ideal for queries without parameters.
        $users = await($client->query($sql));
        
        // 4. Check if any users were found and display them
        if (empty($users)) {
            echo "\nNo users found in the 'users' table.\n";
        } else {
            echo "\nFound " . count($users) . " user(s):\n";
            echo "----------------------------------------\n";
            
            // Loop through the results and echo each one
            foreach ($users as $user) {
                // Use sprintf for clean, formatted output
                echo sprintf(
                    "ID: %-3d | Name: %-20s | Email: %s\n",
                    $user['id'],
                    $user['name'],
                    $user['email']
                );
            }
            
            echo "----------------------------------------\n";
        }

    } catch (Throwable $e) {
        // Catch and display any errors that occur
        echo "\n[ERROR] An error occurred: " . $e->getMessage() . "\n";
    } finally {
        // 5. Always ensure the connection is closed
        if ($client->getSocket() && !$client->getSocket()->isClosed()) {
            echo "\nClosing database connection...\n";
            await($client->close());
        }
        echo "Done.\n";
    }
});