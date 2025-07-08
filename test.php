<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\PreparedStatement;

echo "--- Starting Asynchronous MySQL Client Test ---\n";

run(function () {
    try {
        await(mysql_query('DROP TABLE IF EXISTS products'));
        await(mysql_query('DROP TABLE IF EXISTS inventory'));
        echo "Cleaned up old test tables.\n";

        await(mysql_query('
            CREATE TABLE products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2) NOT NULL
            )
        '));
        echo "Table 'products' created successfully.\n\n";

        echo "--- 1. Testing Prepared Statement INSERT ---\n";
        $insertStmt = await(mysql_prepare('INSERT INTO products (name, price) VALUES (?, ?)'));

        if (!$insertStmt instanceof PreparedStatement) {
            throw new Exception("Failed to prepare INSERT statement.");
        }

        $result1 = await($insertStmt->execute(['Laptop', 1200.50]));
        echo "Inserted 'Laptop', Last Insert ID: {$result1->lastInsertId}\n";

        $result2 = await($insertStmt->execute(['Keyboard', 75.00]));
        echo "Inserted 'Keyboard', Last Insert ID: {$result2->lastInsertId}\n";

        $result3 = await($insertStmt->execute(['Mouse', 25.99]));
        echo "Inserted 'Mouse', Last Insert ID: {$result3->lastInsertId}\n";

        await($insertStmt->close());
        echo "Insert statement closed.\n\n";

        echo "--- 2. Testing Simple Query SELECT ---\n";
        $allProducts = await(mysql_query('SELECT * FROM products ORDER BY id'));
        echo "All products fetched:\n";
        print_r($allProducts->rows);
        echo "\n";

        echo "--- 3. Testing Prepared Statement SELECT ---\n";
        $selectStmt = await(mysql_prepare('SELECT name, price FROM products WHERE name = ?'));
        $keyboardData = await($selectStmt->execute(['Keyboard']));
        echo "Fetched 'Keyboard' data:\n";
        print_r($keyboardData->rows);
        await($selectStmt->close());
        echo "Select statement closed.\n\n";

        echo "--- 4. Testing Transactions ---\n";
        await(mysql_query('
            CREATE TABLE inventory (
                product_id INT PRIMARY KEY,
                quantity INT NOT NULL
            )
        '));
        await(mysql_query('INSERT INTO inventory (product_id, quantity) VALUES (1, 50), (2, 200)'));
        echo "Initial inventory created.\n";

        $transaction = await(mysql_transaction());
        echo "Transaction started...\n";

        await($transaction->query("UPDATE products SET price = 1150.00 WHERE id = 1"));
        await($transaction->query("UPDATE inventory SET quantity = quantity - 1 WHERE product_id = 1"));
        echo "Updated product price and decremented inventory for product 1.\n";

        await($transaction->commit());
        echo "Transaction committed successfully.\n\n";

        echo "--- 5. Verifying Transaction Results ---\n";
        $finalData = await(mysql_query(
            'SELECT p.name, p.price, i.quantity 
             FROM products p 
             JOIN inventory i ON p.id = i.product_id'
        ));
        print_r($finalData->rows);
    } catch (Throwable $e) {
        echo "\n======================\n";
        echo "  AN ERROR OCCURRED!  \n";
        echo "======================\n";
        echo "Message: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
        echo "Trace: \n" . $e->getTraceAsString() . "\n";
    } finally {
        echo "\n--- Test Complete: Closing connection pool ---\n";
        mysql_close();
    }
});
