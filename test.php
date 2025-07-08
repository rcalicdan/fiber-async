<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Database\DB;

$loop = AsyncEventLoop::getInstance();

// echo "Running: Simple Query with Parameters...\n";
// DB::query('SELECT * FROM users WHERE id = ?', [1])
//     ->then(function ($result) {
//         if (isset($result['rows']) && !empty($result['rows'])) {
//             echo "User found by ID: " . json_encode($result['rows'][0]) . "\n";
//         } elseif (isset($result['rows'])) {
//             echo "User with ID 1 not found.\n";
//         } else {
//             echo "Simple query returned: " . json_encode($result) . "\n";
//         }
//     })
//     ->catch(function ($error) {
//         echo "Error in simple query: " . $error->getMessage() . "\n";
//     });

// echo "Running: Query Builder Query...\n";
// DB::table('users')
//     ->orderBy('created_at', 'desc')
//     ->limit(10)
//     ->get()
//     ->then(function ($users) {
//         if (isset($users['rows'])) {
//             echo "Found " . count($users['rows']) . " users via query builder.\n";
//         }
//     })
//     ->catch(function ($error) {
//         echo "Error in query builder: " . $error->getMessage() . "\n";
//     });

// echo "Running: Transaction...\n";
// $password = password_hash('password123', PASSWORD_BCRYPT);
// DB::beginTransaction()
//     ->then(function () use ($password) {
//         return DB::table('users')->insert([
//             'name' => 'John Doe',
//             'email' => 'john.' . time() . '@example.com',
//             'password' => $password,
//             'created_at' => date('Y-m-d H:i:s'),
//         ]);
//     })
//     ->then(function ($result) {
//         echo "Transaction successful. User created with ID: " . ($result['insert_id'] ?? 'N/A') . "\n";
//         return DB::commit();
//     })
//     ->catch(function ($error) {
//         echo "Transaction failed: " . $error->getMessage() . "\n";
//         return DB::rollback();
//     });

// echo "Running: Prepared Statement...\n";
// $statement = null;
// // Try this instead of the SELECT statement
// DB::getClient()->prepare('SELECT 1 as test_column WHERE ? = ?')
//     ->then(function ($stmt) {
//         return $stmt->execute([1, 1]);
//     })
//     ->then(function ($result) {
//         echo "Simple prepared statement worked: " . json_encode($result) . "\n";
//     })
//     ->catch(function ($error) {
//         echo "Simple prepared statement failed: " . $error->getMessage() . "\n";
//     });

echo "Testing simple prepare statement...\n";
DB::getClient()->prepare('SELECT ? as test_value')
    ->then(function ($stmt) {
        echo "Prepare successful, executing...\n";
        return $stmt->execute([42]);
    })
    ->then(function ($result) {
        echo "Execute successful: " . json_encode($result) . "\n";
    })
    ->catch(function ($error) {
        echo "Error: " . $error->getMessage() . "\n";
    });

$loop->run();
