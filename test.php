<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Database\DB;

$loop = AsyncEventLoop::getInstance();

echo "Running: Simple Query with Parameters...\n";
DB::query('SELECT * FROM users WHERE id = ?', [1])
    ->then(function ($result) {
        if (isset($result['rows']) && !empty($result['rows'])) {
            echo "User found by ID: " . json_encode($result['rows'][0]) . "\n";
        } elseif (isset($result['rows'])) {
            echo "User with ID 1 not found.\n";
        } else {
            echo "Simple query returned: " . json_encode($result) . "\n";
        }
    })
    ->catch(function ($error) {
        echo "Error in simple query: " . $error->getMessage() . "\n";
    });

echo "Running: Query Builder Query...\n";
DB::table('users')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get()
    ->then(function ($users) {
        if (isset($users['rows'])) {
            echo "Found " . count($users['rows']) . " users via query builder.\n";
        }
    })
    ->catch(function ($error) {
        echo "Error in query builder: " . $error->getMessage() . "\n";
    });

echo "Running: Transaction...\n";
$password = password_hash('password123', PASSWORD_BCRYPT);
DB::beginTransaction()
    ->then(function () use ($password) {
        return DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john.' . time() . '@example.com',
            'password' => $password,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    })
    ->then(function ($result) {
        echo "Transaction successful. User created with ID: " . ($result['insert_id'] ?? 'N/A') . "\n";
        return DB::commit();
    })
    ->catch(function ($error) {
        echo "Transaction failed: " . $error->getMessage() . "\n";
        return DB::rollback();
    });

echo "Running: Prepared Statement...\n";
$statement = null;
// Try this instead of the SELECT statement
DB::getClient()->prepare('SELECT 1 as test_column WHERE ? = ?')
    ->then(function ($stmt) {
        return $stmt->execute([1, 1]);
    })
    ->then(function ($result) {
        echo "Simple prepared statement worked: " . json_encode($result) . "\n";
    })
    ->catch(function ($error) {
        echo "Simple prepared statement failed: " . $error->getMessage() . "\n";
    });

echo "Testing with users table...\n";

// Test 1: Select all users
echo "\n1. Testing SELECT all users:\n";
DB::getClient()->prepare('SELECT name, email FROM users')
    ->then(function ($stmt) {
        echo "Prepare successful, executing...\n";
        return $stmt->execute();
    })
    ->then(function ($result) {
        echo "Raw result type: " . gettype($result) . "\n";
        echo "Raw result: " . json_encode($result) . "\n";
        
        if (is_array($result) && !empty($result)) {
            echo "Found " . count($result) . " users:\n";
            foreach ($result as $user) {
                if (is_array($user) && isset($user['name'], $user['email'])) {
                    echo "- {$user['name']} ({$user['email']})\n";
                } else {
                    echo "- Invalid user data: " . json_encode($user) . "\n";
                }
            }
        } else {
            echo "No users found or unexpected result format\n";
        }
    })
    ->catch(function ($error) {
        echo "Error: " . $error->getMessage() . "\n";
    });

// Test 2: Select user by email
echo "\n2. Testing SELECT with WHERE clause:\n";
DB::getClient()->prepare('SELECT name, email FROM users WHERE email = ?')
    ->then(function ($stmt) {
        echo "Prepare successful, executing with parameter...\n";
        return $stmt->execute(['user@example.com']); // Replace with actual email
    })
    ->then(function ($result) {
        echo "Raw result: " . json_encode($result) . "\n";
        
        if (is_array($result) && !empty($result) && isset($result[0])) {
            echo "Found user: {$result[0]['name']} ({$result[0]['email']})\n";
        } else {
            echo "No user found with that email\n";
        }
    })
    ->catch(function ($error) {
        echo "Error: " . $error->getMessage() . "\n";
    });

// Test 3: Insert new user
echo "\n3. Testing INSERT:\n";
DB::getClient()->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)')
    ->then(function ($stmt) {
        echo "Prepare successful, inserting test user...\n";
        return $stmt->execute([
            'Test User',
            'test@example.com',
            password_hash('test123', PASSWORD_DEFAULT)
        ]);
    })
    ->then(function ($result) {
        echo "Insert result type: " . gettype($result) . "\n";
        echo "Insert result: " . json_encode($result) . "\n";
        
        // Handle different possible result formats
        if (is_array($result)) {
            echo "Insert successful (array result)\n";
        } elseif (is_object($result)) {
            echo "Insert successful (object result)\n";
            if (method_exists($result, 'affectedRows')) {
                echo "Affected rows: " . $result->affectedRows() . "\n";
            }
            if (method_exists($result, 'insertId')) {
                echo "Last insert ID: " . $result->insertId() . "\n";
            }
        } else {
            echo "Insert successful (result: $result)\n";
        }
    })
    ->catch(function ($error) {
        echo "Insert error: " . $error->getMessage() . "\n";
    });

// Test 4: Update user
echo "\n4. Testing UPDATE:\n";
DB::getClient()->prepare('UPDATE users SET name = ? WHERE email = ?')
    ->then(function ($stmt) {
        echo "Prepare successful, updating user...\n";
        return $stmt->execute(['Updated Test User', 'test@example.com']);
    })
    ->then(function ($result) {
        echo "Update result type: " . gettype($result) . "\n";
        echo "Update result: " . json_encode($result) . "\n";
        echo "Update operation completed\n";
    })
    ->catch(function ($error) {
        echo "Update error: " . $error->getMessage() . "\n";
    });

// Test 5: Count users
echo "\n5. Testing COUNT:\n";
DB::getClient()->prepare('SELECT COUNT(*) as user_count FROM users')
    ->then(function ($stmt) {
        return $stmt->execute();
    })
    ->then(function ($result) {
        echo "Count result: " . json_encode($result) . "\n";
        
        if (is_array($result) && !empty($result) && isset($result[0]['user_count'])) {
            echo "Total users in table: " . $result[0]['user_count'] . "\n";
        } else {
            echo "Could not determine user count\n";
        }
    })
    ->catch(function ($error) {
        echo "Count error: " . $error->getMessage() . "\n";
    });

// Test 6: Delete test user (cleanup)
echo "\n6. Testing DELETE (cleanup):\n";
DB::getClient()->prepare('DELETE FROM users WHERE email = ?')
    ->then(function ($stmt) {
        echo "Cleaning up test user...\n";
        return $stmt->execute(['test@example.com']);
    })
    ->then(function ($result) {
        echo "Delete result type: " . gettype($result) . "\n";
        echo "Delete result: " . json_encode($result) . "\n";
        echo "Delete operation completed\n";
    })
    ->catch(function ($error) {
        echo "Delete error: " . $error->getMessage() . "\n";
    });

$loop->run();
