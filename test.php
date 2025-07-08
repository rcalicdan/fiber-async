<?php

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\DB;
use Rcalicdan\FiberAsync\AsyncEventLoop;

// Test the QueryBuilder with various operations
function testQueryBuilder()
{
    // Test 1: Select all users
    DB::table('users')
        ->select(['id', 'name', 'email'])
        ->get()
        ->then(function ($result) {
            echo "All users:\n";
            if (isset($result['rows']) && is_array($result['rows'])) {
                foreach ($result['rows'] as $user) {
                    echo "- ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
                }
            } else {
                echo "No users found or invalid result structure\n";
            }
        })
        ->catch(function ($error) {
            echo "Error fetching users: " . $error->getMessage() . "\n";
        });

    // Test 2: Find specific user by ID
    DB::table('users')
        ->find(1)
        ->then(function ($user) {
            if ($user) {
                echo "\nFound user: {$user['name']} ({$user['email']})\n";
            } else {
                echo "\nUser not found\n";
            }
        })
        ->catch(function ($error) {
            echo "Error finding user: " . $error->getMessage() . "\n";
        });

    // Test 3: Insert new user
    DB::table('users')
        ->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT)
        ])
        ->then(function ($result) {
            $insertId = $result['insert_id'] ?? 'unknown';
            echo "\nUser inserted successfully. Insert ID: {$insertId}\n";
        })
        ->catch(function ($error) {
            echo "Error inserting user: " . $error->getMessage() . "\n";
        });

    // Test 4: Update user
    DB::table('users')
        ->where('email', 'john@example.com')
        ->update([
            'name' => 'John Smith'
        ])
        ->then(function ($result) {
            $affectedRows = $result['affected_rows'] ?? 0;
            echo "\nUser updated. Affected rows: {$affectedRows}\n";
        })
        ->catch(function ($error) {
            echo "Error updating user: " . $error->getMessage() . "\n";
        });

    // Test 5: Simple query with conditions (removed JOIN)
    DB::table('users')
        ->select(['id', 'name', 'email'])
        ->where('name', 'like', '%John%')
        ->orderBy('name', 'asc')
        ->limit(10)
        ->get()
        ->then(function ($result) {
            echo "\nUsers with 'John' in name:\n";
            if (isset($result['rows']) && is_array($result['rows'])) {
                foreach ($result['rows'] as $row) {
                    echo "- {$row['name']} ({$row['email']})\n";
                }
            } else {
                echo "No users found or invalid result structure\n";
            }
        })
        ->catch(function ($error) {
            echo "Error in query: " . $error->getMessage() . "\n";
        });

    // Test 6: Count query using raw SQL
    DB::query('SELECT COUNT(*) as total FROM users')
        ->then(function ($result) {
            if (isset($result['rows']) && is_array($result['rows']) && !empty($result['rows'])) {
                $total = $result['rows'][0]['total'] ?? 0;
                echo "\nTotal users: {$total}\n";
            } else {
                echo "\nCould not get user count\n";
            }
        })
        ->catch(function ($error) {
            echo "Error counting users: " . $error->getMessage() . "\n";
        });

    // Test 7: Transaction example (simplified)
    DB::beginTransaction()
        ->then(function () {
            echo "\nStarting transaction...\n";
            return DB::table('users')
                ->insert([
                    'name' => 'Transaction User',
                    'email' => 'transaction@example.com',
                    'password' => password_hash('password123', PASSWORD_DEFAULT)
                ]);
        })
        ->then(function ($result) {
            $insertId = $result['insert_id'] ?? 'unknown';
            echo "Inserted user in transaction, ID: {$insertId}\n";
            return DB::commit();
        })
        ->then(function () {
            echo "Transaction committed successfully\n";
        })
        ->catch(function ($error) {
            echo "Transaction failed: " . $error->getMessage() . "\n";
            return DB::rollback();
        });

    // Test 8: Where conditions
    DB::table('users')
        ->where('email', 'like', '%@example.com')
        ->get()
        ->then(function ($result) {
            $count = (isset($result['rows']) && is_array($result['rows'])) ? count($result['rows']) : 0;
            echo "\nExample.com users count: {$count}\n";
        })
        ->catch(function ($error) {
            echo "Error in filtered query: " . $error->getMessage() . "\n";
        });

    // Test 9: whereIn condition
    DB::table('users')
        ->whereIn('id', [1, 2, 3, 4, 5])
        ->select(['id', 'name'])
        ->get()
        ->then(function ($result) {
            echo "\nUsers with IDs 1-5:\n";
            if (isset($result['rows']) && is_array($result['rows'])) {
                foreach ($result['rows'] as $user) {
                    echo "- ID: {$user['id']}, Name: {$user['name']}\n";
                }
            } else {
                echo "No users found or invalid result structure\n";
            }
        })
        ->catch(function ($error) {
            echo "Error in whereIn query: " . $error->getMessage() . "\n";
        });

    // Test 10: Delete operation
    DB::table('users')
        ->where('email', 'transaction@example.com')
        ->delete()
        ->then(function ($result) {
            $affectedRows = $result['affected_rows'] ?? 0;
            echo "\nDeleted users: {$affectedRows}\n";
        })
        ->catch(function ($error) {
            echo "Error deleting user: " . $error->getMessage() . "\n";
        });

    AsyncEventLoop::getInstance()->run();
}

// Run the tests
echo "Starting QueryBuilder tests...\n";
testQueryBuilder();

echo "\nAll tests completed!\n";