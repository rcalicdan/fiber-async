<?php

require 'vendor/autoload.php';

// Include your async helpers and the Enum
require_once __DIR__.'/src/Helpers/async_helper.php';
require_once __DIR__.'/src/Helpers/loop_helper.php';
require_once __DIR__.'/src/Database/TransactionIsolationLevel.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\TransactionIsolationLevel;

$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    'debug' => false,
];

run(async(function () use ($dbConfig) {
    // We need two independent connections to the database
    $clientA = new MySQLClient($dbConfig);
    $clientB = new MySQLClient($dbConfig);

    try {
        await(all([$clientA->connect(), $clientB->connect()]));
        echo "Both clients connected successfully.\n";

        // Setup the test table and initial data
        await($clientB->query('DROP TABLE IF EXISTS products'));
        await($clientB->query('CREATE TABLE products (id INT PRIMARY KEY, price DECIMAL(10, 2))'));

        // ===================================================================
        // TEST 1: Proving that a DIRECT parameter OVERRIDES a strict default.
        // ===================================================================
        echo "\n--- TEST 1: Overriding REPEATABLE READ with a direct READ COMMITTED ---\n";
        await($clientB->query('INSERT INTO products VALUES (1, 100.00)'));

        // Set the default on Client A to be strict
        $clientA->setTransactionIsolationLevel(TransactionIsolationLevel::RepeatableRead);
        echo "[Setup] Default isolation level set to REPEATABLE READ.\n";

        // Start a transaction but explicitly pass the WEAKER level.
        $tx1 = await($clientA->beginTransaction(TransactionIsolationLevel::ReadCommitted));
        echo "[Client A] Transaction started with direct READ COMMITTED level.\n";

        $firstRead = await($tx1->query('SELECT price FROM products WHERE id = 1'));
        assert($firstRead[0]['price'] == 100.00, 'Test 1, Read 1 failed.');
        echo "[Client A] First read: Price is 100.00.\n";

        // Client B makes a change that gets committed instantly.
        await($clientB->query('UPDATE products SET price = 200.00 WHERE id = 1'));
        echo "[Client B] Updated price to 200.00 and committed.\n";

        // Client A reads again. Because the transaction is running as READ COMMITTED, it should see the new price.
        $secondRead = await($tx1->query('SELECT price FROM products WHERE id = 1'));
        echo '[Client A] Second read: Price is '.$secondRead[0]['price'].".\n";

        assert($secondRead[0]['price'] == 200.00, 'Test 1 FAILED: Direct override was not respected.');
        echo "SUCCESS: Direct parameter correctly overrode the default.\n";
        await($tx1->commit());
        await($clientB->query('TRUNCATE TABLE products')); // Clean up for next test

        // ===================================================================
        // TEST 2: Proving that a DIRECT parameter OVERRIDES a weak default.
        // ===================================================================
        echo "\n--- TEST 2: Overriding READ COMMITTED with a direct REPEATABLE READ ---\n";
        await($clientB->query('INSERT INTO products VALUES (1, 100.00)'));

        // Set the default on Client A to be weak
        $clientA->setTransactionIsolationLevel(TransactionIsolationLevel::ReadCommitted);
        echo "[Setup] Default isolation level set to READ COMMITTED.\n";

        // Start a transaction but explicitly pass the STRONGER level.
        $tx2 = await($clientA->beginTransaction(TransactionIsolationLevel::RepeatableRead));
        echo "[Client A] Transaction started with direct REPEATABLE READ level.\n";

        $firstRead = await($tx2->query('SELECT price FROM products WHERE id = 1'));
        assert($firstRead[0]['price'] == 100.00, 'Test 2, Read 1 failed.');
        echo "[Client A] First read: Price is 100.00.\n";

        // Client B makes a change.
        await($clientB->query('UPDATE products SET price = 200.00 WHERE id = 1'));
        echo "[Client B] Updated price to 200.00 and committed.\n";

        // Client A reads again. Because the transaction is running as REPEATABLE READ, it should NOT see the new price.
        $secondRead = await($tx2->query('SELECT price FROM products WHERE id = 1'));
        echo '[Client A] Second read: Price is '.$secondRead[0]['price'].".\n";

        assert($secondRead[0]['price'] == 100.00, 'Test 2 FAILED: Direct override was not respected.');
        echo "SUCCESS: Direct parameter correctly overrode the default.\n";
        await($tx2->commit());
        await($clientB->query('TRUNCATE TABLE products'));

        // ===================================================================
        // TEST 3: Proving backward compatibility (no parameter uses default).
        // ===================================================================
        echo "\n--- TEST 3: Verifying backward compatibility ---\n";
        await($clientB->query('INSERT INTO products VALUES (1, 100.00)'));

        // Set the default on Client A to be strict
        $clientA->setTransactionIsolationLevel(TransactionIsolationLevel::RepeatableRead);
        echo "[Setup] Default isolation level set to REPEATABLE READ.\n";

        // Start a transaction with NO parameter. It should use the default.
        $tx3 = await($clientA->beginTransaction());
        echo "[Client A] Transaction started with no direct parameter.\n";

        $firstRead = await($tx3->query('SELECT price FROM products WHERE id = 1'));
        echo "[Client A] First read: Price is 100.00.\n";

        await($clientB->query('UPDATE products SET price = 200.00 WHERE id = 1'));
        echo "[Client B] Updated price to 200.00 and committed.\n";

        // Client A reads again. It should be using the default REPEATABLE READ level.
        $secondRead = await($tx3->query('SELECT price FROM products WHERE id = 1'));
        echo '[Client A] Second read: Price is '.$secondRead[0]['price'].".\n";

        assert($secondRead[0]['price'] == 100.00, 'Test 3 FAILED: Fallback to default isolation level did not work.');
        echo "SUCCESS: Backward compatibility is maintained.\n";
        await($tx3->commit());

    } catch (Throwable $e) {
        echo "\nAN ERROR OCCURRED:\n".$e->getMessage()."\n";
    } finally {
        // Cleanup
        echo "\nCleaning up...\n";
        await($clientB->query('DROP TABLE IF EXISTS products'));
        $clientA->close();
        $clientB->close();
        echo "Test complete.\n";
    }
}));
