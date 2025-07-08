<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Database\DB;
use Rcalicdan\FiberAsync\Database\DatabaseFactory;


$loop = AsyncEventLoop::getInstance();

DB::query('SELECT * FROM users WHERE id = ?', [1])
    ->then(function ($result) {
        echo "User found: " . json_encode($result) . "\n";
    })
    ->catch(function ($error) {
        echo "Error: " . $error->getMessage() . "\n";
    });

DB::table('users')
    ->where('active', 1)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get()
    ->then(function ($users) {
        echo "Found " . count($users['rows']) . " users\n";
    });

DB::beginTransaction()
    ->then(function () {
        return DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
    })
    ->then(function ($result) {
        echo "User created with ID: " . $result['insert_id'] . "\n";
        return DB::commit();
    })
    ->catch(function ($error) {
        echo "Transaction failed: " . $error->getMessage() . "\n";
        return DB::rollback();
    });

DB::getClient()->prepare('SELECT * FROM users WHERE age > ? AND city = ?')
    ->then(function ($stmt) {
        return $stmt->execute([25, 'New York']);
    })
    ->then(function ($result) {
        echo "Found users: " . json_encode($result) . "\n";
    });

$loop->run();