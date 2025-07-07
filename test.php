<?php

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Database\AsyncDB;

require 'vendor/autoload.php';

AsyncDB::table('posts')
    ->where('status', '=', 'published')
    ->orderBy('created_at', 'DESC')
    ->get()
    ->then(function ($posts) {
        foreach ($posts as $post) {
            echo "Post: {$post['title']}\n";
        }
    })
    ->catch(function ($error) {
        echo "Error fetching posts: " . $error->getMessage() . "\n";
    });

AsyncEventLoop::getInstance()->run();
